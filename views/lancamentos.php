<?php
// views/lancamentos.php - Gestão de Lançamentos de Lavanderia

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('lancamentos_ver');
$pdo = getDB();

// Processamento de Ações: Lançar em Lote e Mover para a Lixeira
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_lote') {
        $editarId = (int)($_POST['editar_id'] ?? 0);
        exigirPermissao($editarId > 0 ? 'lancamentos_editar' : 'lancamentos_criar');

        $data = $_POST['data_lancamento'] ?? date('Y-m-d');
        $departamentoId = (int)($_POST['departamento_id'] ?? 0);
        $observacoesGerais = trim($_POST['observacoes_gerais'] ?? '');
        $itensPost = $_POST['itens'] ?? [];

        if ($departamentoId <= 0) {
            definirAlerta('danger', 'Selecione um departamento válido para o lote.');
            header('Location: lancamentos.php');
            exit;
        }

        if (empty($itensPost) || !is_array($itensPost)) {
            definirAlerta('danger', 'Adicione pelo menos um item ao lote de lançamentos.');
            header('Location: lancamentos.php');
            exit;
        }

        // Validação e filtragem dos itens válidos
        $itensValidos = [];
        foreach ($itensPost as $it) {
            $prodId = (int)($it['produto_id'] ?? 0);
            $qtd = (int)($it['quantidade'] ?? 0);
            $valUnit = (float)str_replace(',', '.', $it['valor_unitario'] ?? 0);
            $obsItem = trim($it['observacoes'] ?? '');

            if ($prodId > 0 && $qtd > 0 && $valUnit > 0) {
                $itensValidos[] = [
                    'produto_id' => $prodId,
                    'quantidade' => $qtd,
                    'valor_unitario' => $valUnit,
                    'observacoes' => !empty($obsItem) ? $obsItem : $observacoesGerais
                ];
            }
        }

        if (empty($itensValidos)) {
            definirAlerta('danger', 'Nenhum item com peça, quantidade e valor válido foi informado no lote.');
            header('Location: lancamentos.php');
            exit;
        }

        if ($editarId > 0) {
            if (count($itensPost) !== 1 || count($itensValidos) !== 1) {
                definirAlerta('danger', 'A edição deve conter exatamente uma peça.');
                header('Location: lancamentos.php');
                exit;
            }

            $item = $itensValidos[0];
            $valorTotal = $item['quantidade'] * $item['valor_unitario'];
            $stmt = $pdo->prepare("
                UPDATE lancamentos
                SET data_lancamento = ?, departamento_id = ?, produto_id = ?, quantidade = ?,
                    valor_unitario = ?, valor_total = ?, observacoes = ?,
                    departamento_nome_snapshot = (SELECT nome FROM departamentos WHERE id = ?),
                    produto_nome_snapshot = (SELECT nome FROM produtos WHERE id = ?)
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $data, $departamentoId, $item['produto_id'], $item['quantidade'],
                $item['valor_unitario'], $valorTotal, $item['observacoes'],
                $departamentoId, $item['produto_id'], $editarId
            ]);

            if ($stmt->rowCount() === 0) {
                $stmtExistencia = $pdo->prepare("SELECT id FROM lancamentos WHERE id = ? AND deleted_at IS NULL");
                $stmtExistencia->execute([$editarId]);
                if (!$stmtExistencia->fetchColumn()) {
                    definirAlerta('danger', 'O lançamento não foi encontrado ou já foi removido.');
                    header('Location: lancamentos.php');
                    exit;
                }
            }

            definirAlerta('success', 'Lançamento atualizado com sucesso!');
            header('Location: lancamentos.php');
            exit;
        }

        // Inserção atômica em lote com transação PDO
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO lancamentos (
                    data_lancamento, departamento_id, produto_id, quantidade, valor_unitario, valor_total, 
                    observacoes, usuario_id, departamento_nome_snapshot, produto_nome_snapshot
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 
                    (SELECT nome FROM departamentos WHERE id = ?),
                    (SELECT nome FROM produtos WHERE id = ?)
                )
            ");

            $totalPecasLote = 0;
            $totalValorLote = 0;

            foreach ($itensValidos as $it) {
                $valorTotal = $it['quantidade'] * $it['valor_unitario'];
                $totalPecasLote += $it['quantidade'];
                $totalValorLote += $valorTotal;

                $stmt->execute([
                    $data, $departamentoId, $it['produto_id'], $it['quantidade'], 
                    $it['valor_unitario'], $valorTotal, $it['observacoes'], 
                    $currentUser['id'], $departamentoId, $it['produto_id']
                ]);
            }

            $pdo->commit();

            // Busca nome do departamento para mensagem de sucesso
            $stmtDepNome = $pdo->prepare("SELECT nome FROM departamentos WHERE id = ?");
            $stmtDepNome->execute([$departamentoId]);
            $nomeDep = $stmtDepNome->fetchColumn() ?: 'Departamento';

            $totalItens = count($itensValidos);
            $valorFormatado = number_format($totalValorLote, 2, ',', '.');
            definirAlerta('success', "Lote com {$totalItens} item(ns) ({$totalPecasLote} peças - Total: R$ {$valorFormatado}) registrado com sucesso para o departamento \"{$nomeDep}\"!");
        } catch (Exception $e) {
            $pdo->rollBack();
            definirAlerta('danger', 'Erro ao registrar lote de lançamentos.');
        }

        header('Location: lancamentos.php');
        exit;
    } elseif ($acao === 'deletar') {
        exigirPermissao('lancamentos_excluir');
        // Envia para a Lixeira (Soft Delete)
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE lancamentos SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);
            definirAlerta('warning', 'Lançamento movido para a Lixeira. Ele ficará guardado por 60 dias.');
        }
        header('Location: lancamentos.php');
        exit;
    }
}

// ── Filtros aplicados automaticamente ───────────────────────────────────────
$pageTitle  = 'Lançamentos';
$filtroMes   = is_string($_GET['mes'] ?? null) ? trim($_GET['mes']) : '';
$filtroData  = is_string($_GET['data'] ?? null) ? trim($_GET['data']) : '';
$periodoInformado = array_key_exists('data', $_GET) || array_key_exists('mes', $_GET);
$filtroDep   = is_string($_GET['departamento_id'] ?? null) ? $_GET['departamento_id'] : '';
$filtroBusca = is_string($_GET['busca'] ?? null) ? trim($_GET['busca']) : '';

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filtroMes)) {
    $filtroMes = '';
}
$dataObj = DateTime::createFromFormat('Y-m-d', $filtroData);
if (!$dataObj || $dataObj->format('Y-m-d') !== $filtroData) {
    $filtroData = '';
}
if (!$periodoInformado) {
    $filtroData = date('Y-m-d');
}
$filtroDep = ctype_digit((string)$filtroDep) ? (string)(int)$filtroDep : '';
if (mb_strlen($filtroBusca) > 100) {
    $filtroBusca = mb_substr($filtroBusca, 0, 100);
}

// Filtros base compartilhados pela listagem e pelo calendário
$whereBase  = "l.deleted_at IS NULL";
$paramsBase = [];

if ($filtroDep !== '') {
    $whereBase .= " AND l.departamento_id = ?";
    $paramsBase[] = (int)$filtroDep;
}
if ($filtroBusca !== '') {
    $whereBase .= " AND (COALESCE(d.nome, l.departamento_nome_snapshot) LIKE ? OR COALESCE(p.nome, l.produto_nome_snapshot) LIKE ?)";
    $paramsBase[] = "%$filtroBusca%";
    $paramsBase[] = "%$filtroBusca%";
}

// Dia específico tem prioridade sobre o mês
$sqlWhere = "WHERE $whereBase";
$params = $paramsBase;
if ($filtroData !== '') {
    $sqlWhere .= " AND l.data_lancamento = ?";
    $params[] = $filtroData;
} elseif ($filtroMes !== '') {
    $sqlWhere .= " AND DATE_FORMAT(l.data_lancamento, '%Y-%m') = ?";
    $params[] = $filtroMes;
}

// ── Calendário: dias com movimento ─────────────────────────────────────────
$carregarDiasCalendario = function (string $mes) use ($pdo, $whereBase, $paramsBase): array {
    $stmt = $pdo->prepare("
        SELECT l.data_lancamento,
               COUNT(*) AS qtd_lancamentos,
               COALESCE(SUM(l.quantidade), 0) AS qtd_pecas,
               COALESCE(SUM(l.valor_total), 0) AS valor_total
        FROM lancamentos l
        LEFT JOIN departamentos d ON d.id = l.departamento_id
        LEFT JOIN produtos p ON p.id = l.produto_id
        WHERE $whereBase AND DATE_FORMAT(l.data_lancamento, '%Y-%m') = ?
        GROUP BY l.data_lancamento
    ");
    $stmt->execute(array_merge($paramsBase, [$mes]));

    $dias = [];
    foreach ($stmt->fetchAll() as $dia) {
        $dias[$dia['data_lancamento']] = [
            'qtd'   => (int)$dia['qtd_lancamentos'],
            'pecas' => (int)$dia['qtd_pecas'],
            'valor' => (float)$dia['valor_total'],
        ];
    }
    return $dias;
};

$mesCalendario = $filtroData !== '' ? substr($filtroData, 0, 7) : $filtroMes;
if ($mesCalendario === '') {
    $stmtUltimoMes = $pdo->query("
        SELECT DATE_FORMAT(MAX(data_lancamento), '%Y-%m')
        FROM lancamentos
        WHERE deleted_at IS NULL
    ");
    $mesCalendario = $stmtUltimoMes->fetchColumn() ?: date('Y-m');
}

// Endpoint interno usado ao navegar entre os meses do calendário
if (($_GET['ajax'] ?? '') === 'dias') {
    $mesAjax = is_string($_GET['cal_mes'] ?? null) ? trim($_GET['cal_mes']) : '';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesAjax)) {
        $mesAjax = $mesCalendario;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'mes'  => $mesAjax,
        'dias' => $carregarDiasCalendario($mesAjax),
    ]);
    exit;
}

$diasComMovimento = $carregarDiasCalendario($mesCalendario);

$mesesCalendarioPt = [
    '01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril',
    '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto',
    '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro'
];
$partesMesCalendario = explode('-', $mesCalendario);
$nomeMesCalendario = $mesesCalendarioPt[$partesMesCalendario[1] ?? '01'] ?? '';
$labelMesCalendario = ucfirst($nomeMesCalendario) . ' de ' . ($partesMesCalendario[0] ?? '');

$periodoSelecionadoLabel = 'Todo o período';
$periodoSelecionadoHint  = 'Sem filtro de data';
if ($filtroData !== '') {
    $periodoSelecionadoLabel = date('d/m/Y', strtotime($filtroData));
    $periodoSelecionadoHint  = 'Dia selecionado';
} elseif ($filtroMes !== '') {
    $periodoSelecionadoLabel = $labelMesCalendario;
    $periodoSelecionadoHint  = 'Mês inteiro';
}


// KPIs calculados sobre todos os lançamentos do filtro
$stmtKpi = $pdo->prepare("
    SELECT COUNT(l.id) AS total_itens,
           COALESCE(SUM(l.quantidade), 0) AS total_pecas,
           COALESCE(SUM(l.valor_total), 0) AS valor_total_geral
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    LEFT JOIN produtos p ON p.id = l.produto_id
    $sqlWhere
");
$stmtKpi->execute($params);
$kpiData = $stmtKpi->fetch();
$totalItens = (int)($kpiData['total_itens'] ?? 0);
$totalPecas = (int)($kpiData['total_pecas'] ?? 0);
$valorTotalGeral = (float)($kpiData['valor_total_geral'] ?? 0.0);

// Paginação
$itensPorPagina = 50;
$totalPaginas = max(1, (int)ceil($totalItens / $itensPorPagina));
$paginaAtualNum = max(1, min($totalPaginas, (int)($_GET['pagina'] ?? 1)));
$offset = ($paginaAtualNum - 1) * $itensPorPagina;

// Consulta de Lançamentos paginada com proteção de snapshot
$stmt = $pdo->prepare("
    SELECT l.*, 
           COALESCE(d.nome, l.departamento_nome_snapshot, 'Setor Removido') AS departamento_nome, 
           COALESCE(p.nome, l.produto_nome_snapshot, 'Peça Removida') AS produto_nome, 
           u.nome AS usuario_nome
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    LEFT JOIN produtos p ON p.id = l.produto_id
    LEFT JOIN usuarios u ON u.id = l.usuario_id
    $sqlWhere
    ORDER BY l.data_lancamento DESC, l.id DESC
    LIMIT $itensPorPagina OFFSET $offset
");
$stmt->execute($params);
$lancamentos = $stmt->fetchAll();

// Listas para os selects (apenas não deletados)
$departamentos = $pdo->query("SELECT * FROM departamentos WHERE deleted_at IS NULL ORDER BY nome ASC")->fetchAll();
$produtos = $pdo->query("SELECT * FROM produtos WHERE deleted_at IS NULL ORDER BY nome ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ==========================================================================
   FILTROS E CALENDÁRIO — Página de Lançamentos
   ========================================================================== */
.main-content { min-width: 0; }

.lanc-filter-card {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    position: relative;
    z-index: 30;
}
.lanc-filter-form {
    display: grid;
    grid-template-columns: minmax(230px, 1.1fr) minmax(220px, 1.1fr) minmax(220px, 1.1fr) auto;
    gap: 14px;
    align-items: end;
}
.lanc-filter-field { min-width: 0; }
.lanc-filter-field .form-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    margin-bottom: 6px;
}
.lanc-filter-field .form-control {
    height: 42px;
    padding: 8px 12px;
    font-size: 0.88rem;
    border-radius: 10px;
}
.lanc-search-wrap { position: relative; }
.lanc-search-wrap .form-control { padding-left: 36px; }
.lanc-search-icon {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 14px;
    pointer-events: none;
}
.lanc-clear-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 42px;
    padding: 0 15px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: #64748b;
    background: #ffffff;
    text-decoration: none;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
    justify-self: start;
    transition: var(--transition);
}
.lanc-clear-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}

/* Calendário personalizado */
.cal-wrap { position: relative; }
.cal-trigger {
    width: 100%;
    height: 42px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    cursor: pointer;
    text-align: left;
    transition: var(--transition);
}
.cal-trigger:hover { border-color: #93c5fd; }
.cal-trigger.active {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
}
.cal-trigger-icon { font-size: 15px; line-height: 1; }
.cal-trigger-text {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.cal-trigger-text strong {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cal-trigger-text small {
    font-size: 0.68rem;
    font-weight: 600;
    color: #94a3b8;
    line-height: 1.2;
}
.cal-trigger-caret {
    color: #94a3b8;
    font-size: 0.7rem;
    transition: transform 0.2s ease;
}
.cal-trigger.active .cal-trigger-caret { transform: rotate(180deg); }

.cal-popover {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    width: 322px;
    max-width: calc(100vw - 42px);
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
    padding: 14px;
    z-index: 200;
    animation: calFadeIn 0.14s ease-out;
}
.cal-popover[hidden] { display: none !important; }
@keyframes calFadeIn {
    from { transform: translateY(-5px); }
    to { transform: translateY(0); }
}
.cal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 8px;
}
.cal-title {
    font-size: 0.86rem;
    font-weight: 800;
    color: #0f172a;
}
.cal-nav {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    border-radius: 8px;
    color: #475569;
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    transition: var(--transition);
}
.cal-nav:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}
.cal-weekdays, .cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}
.cal-weekday {
    text-align: center;
    font-size: 0.65rem;
    font-weight: 800;
    color: #94a3b8;
    text-transform: uppercase;
    padding: 5px 0;
}
.cal-grid { margin-top: 2px; }
.cal-empty { aspect-ratio: 1; }
.cal-day {
    position: relative;
    aspect-ratio: 1;
    border: none;
    background: transparent;
    border-radius: 9px;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.12s ease;
}
.cal-day:hover { background: #eff6ff; color: #1d4ed8; }
.cal-day.hoje {
    border: 1.5px solid #93c5fd;
    color: #1d4ed8;
    font-weight: 800;
}
.cal-day.selecionado {
    background: var(--primary);
    color: #ffffff;
    font-weight: 800;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.32);
}
.cal-day.selecionado:hover { background: var(--primary-hover); color: #ffffff; }
.cal-dot {
    position: absolute;
    bottom: 4px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #22c55e;
}
.cal-day.selecionado .cal-dot { background: #ffffff; }
.cal-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    margin-top: 10px;
    padding-top: 10px;
}
.cal-foot-link {
    border: none;
    background: none;
    padding: 4px 2px;
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}
.cal-foot-link:hover { color: #1d4ed8; }
.cal-foot-actions { display: flex; gap: 6px; }
.cal-foot-actions .btn { font-size: 0.72rem; padding: 5px 9px; }

@media (max-width: 1100px) {
    .lanc-filter-form { grid-template-columns: minmax(220px, 1fr) minmax(180px, 1fr); }
}
@media (max-width: 700px) {
    .lanc-filter-form { grid-template-columns: 1fr; }
    .lanc-clear-btn { width: 100%; justify-self: stretch; }
}
@media print {
    .lanc-filter-card { display: none !important; }
}
</style>

<!-- Cabeçalho da Página -->
<div class="page-header">
    <div class="page-title-group">
        <h1>Lançamentos</h1>
        <p>Registro diário de lavagem de peças por departamento</p>
    </div>
    <div class="page-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <?php if (usuarioTemPermissao('lancamentos_criar')): ?>
        <button type="button" class="btn btn-primary" onclick="abrirModalLancamentoLote()" style="display: flex; align-items: center; gap: 8px;">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Novo Lançamento</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- KPIs Rápidos -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon blue">📋</div>
        <div class="kpi-data">
            <span>Registros</span>
            <h3><?= number_format($totalItens, 0, ',', '.') ?></h3>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon amber">🧺</div>
        <div class="kpi-data">
            <span>Peças Lavadas</span>
            <h3><?= number_format($totalPecas, 0, ',', '.') ?></h3>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon green">💰</div>
        <div class="kpi-data">
            <span>Valor Total</span>
            <h3>R$ <?= number_format($valorTotalGeral, 2, ',', '.') ?></h3>
        </div>
    </div>
</div>

<!-- Barra de Filtros com Calendário -->
<div class="lanc-filter-card">
    <form method="GET" action="lancamentos.php" class="lanc-filter-form" id="formFiltrosLanc">
        <input type="hidden" name="mes" id="filtro_mes_hidden" value="<?= htmlspecialchars($filtroMes) ?>">
        <input type="hidden" name="data" id="filtro_data_hidden" value="<?= htmlspecialchars($filtroData) ?>">

        <div class="lanc-filter-field">
            <label class="form-label" for="calTrigger">Período</label>
            <div class="cal-wrap">
                <button type="button" class="cal-trigger" id="calTrigger"
                        aria-haspopup="dialog" aria-expanded="false" aria-controls="calPopover">
                    <span class="cal-trigger-icon">📅</span>
                    <span class="cal-trigger-text">
                        <strong id="calTriggerText"><?= htmlspecialchars($periodoSelecionadoLabel) ?></strong>
                        <small id="calTriggerHint"><?= htmlspecialchars($periodoSelecionadoHint) ?></small>
                    </span>
                    <span class="cal-trigger-caret">▼</span>
                </button>

                <div class="cal-popover" id="calPopover" role="dialog" aria-label="Selecionar período" hidden>
                    <div class="cal-head">
                        <button type="button" class="cal-nav" id="calPrev" aria-label="Mês anterior">‹</button>
                        <div class="cal-title" id="calTitle"><?= htmlspecialchars($labelMesCalendario) ?></div>
                        <button type="button" class="cal-nav" id="calNext" aria-label="Próximo mês">›</button>
                    </div>

                    <div class="cal-weekdays" aria-hidden="true">
                        <div class="cal-weekday">D</div>
                        <div class="cal-weekday">S</div>
                        <div class="cal-weekday">T</div>
                        <div class="cal-weekday">Q</div>
                        <div class="cal-weekday">Q</div>
                        <div class="cal-weekday">S</div>
                        <div class="cal-weekday">S</div>
                    </div>

                    <div class="cal-grid" id="calGrid"></div>

                    <div class="cal-foot">
                        <button type="button" class="cal-foot-link" id="calTodoPeriodo">Todo o período</button>
                        <div class="cal-foot-actions">
                            <button type="button" class="btn btn-outline btn-sm" id="calHoje">Hoje</button>
                            <button type="button" class="btn btn-primary btn-sm" id="calMesInteiro">Mês inteiro</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="lanc-filter-field">
            <label class="form-label" for="filtro_departamento">Departamento</label>
            <select name="departamento_id" id="filtro_departamento" class="form-control">
                <option value="">Todos os Departamentos</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (string)$d['id'] === $filtroDep ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="lanc-filter-field">
            <label class="form-label" for="filtro_busca">Buscar peça / departamento</label>
            <div class="lanc-search-wrap">
                <span class="lanc-search-icon">🔍</span>
                <input type="search" name="busca" id="filtro_busca"
                       value="<?= htmlspecialchars($filtroBusca) ?>"
                       placeholder="Digite e aguarde..."
                       class="form-control" maxlength="100" autocomplete="off">
            </div>
        </div>

        <a href="lancamentos.php" class="lanc-clear-btn" title="Limpar todos os filtros">
            ✕ Limpar
        </a>
    </form>
</div>


<!-- Tabela de Lançamentos (Fiel à Imagem 1) -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>📋</span>
            <span>Tabela Geral de Lançamentos</span>
        </div>
        <span style="font-size: 0.85rem; color: #64748b;">
            Exibindo <?= count($lancamentos) ?> lançamento(s)
        </span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Data</th>
                    <th>Departamentos</th>
                    <th>Peças</th>
                    <th style="width: 90px; text-align: center;">Qtde</th>
                    <th style="width: 130px; text-align: right;">Valor</th>
                    <th style="width: 140px; text-align: right;">Total</th>
                    <th>Lançado por</th>
                    <th style="width: 120px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lancamentos)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;">
                            Nenhum lançamento encontrado para os filtros selecionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lancamentos as $item): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($item['data_lancamento'])) ?></td>
                            <td><strong><?= htmlspecialchars($item['departamento_nome']) ?></strong></td>
                            <td><?= htmlspecialchars($item['produto_nome']) ?></td>
                            <td style="text-align: center; font-weight: 600;"><?= $item['quantidade'] ?></td>
                            <td style="text-align: right;" class="cell-currency">
                                R$ <?= number_format($item['valor_unitario'], 2, ',', '.') ?>
                            </td>
                            <td style="text-align: right;" class="cell-total">
                                R$ <?= number_format($item['valor_total'], 2, ',', '.') ?>
                            </td>
                            <td><?= htmlspecialchars($item['usuario_nome'] ?? 'Usuário removido', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <?php if (usuarioTemPermissao('lancamentos_editar')): ?>
                                    <button type="button" class="btn btn-outline btn-sm btn-icon"
                                            onclick='editarLancamentoNoLote(<?= htmlspecialchars(json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)'
                                            title="Editar">
                                        ✏️
                                    </button>
                                    <?php endif; ?>

                                    <?php if (usuarioTemPermissao('lancamentos_excluir')): ?>
                                    <form method="POST" action="lancamentos.php" style="display: inline;" 
                                          onsubmit="return confirm('Mover este lançamento para a Lixeira?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="acao" value="deletar">
                                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm btn-icon" style="color: #ef4444;" title="Mover para Lixeira">
                                            🗑️
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if (!usuarioTemPermissao('lancamentos_excluir')): ?>
                                        <span style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">Apenas leitura</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; background: #f8fafc; border-top: 1px solid var(--border-color); flex-wrap: wrap; gap: 12px;">
            <div style="font-size: 0.85rem; color: #64748b;">
                Página <strong><?= $paginaAtualNum ?></strong> de <strong><?= $totalPaginas ?></strong> (Total de <?= number_format($totalItens, 0, ',', '.') ?> lançamentos)
            </div>
            <div style="display: flex; gap: 6px;">
                <?php
                $queryParams = $_GET;
                if ($paginaAtualNum > 1):
                    $queryParams['pagina'] = $paginaAtualNum - 1;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="btn btn-outline btn-sm">‹ Anterior</a>
                <?php endif; ?>

                <?php
                $inicioPaginacao = max(1, $paginaAtualNum - 2);
                $fimPaginacao = min($totalPaginas, $paginaAtualNum + 2);
                for ($p = $inicioPaginacao; $p <= $fimPaginacao; $p++):
                    $queryParams['pagina'] = $p;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="btn btn-sm <?= $p === $paginaAtualNum ? 'btn-primary' : 'btn-outline' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php
                if ($paginaAtualNum < $totalPaginas):
                    $queryParams['pagina'] = $paginaAtualNum + 1;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="btn btn-outline btn-sm">Próxima ›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Lançamento em Lote (Multi-Peças por Departamento) -->
<div class="modal-overlay" id="modalLancamentoLote">
    <div class="modal-container" style="max-width: 860px; max-height: 92vh; display: flex; flex-direction: column;">
        <form method="POST" action="lancamentos.php" id="formLancamentoLote" onsubmit="return validarEnvioLote(event)" style="display: flex; flex-direction: column; height: 100%; overflow: hidden;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar_lote">
            <input type="hidden" name="editar_id" id="lote_editar_id" value="">

            <!-- Cabeçalho do Modal -->
            <div class="modal-header" style="flex-shrink: 0;">
                <div>
                    <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                        <span>📦</span>
                        <span id="tituloLancamentoLote">Lançamento em Lote</span>
                    </h3>
                    <div id="descricaoLancamentoLote" style="font-size: 0.82rem; color: #64748b; margin-top: 2px;">
                        Informe a data e o departamento uma única vez e adicione quantas peças precisar.
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="fecharModal('modalLancamentoLote')">&times;</button>
            </div>

            <!-- Corpo Rolável -->
            <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
                <!-- Cabeçalho do Lote: Data, Departamento e Observações Gerais -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: 180px 1fr; gap: 16px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="lote_data" style="font-weight: 600;">Data do Lote *</label>
                            <input type="date" name="data_lancamento" id="lote_data" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="buscar_lote_departamento" style="font-weight: 600; color: #1e293b;">
                                Departamento Atendido *
                                <span style="font-size: 0.75rem; color: #2563eb; font-weight: normal; margin-left: 6px;">(Aplicado a todas as peças abaixo)</span>
                            </label>
                            <div class="produto-busca-wrapper">
                                <input type="text" id="buscar_lote_departamento" class="form-control" placeholder="Digite para pesquisar o departamento..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="resultados_departamento_lote" style="border-color: #3b82f6; font-weight: 600;" required>
                                <div id="resultados_departamento_lote" class="produto-busca-resultados" role="listbox" hidden></div>
                            </div>
                            <select name="departamento_id" id="lote_departamento" class="form-control" style="display: none;" hidden>
                                <option value="">Selecione o departamento...</option>
                                <?php foreach ($departamentos as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="lote_obs" style="font-size: 0.8rem;">Observações Gerais do Lote (Opcional)</label>
                        <input type="text" name="observacoes_gerais" id="lote_obs" class="form-control" placeholder="Ex: Lavagem semanal, uniforme de jogos, roupas de mesa do evento...">
                    </div>
                </div>

                <!-- Grade de Peças -->
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div style="font-weight: 700; font-size: 0.95rem; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                            <span>🧺</span>
                            <span>Lista de Peças do Lote</span>
                        </div>
                        <button type="button" id="btnAdicionarLinhaLoteTopo" class="btn btn-outline btn-sm" onclick="adicionarLinhaLote()" style="display: flex; align-items: center; gap: 6px; font-weight: 600;">
                            <span>➕</span>
                            <span>Adicionar Linha</span>
                        </button>
                    </div>

                    <div class="table-responsive" style="border: 1px solid #e2e8f0; border-radius: var(--radius-md); overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="background: #f1f5f9; border-bottom: 2px solid #e2e8f0;">
                                <tr>
                                    <th style="padding: 10px 12px; text-align: left; font-size: 0.8rem; color: #475569; font-weight: 700;">Peça / Produto *</th>
                                    <th style="padding: 10px 12px; text-align: center; font-size: 0.8rem; color: #475569; font-weight: 700; width: 180px;">Qtd *</th>
                                    <th style="padding: 10px 12px; text-align: right; font-size: 0.8rem; color: #475569; font-weight: 700; width: 130px;">Valor Unit. *</th>
                                    <th style="padding: 10px 12px; text-align: right; font-size: 0.8rem; color: #475569; font-weight: 700; width: 140px;">Subtotal</th>
                                    <th style="padding: 10px 6px; text-align: center; font-size: 0.8rem; color: #475569; font-weight: 700; width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="tabelaItensLoteCorpo">
                                <!-- Linhas dinâmicas criadas via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Painel de Totais em Tempo Real -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-md); flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; gap: 20px; font-size: 0.88rem; color: #065f46;">
                        <div>
                            <span>Itens:</span> <strong id="lote_total_itens">0</strong>
                        </div>
                        <div>
                            <span>Total de Peças:</span> <strong id="lote_total_pecas">0</strong>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 0.9rem; font-weight: 600; color: #065f46;">Total Geral do Lote:</span>
                        <span id="lote_valor_geral" style="font-size: 1.3rem; font-weight: 800; color: #047857;">R$ 0,00</span>
                    </div>
                </div>
            </div>

            <!-- Rodapé do Modal -->
            <div class="modal-footer" style="flex-shrink: 0; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between;">
                <button type="button" id="btnAdicionarLinhaLoteRodape" class="btn btn-outline" onclick="adicionarLinhaLote()">
                    ➕ Adicionar Mais Peças
                </button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn btn-outline" onclick="fecharModal('modalLancamentoLote')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px; font-weight: 700;">
                        <span>💾</span>
                        <span id="textoBotaoSalvarLote">Gravar Todo o Lote</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
#btnAdicionarLinhaLoteTopo[hidden],
#btnAdicionarLinhaLoteRodape[hidden],
.lote-remover-item[hidden] {
    display: none !important;
}

.produto-busca-resultados {
    position: fixed;
    max-height: 220px;
    overflow-y: auto;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.16);
    color: var(--text-primary);
    z-index: 1200;
}

.produto-busca-resultados[hidden] {
    display: none;
}

.produto-busca-opcao {
    display: block;
    width: 100%;
    padding: 9px 12px;
    border: 0;
    background: var(--bg-card);
    color: var(--text-primary);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.produto-busca-opcao:hover,
.produto-busca-opcao:focus {
    background: var(--bg-muted);
    outline: none;
}

.produto-busca-vazio {
    padding: 9px 12px;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.lote-qtd-controles {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.lote-qtd-controles .lote-qtd-input {
    flex: 0 0 76px;
    width: 76px;
    min-width: 76px;
    height: 36px;
    padding: 6px 4px;
    text-align: center;
    font-size: 0.9rem;
}

.lote-qtd-step,
.lote-qtd-grade-abrir {
    flex: 0 0 30px;
    width: 30px;
    min-width: 30px;
    height: 36px;
    padding: 0;
}

.lote-qtd-grade {
    position: fixed;
    z-index: 1250;
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 4px;
    width: min(320px, calc(100vw - 24px));
    max-height: min(300px, calc(100vh - 24px));
    overflow-y: auto;
    padding: 10px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.2);
}

.lote-qtd-grade[hidden] {
    display: none;
}

.lote-qtd-grade button {
    min-height: 28px;
    padding: 2px;
    border: 1px solid var(--border-color);
    border-radius: 5px;
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
}

.lote-qtd-grade button:hover,
.lote-qtd-grade button:focus {
    border-color: var(--primary);
    background: var(--bg-muted);
    outline: none;
}
</style>

<script>
// ── Garantia de Funções de Modal Seguras ──────────────────────────────────
function abrirModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
    }
}

function fecharModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

function configurarBuscaSelect(input, select, mensagemVazia) {
    const resultados = input.parentElement.querySelector('.produto-busca-resultados');
    document.body.appendChild(resultados);

    function normalizarBusca(texto) {
        return texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    }

    function ocultarResultados() {
        resultados.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        window.removeEventListener('scroll', fecharAoRolar, true);
        window.removeEventListener('resize', ocultarResultados);
    }

    function fecharAoRolar(event) {
        if (!resultados.contains(event.target)) {
            ocultarResultados();
        }
    }

    function selecionarOpcao(opcao) {
        const valorAnterior = select.value;
        input.value = opcao.textContent.trim();
        select.value = opcao.value;
        input.setCustomValidity('');
        ocultarResultados();

        if (select.value !== valorAnterior) {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function mostrarResultados() {
        const termo = normalizarBusca(input.value.trim());
        const campo = input.getBoundingClientRect();
        const opcoes = Array.from(select.options).filter(function(option) {
            return option.value && normalizarBusca(option.textContent.trim()).includes(termo);
        });

        resultados.style.top = (campo.bottom + 2) + 'px';
        resultados.style.left = campo.left + 'px';
        resultados.style.width = campo.width + 'px';
        resultados.replaceChildren();
        if (opcoes.length === 0) {
            const vazio = document.createElement('div');
            vazio.className = 'produto-busca-vazio';
            vazio.textContent = mensagemVazia;
            resultados.appendChild(vazio);
        } else {
            opcoes.forEach(function(opcao) {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'produto-busca-opcao';
                botao.setAttribute('role', 'option');
                botao.textContent = opcao.textContent.trim();
                botao.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                });
                botao.addEventListener('click', function() {
                    selecionarOpcao(opcao);
                });
                resultados.appendChild(botao);
            });
        }

        resultados.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        window.addEventListener('scroll', fecharAoRolar, true);
        window.addEventListener('resize', ocultarResultados);
    }

    function sincronizarSelecao() {
        const termo = input.value.trim();
        const opcao = Array.from(select.options).find(function(option) {
            return option.value && normalizarBusca(option.textContent.trim()) === normalizarBusca(termo);
        });
        const valorAnterior = select.value;

        select.value = opcao ? opcao.value : '';
        input.setCustomValidity(termo && !opcao ? 'Selecione uma opção válida da lista.' : '');

        if (select.value !== valorAnterior) {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    input.addEventListener('focus', mostrarResultados);
    input.addEventListener('input', function() {
        sincronizarSelecao();
        mostrarResultados();
    });
    input.addEventListener('change', sincronizarSelecao);
    input.addEventListener('blur', function() {
        window.setTimeout(ocultarResultados, 120);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const selectDepLote = document.getElementById('lote_departamento');
    const buscaDepLote = document.getElementById('buscar_lote_departamento');

    if (selectDepLote && buscaDepLote) {
        configurarBuscaSelect(buscaDepLote, selectDepLote, 'Nenhum departamento encontrado.');
    }
});

// ── Lógica do Lançamento em Lote ──────────────────────────────────────────
const catalogoProdutos = <?= json_encode(array_map(function($p) {
    return [
        'id' => (int)$p['id'],
        'nome' => $p['nome'],
        'valor_padrao' => (float)$p['valor_padrao']
    ];
}, $produtos)) ?>;

let contadorLinhasLote = 0;

function prepararModalLote(editar) {
    fecharSeletorQuantidadeLote();
    document.querySelectorAll('.lote-qtd-grade').forEach(function(grade) {
        grade.remove();
    });
    document.getElementById('formLancamentoLote').reset();
    document.getElementById('buscar_lote_departamento').setCustomValidity('');
    document.getElementById('lote_editar_id').value = '';
    document.getElementById('tituloLancamentoLote').textContent = editar ? 'Editar Lançamento' : 'Lançamento em Lote';
    document.getElementById('descricaoLancamentoLote').textContent = editar
        ? 'Altere os dados do lançamento selecionado.'
        : 'Informe a data e o departamento uma única vez e adicione quantas peças precisar.';
    document.getElementById('textoBotaoSalvarLote').textContent = editar ? 'Salvar Alterações' : 'Gravar Todo o Lote';
    document.getElementById('btnAdicionarLinhaLoteTopo').hidden = editar;
    document.getElementById('btnAdicionarLinhaLoteRodape').hidden = editar;
    document.getElementById('tabelaItensLoteCorpo').innerHTML = '';
    contadorLinhasLote = 0;
}

function abrirModalLancamentoLote() {
    prepararModalLote(false);
    document.getElementById('lote_data').value = '<?= date('Y-m-d') ?>';

    // Começa com uma linha; outras podem ser adicionadas conforme necessário.
    adicionarLinhaLote();

    recalcularTotaisLote();
    abrirModal('modalLancamentoLote');
}

function editarLancamentoNoLote(item) {
    prepararModalLote(true);
    document.getElementById('lote_editar_id').value = item.id;
    document.getElementById('lote_data').value = item.data_lancamento;
    document.getElementById('lote_obs').value = item.observacoes || '';

    const selectDepartamento = document.getElementById('lote_departamento');
    const buscaDepartamento = document.getElementById('buscar_lote_departamento');
    selectDepartamento.value = item.departamento_id;
    buscaDepartamento.value = selectDepartamento.selectedIndex > 0
        ? selectDepartamento.options[selectDepartamento.selectedIndex].textContent.trim()
        : '';
    buscaDepartamento.setCustomValidity('');

    adicionarLinhaLote(
        item.produto_id,
        item.quantidade,
        parseFloat(item.valor_unitario).toFixed(2)
    );
    document.querySelector('#tabelaItensLoteCorpo .lote-remover-item').hidden = true;

    recalcularTotaisLote();
    abrirModal('modalLancamentoLote');
}

function adicionarLinhaLote(produtoId = '', quantidade = 1, valorUnitario = '') {
    contadorLinhasLote++;
    const idx = contadorLinhasLote;
    const corpo = document.getElementById('tabelaItensLoteCorpo');

    const tr = document.createElement('tr');
    tr.id = 'linha_lote_' + idx;

    let optionsHtml = '<option value="">Selecione a peça...</option>';
    catalogoProdutos.forEach(p => {
        const selected = (p.id == produtoId) ? 'selected' : '';
        const precoFmt = parseFloat(p.valor_padrao).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        optionsHtml += `<option value="${p.id}" data-preco="${p.valor_padrao}" ${selected}>${p.nome} (R$ ${precoFmt})</option>`;
    });

    tr.innerHTML = `
        <td style="padding: 8px 10px;">
            <div class="produto-busca-wrapper">
                <input type="text" class="form-control lote-produto-busca" placeholder="Digite para pesquisar..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="resultados_produto_lote_${idx}" style="font-size: 0.88rem;" required>
                <div id="resultados_produto_lote_${idx}" class="produto-busca-resultados" role="listbox" hidden></div>
            </div>
            <select name="itens[${idx}][produto_id]" class="form-control lote-produto-select" onchange="aoMudarProdutoLote(this, ${idx})" style="display: none;" hidden>
                ${optionsHtml}
            </select>
        </td>
        <td style="padding: 8px 10px; width: 180px;">
            <div class="lote-qtd-controles">
                <button type="button" class="btn btn-outline btn-sm lote-qtd-step" onclick="alterarQuantidadeLote(${idx}, -1)" aria-label="Diminuir quantidade">-</button>
                <input type="number" name="itens[${idx}][quantidade]" id="lote_qtd_${idx}" class="form-control lote-qtd-input" min="1" step="1" value="${quantidade}" oninput="recalcularLinhaLote(${idx})" required>
                <button type="button" class="btn btn-outline btn-sm lote-qtd-step" onclick="alterarQuantidadeLote(${idx}, 1)" aria-label="Aumentar quantidade">+</button>
                <button type="button" class="btn btn-outline btn-sm lote-qtd-grade-abrir" id="lote_qtd_grade_btn_${idx}" aria-label="Abrir seletor de quantidade" aria-haspopup="dialog" aria-expanded="false" aria-controls="lote_qtd_grade_${idx}" onclick="alternarSeletorQuantidadeLote(${idx}, event)">▦</button>
            </div>
            <div id="lote_qtd_grade_${idx}" class="lote-qtd-grade" role="dialog" aria-label="Selecionar quantidade de 1 a 100" hidden></div>
        </td>
        <td style="padding: 8px 10px; width: 130px;">
            <input type="number" step="0.01" min="0.01" name="itens[${idx}][valor_unitario]" id="lote_val_${idx}" class="form-control lote-val-input" placeholder="0.00" value="${valorUnitario}" oninput="recalcularLinhaLote(${idx})" style="text-align: right; font-size: 0.88rem;" required>
        </td>
        <td style="padding: 8px 10px; width: 140px; text-align: right;">
            <input type="text" id="lote_subtotal_${idx}" class="form-control" readonly style="background: #f8fafc; font-weight: 700; color: #047857; text-align: right; font-size: 0.88rem;" value="R$ 0,00">
        </td>
        <td style="padding: 8px 6px; width: 50px; text-align: center;">
            <button type="button" class="btn btn-outline btn-sm btn-icon lote-remover-item" onclick="removerLinhaLote(${idx})" style="color: #ef4444; border-color: #fee2e2;" title="Remover item">
                🗑️
            </button>
        </td>
    `;

    corpo.appendChild(tr);
    configurarBuscaSelect(tr.querySelector('.lote-produto-busca'), tr.querySelector('.lote-produto-select'), 'Nenhuma peça encontrada.');
    const gradeQuantidade = tr.querySelector('.lote-qtd-grade');
    gradeQuantidade.innerHTML = Array.from({ length: 100 }, function(_, indice) {
        const quantidadeGrade = indice + 1;
        return `<button type="button" data-quantidade="${quantidadeGrade}" aria-label="${quantidadeGrade}">${quantidadeGrade}</button>`;
    }).join('');
    gradeQuantidade.addEventListener('click', function(event) {
        const opcao = event.target.closest('[data-quantidade]');
        if (opcao) {
            selecionarQuantidadeLote(idx, parseInt(opcao.dataset.quantidade, 10));
        }
    });
    document.body.appendChild(gradeQuantidade);

    if (produtoId) {
        const selectProduto = tr.querySelector('.lote-produto-select');
        const buscaProduto = tr.querySelector('.lote-produto-busca');
        buscaProduto.value = selectProduto.options[selectProduto.selectedIndex].textContent.trim();
        recalcularLinhaLote(idx);
    }
}

let seletorQuantidadeLoteAberto = null;

function fecharSeletorQuantidadeLote() {
    if (!seletorQuantidadeLoteAberto) return;

    seletorQuantidadeLoteAberto.grade.hidden = true;
    seletorQuantidadeLoteAberto.botao.setAttribute('aria-expanded', 'false');
    seletorQuantidadeLoteAberto = null;
}

function alternarSeletorQuantidadeLote(idx, event) {
    event.stopPropagation();
    const botao = document.getElementById('lote_qtd_grade_btn_' + idx);
    const grade = document.getElementById('lote_qtd_grade_' + idx);

    if (!botao || !grade) return;
    if (seletorQuantidadeLoteAberto && seletorQuantidadeLoteAberto.grade === grade) {
        fecharSeletorQuantidadeLote();
        return;
    }

    fecharSeletorQuantidadeLote();
    grade.hidden = false;
    botao.setAttribute('aria-expanded', 'true');

    const retangulo = botao.getBoundingClientRect();
    const largura = grade.offsetWidth;
    const altura = grade.offsetHeight;
    const esquerda = Math.max(12, Math.min(retangulo.left, window.innerWidth - largura - 12));
    const topo = retangulo.bottom + altura <= window.innerHeight - 12
        ? retangulo.bottom + 6
        : Math.max(12, retangulo.top - altura - 6);

    grade.style.left = esquerda + 'px';
    grade.style.top = topo + 'px';
    seletorQuantidadeLoteAberto = { botao: botao, grade: grade };
}

function selecionarQuantidadeLote(idx, quantidade) {
    const input = document.getElementById('lote_qtd_' + idx);
    if (!input) return;

    input.value = quantidade;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    fecharSeletorQuantidadeLote();
    input.focus();
}

document.addEventListener('click', function(event) {
    if (seletorQuantidadeLoteAberto
        && !seletorQuantidadeLoteAberto.grade.contains(event.target)
        && !seletorQuantidadeLoteAberto.botao.contains(event.target)) {
        fecharSeletorQuantidadeLote();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') fecharSeletorQuantidadeLote();
});

window.addEventListener('scroll', fecharSeletorQuantidadeLote, true);
window.addEventListener('resize', fecharSeletorQuantidadeLote);

function alterarQuantidadeLote(idx, incremento) {
    const input = document.getElementById('lote_qtd_' + idx);
    if (!input) return;

    const quantidadeAtual = parseInt(input.value, 10) || 1;
    input.value = Math.max(1, quantidadeAtual + incremento);
    recalcularLinhaLote(idx);
}

function aoMudarProdutoLote(selectEl, idx) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const preco = selectedOption.getAttribute('data-preco');
    const valInput = document.getElementById('lote_val_' + idx);
    
    if (preco && (!valInput.value || parseFloat(valInput.value) <= 0)) {
        valInput.value = parseFloat(preco).toFixed(2);
    }

    recalcularLinhaLote(idx);
}

function recalcularLinhaLote(idx) {
    const qtdInput = document.getElementById('lote_qtd_' + idx);
    const valInput = document.getElementById('lote_val_' + idx);
    const subtotalInput = document.getElementById('lote_subtotal_' + idx);

    if (!qtdInput || !valInput || !subtotalInput) return;

    const qtd = parseInt(qtdInput.value) || 0;
    const val = parseFloat(valInput.value) || 0;
    const subtotal = qtd * val;

    subtotalInput.value = 'R$ ' + subtotal.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    recalcularTotaisLote();
}

function removerLinhaLote(idx) {
    const corpo = document.getElementById('tabelaItensLoteCorpo');
    const linha = document.getElementById('linha_lote_' + idx);
    
    if (corpo.children.length <= 1) {
        alert('O lote deve conter pelo menos uma linha de peça.');
        return;
    }

    if (linha) {
        const gradeQuantidade = document.getElementById('lote_qtd_grade_' + idx);
        if (gradeQuantidade) {
            if (seletorQuantidadeLoteAberto && seletorQuantidadeLoteAberto.grade === gradeQuantidade) {
                fecharSeletorQuantidadeLote();
            }
            gradeQuantidade.remove();
        }
        linha.remove();
        recalcularTotaisLote();
    }
}

function recalcularTotaisLote() {
    let totalItensValidos = 0;
    let totalPecas = 0;
    let totalValor = 0;

    const linhas = document.querySelectorAll('#tabelaItensLoteCorpo tr');
    linhas.forEach(tr => {
        const select = tr.querySelector('.lote-produto-select');
        const qtdInput = tr.querySelector('.lote-qtd-input');
        const valInput = tr.querySelector('.lote-val-input');

        if (select && select.value) {
            const qtd = parseInt(qtdInput.value) || 0;
            const val = parseFloat(valInput.value) || 0;
            totalItensValidos++;
            totalPecas += qtd;
            totalValor += (qtd * val);
        }
    });

    document.getElementById('lote_total_itens').innerText = totalItensValidos;
    document.getElementById('lote_total_pecas').innerText = totalPecas;
    document.getElementById('lote_valor_geral').innerText = 'R$ ' + totalValor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function validarEnvioLote(event) {
    const depSelect = document.getElementById('lote_departamento');
    if (!depSelect.value) {
        alert('Por favor, selecione o Departamento antes de salvar o lote.');
        document.getElementById('buscar_lote_departamento').focus();
        event.preventDefault();
        return false;
    }

    let itensComProduto = 0;
    const selects = document.querySelectorAll('#tabelaItensLoteCorpo .lote-produto-select');
    selects.forEach(s => {
        if (s.value) itensComProduto++;
    });

    if (itensComProduto === 0) {
        alert('Selecione pelo menos uma peça na lista para salvar o lote.');
        document.querySelector('#tabelaItensLoteCorpo .lote-produto-busca').focus();
        event.preventDefault();
        return false;
    }

    if (document.getElementById('lote_editar_id').value && selects.length !== 1) {
        alert('A edição deve conter exatamente uma peça.');
        event.preventDefault();
        return false;
    }

    return true;
}
</script>

<script>
// ==========================================================================
// Filtros automáticos + calendário personalizado
// ==========================================================================
(function () {
    const form = document.getElementById('formFiltrosLanc');
    if (!form) return;

    const inputMes = document.getElementById('filtro_mes_hidden');
    const inputData = document.getElementById('filtro_data_hidden');
    const selectDep = document.getElementById('filtro_departamento');
    const inputBusca = document.getElementById('filtro_busca');

    let timerBusca = null;

    // Departamento é aplicado imediatamente
    if (selectDep) {
        selectDep.addEventListener('change', function () {
            form.submit();
        });
    }

    // Busca com pequeno atraso para não recarregar a cada tecla
    if (inputBusca) {
        inputBusca.addEventListener('input', function () {
            clearTimeout(timerBusca);
            timerBusca = setTimeout(function () {
                form.submit();
            }, 650);
        });

        inputBusca.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                clearTimeout(timerBusca);
                form.submit();
            }
        });
    }

    const trigger = document.getElementById('calTrigger');
    const popover = document.getElementById('calPopover');
    if (!trigger || !popover) return;

    const grid = document.getElementById('calGrid');
    const calTitle = document.getElementById('calTitle');

    const MESES = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'
    ];

    const estado = {
        mes: <?= json_encode($mesCalendario) ?>,
        selecionado: <?= json_encode($filtroData) ?>,
        dias: <?= json_encode($diasComMovimento, JSON_UNESCAPED_UNICODE) ?>
    };

    const hoje = new Date();
    const hojeISO = hoje.getFullYear() + '-'
        + String(hoje.getMonth() + 1).padStart(2, '0') + '-'
        + String(hoje.getDate()).padStart(2, '0');

    function formatarMes(ym) {
        const partes = ym.split('-');
        const mes = MESES[parseInt(partes[1], 10) - 1] || '';
        return mes.charAt(0).toUpperCase() + mes.slice(1) + ' de ' + partes[0];
    }

    function renderizarCalendario() {
        const partes = estado.mes.split('-');
        const ano = parseInt(partes[0], 10);
        const mes = parseInt(partes[1], 10);

        calTitle.textContent = formatarMes(estado.mes);

        const primeiroDiaSemana = new Date(ano, mes - 1, 1).getDay();
        const totalDias = new Date(ano, mes, 0).getDate();

        let html = '';
        for (let i = 0; i < primeiroDiaSemana; i++) {
            html += '<div class="cal-empty"></div>';
        }

        for (let dia = 1; dia <= totalDias; dia++) {
            const iso = estado.mes + '-' + String(dia).padStart(2, '0');
            const info = estado.dias[iso] || null;
            const classes = ['cal-day'];

            if (iso === hojeISO) classes.push('hoje');
            if (iso === estado.selecionado) classes.push('selecionado');

            let titulo = 'Sem lançamentos';
            if (info && info.qtd > 0) {
                const valor = Number(info.valor).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                titulo = info.qtd + ' lançamento(s) • ' + info.pecas + ' peça(s) • R$ ' + valor;
            }

            html += '<button type="button" class="' + classes.join(' ') + '"'
                + ' data-data="' + iso + '"'
                + ' aria-label="' + dia + ' de ' + formatarMes(estado.mes) + '"'
                + ' aria-pressed="' + (iso === estado.selecionado ? 'true' : 'false') + '"'
                + ' title="' + titulo + '">'
                + '<span>' + dia + '</span>'
                + (info && info.qtd > 0 ? '<span class="cal-dot"></span>' : '')
                + '</button>';
        }

        grid.innerHTML = html;
    }

    async function carregarDiasDoMes(mes) {
        const params = new URLSearchParams({ ajax: 'dias', cal_mes: mes });
        if (selectDep && selectDep.value) params.set('departamento_id', selectDep.value);
        if (inputBusca && inputBusca.value.trim()) params.set('busca', inputBusca.value.trim());

        try {
            const resposta = await fetch('lancamentos.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!resposta.ok) return;

            const dados = await resposta.json();
            if (dados && dados.dias && mes === estado.mes) {
                estado.dias = dados.dias;
                renderizarCalendario();
            }
        } catch (erro) {
            // Sem conexão ou sessão expirada: mantém o calendário sem os pontos
        }
    }

    function mudarMes(delta) {
        const partes = estado.mes.split('-');
        const dataBase = new Date(parseInt(partes[0], 10), parseInt(partes[1], 10) - 1 + delta, 1);

        estado.mes = dataBase.getFullYear() + '-' + String(dataBase.getMonth() + 1).padStart(2, '0');
        estado.dias = {};
        renderizarCalendario();
        carregarDiasDoMes(estado.mes);
    }

    function abrirCalendario() {
        clearTimeout(timerBusca);
        popover.hidden = false;
        trigger.classList.add('active');
        trigger.setAttribute('aria-expanded', 'true');
        renderizarCalendario();
    }

    function fecharCalendario() {
        popover.hidden = true;
        trigger.classList.remove('active');
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (event) {
        event.stopPropagation();
        if (popover.hidden) {
            abrirCalendario();
        } else {
            fecharCalendario();
        }
    });

    document.getElementById('calPrev').addEventListener('click', function () {
        mudarMes(-1);
    });

    document.getElementById('calNext').addEventListener('click', function () {
        mudarMes(1);
    });

    grid.addEventListener('click', function (event) {
        const botaoDia = event.target.closest('.cal-day');
        if (!botaoDia) return;

        inputData.value = botaoDia.getAttribute('data-data');
        inputMes.value = '';
        form.submit();
    });

    document.getElementById('calMesInteiro').addEventListener('click', function () {
        inputData.value = '';
        inputMes.value = estado.mes;
        form.submit();
    });

    document.getElementById('calTodoPeriodo').addEventListener('click', function () {
        inputData.value = '';
        inputMes.value = '';
        form.submit();
    });

    document.getElementById('calHoje').addEventListener('click', function () {
        inputData.value = hojeISO;
        inputMes.value = '';
        form.submit();
    });

    popover.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function (event) {
        if (!popover.hidden && !trigger.contains(event.target) && !popover.contains(event.target)) {
            fecharCalendario();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !popover.hidden) {
            fecharCalendario();
            trigger.focus();
        }
    });

    renderizarCalendario();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
