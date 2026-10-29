<?php
// views/relatorios.php - Relatórios Executivos da Lavanderia

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('relatorios_ver');
$pdo = getDB();

$pageTitle = 'Relatórios Executivos';

// Modelo selecionado: 'departamento_detalhado', 'pecas_consolidado', 'resumo_departamentos'
$modelo = is_string($_GET['modelo'] ?? null) ? $_GET['modelo'] : 'resumo_departamentos';
if (!in_array($modelo, ['resumo_departamentos', 'departamento_detalhado', 'pecas_consolidado'], true)) {
    $modelo = 'resumo_departamentos';
}

// ── Filtros Avançados ────────────────────────────────────────────────────────
$getStr = static function (string $chave): string {
    $valor = $_GET[$chave] ?? '';
    return is_string($valor) ? trim($valor) : '';
};
$getInt = static function (string $chave): int {
    $valor = $_GET[$chave] ?? '';
    return is_string($valor) && ctype_digit($valor) ? (int)$valor : 0;
};

$tipoPeriodo = $getStr('tipo_periodo') ?: 'mes'; // mes | dia | intervalo | todos
if (!in_array($tipoPeriodo, ['mes', 'dia', 'intervalo', 'todos'], true)) {
    $tipoPeriodo = 'mes';
}
$mesSel        = $getStr('mes');
$dataSel       = $getStr('data');
$dataInicio    = $getStr('data_inicio');
$dataFim       = $getStr('data_fim');
$depFiltro     = $getInt('departamento_id');
$prodFiltro    = $getInt('produto_id');
$ordemFiltro   = $getStr('ordem') ?: 'valor_desc';
if (!in_array($ordemFiltro, ['valor_desc', 'pecas_desc', 'nome_asc', 'valor_asc', 'data_desc'], true)) {
    $ordemFiltro = 'valor_desc';
}

$sysConfig = obterConfiguracoes();
$qtdAssinaturasDefault = (int)($sysConfig['relatorio_assinatura_qtd'] ?? 2);
if ($qtdAssinaturasDefault < 0 || $qtdAssinaturasDefault > 3) {
    $qtdAssinaturasDefault = 2;
}
$qtdAssinaturasRaw = $_GET['qtd_assinaturas'] ?? null;
$qtdAssinaturas = is_string($qtdAssinaturasRaw) && ctype_digit($qtdAssinaturasRaw)
    ? (int)$qtdAssinaturasRaw
    : $qtdAssinaturasDefault;
if ($qtdAssinaturas < 0 || $qtdAssinaturas > 3) {
    $qtdAssinaturas = $qtdAssinaturasDefault;
}

$mesesPt = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

$dataValida = static function (string $data): bool {
    if ($data === '') {
        return false;
    }
    $obj = DateTime::createFromFormat('Y-m-d', $data);
    return $obj !== false && $obj->format('Y-m-d') === $data;
};

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesSel)) {
    $mesSel = '';
}
if (!$dataValida($dataSel)) {
    $dataSel = '';
}
if (!$dataValida($dataInicio)) {
    $dataInicio = '';
}
if (!$dataValida($dataFim)) {
    $dataFim = '';
}
if ($dataInicio !== '' && $dataFim !== '' && $dataInicio > $dataFim) {
    [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
}

if ($tipoPeriodo === 'dia' && $dataSel === '') {
    $tipoPeriodo = 'mes';
}
if ($tipoPeriodo === 'intervalo' && ($dataInicio === '' || $dataFim === '')) {
    $tipoPeriodo = 'mes';
}

// Meses disponíveis com lançamentos
$mesesDisponiveis = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(data_lancamento, '%Y-%m') as ym
    FROM lancamentos 
    WHERE deleted_at IS NULL 
    ORDER BY ym DESC
")->fetchAll(PDO::FETCH_COLUMN);

// ── Calendário: dias com movimento ──────────────────────────────────────────
$carregarDiasRelatorio = static function (string $mes) use ($pdo, $depFiltro, $prodFiltro): array {
    $sql = "
        SELECT l.data_lancamento,
               COUNT(*) AS qtd_lancamentos,
               COALESCE(SUM(l.quantidade), 0) AS qtd_pecas,
               COALESCE(SUM(l.valor_total), 0) AS valor_total
        FROM lancamentos l
        WHERE l.deleted_at IS NULL
          AND DATE_FORMAT(l.data_lancamento, '%Y-%m') = ?
    ";
    $params = [$mes];

    if ($depFiltro > 0) {
        $sql .= " AND l.departamento_id = ?";
        $params[] = $depFiltro;
    }
    if ($prodFiltro > 0) {
        $sql .= " AND l.produto_id = ?";
        $params[] = $prodFiltro;
    }

    $sql .= " GROUP BY l.data_lancamento";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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

// Endpoint interno usado ao navegar entre os meses do calendário
if (($_GET['ajax'] ?? '') === 'dias') {
    $mesAjax = $getStr('cal_mes');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesAjax)) {
        $mesAjax = $mesesDisponiveis[0] ?? date('Y-m');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'mes'  => $mesAjax,
        'dias' => $carregarDiasRelatorio($mesAjax),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Resolução do período
if ($tipoPeriodo === 'dia') {
    $mesSel = '';
    $dataInicio = '';
    $dataFim = '';
    $whereData = "l.data_lancamento = ?";
    $paramsData = [$dataSel];
    $periodoLabel = date('d/m/Y', strtotime($dataSel));
    $periodoLabelCurto = $periodoLabel;
    $periodoHint = 'Dia selecionado';
} elseif ($tipoPeriodo === 'intervalo') {
    $mesSel = '';
    $dataSel = '';
    $whereData = "l.data_lancamento BETWEEN ? AND ?";
    $paramsData = [$dataInicio, $dataFim];
    $periodoLabel = date('d/m/Y', strtotime($dataInicio)) . ' até ' . date('d/m/Y', strtotime($dataFim));
    $periodoLabelCurto = $periodoLabel;
    if (date('Y', strtotime($dataInicio)) === date('Y', strtotime($dataFim))) {
        $periodoLabelCurto = date('d/m', strtotime($dataInicio)) . ' – ' . date('d/m/Y', strtotime($dataFim));
    }
    $periodoHint = 'Período personalizado';
} elseif ($tipoPeriodo === 'todos') {
    $mesSel = '';
    $dataSel = '';
    $dataInicio = '';
    $dataFim = '';
    $whereData = "1=1";
    $paramsData = [];
    $periodoLabel = 'TODO O PERÍODO';
    $periodoLabelCurto = 'Todo o período';
    $periodoHint = 'Sem filtro de data';
} else {
    $tipoPeriodo = 'mes';
    $dataSel = '';
    $dataInicio = '';
    $dataFim = '';
    if ($mesSel === '') {
        $mesAtual = date('Y-m');
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM lancamentos WHERE deleted_at IS NULL AND DATE_FORMAT(data_lancamento, '%Y-%m') = ?");
        $stmtCheck->execute([$mesAtual]);
        if ($stmtCheck->fetchColumn() > 0 || empty($mesesDisponiveis)) {
            $mesSel = $mesAtual;
        } else {
            $mesSel = $mesesDisponiveis[0] ?? $mesAtual;
        }
    }
    $whereData = "DATE_FORMAT(l.data_lancamento, '%Y-%m') = ?";
    $paramsData = [$mesSel];

    $partesData = explode('-', $mesSel);
    $mesNomeExtenso = ($mesesPt[$partesData[1] ?? '01'] ?? '') . ' de ' . ($partesData[0] ?? '');
    $periodoLabel = mb_strtoupper($mesNomeExtenso, 'UTF-8');
    $periodoLabelCurto = $mesNomeExtenso;
    $periodoHint = 'Mês fechado';
}

// Mês em foco no calendário
if ($tipoPeriodo === 'dia') {
    $mesCalendario = substr($dataSel, 0, 7);
} elseif ($tipoPeriodo === 'intervalo') {
    $mesCalendario = substr($dataFim, 0, 7);
} elseif ($tipoPeriodo === 'mes') {
    $mesCalendario = $mesSel;
} else {
    $mesCalendario = $mesesDisponiveis[0] ?? date('Y-m');
}
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesCalendario)) {
    $mesCalendario = $mesesDisponiveis[0] ?? date('Y-m');
}
$diasComMovimento = $carregarDiasRelatorio($mesCalendario);
$partesMesCalendario = explode('-', $mesCalendario);
$labelMesCalendario = ($mesesPt[$partesMesCalendario[1] ?? '01'] ?? '') . ' de ' . ($partesMesCalendario[0] ?? '');
$anoAtalhos = $partesMesCalendario[0] ?? date('Y');

// Links que preservam todos os filtros atuais
$linkFiltros = static function (array $overrides = []) use (
    $modelo, $tipoPeriodo, $mesSel, $dataSel, $dataInicio, $dataFim,
    $depFiltro, $prodFiltro, $ordemFiltro, $qtdAssinaturas
): string {
    $params = [
        'modelo'          => $modelo,
        'tipo_periodo'    => $tipoPeriodo,
        'mes'             => $mesSel,
        'data'            => $dataSel,
        'data_inicio'     => $dataInicio,
        'data_fim'        => $dataFim,
        'departamento_id' => $depFiltro,
        'produto_id'      => $prodFiltro,
        'ordem'           => $ordemFiltro,
        'qtd_assinaturas' => $qtdAssinaturas,
    ];
    foreach ($overrides as $chave => $valor) {
        $params[$chave] = $valor;
    }
    return '?' . http_build_query($params);
};

// Listas auxiliares para os filtros
$todosDepartamentos = $pdo->query("SELECT id, nome FROM departamentos WHERE deleted_at IS NULL ORDER BY nome ASC")->fetchAll();
$todosProdutos      = $pdo->query("SELECT id, nome, valor_padrao FROM produtos WHERE deleted_at IS NULL ORDER BY nome ASC")->fetchAll();

// ==========================================================================
// CONSULTA MODELO 1: DETALHADO POR DEPARTAMENTO
// ==========================================================================
$sqlMod1 = "
    SELECT l.*, 
           COALESCE(d.nome, l.departamento_nome_snapshot, 'Setor Removido') AS departamento_nome, 
           COALESCE(p.nome, l.produto_nome_snapshot, 'Peça Removida') AS produto_nome
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    LEFT JOIN produtos p ON p.id = l.produto_id
    WHERE l.deleted_at IS NULL AND $whereData
";
$paramsMod1 = $paramsData;

if ($depFiltro > 0) {
    $sqlMod1 .= " AND l.departamento_id = ?";
    $paramsMod1[] = $depFiltro;
}
if ($prodFiltro > 0) {
    $sqlMod1 .= " AND l.produto_id = ?";
    $paramsMod1[] = $prodFiltro;
}

// Ordenação
switch ($ordemFiltro) {
    case 'valor_asc':   $sqlMod1 .= " ORDER BY d.nome ASC, l.valor_total ASC"; break;
    case 'pecas_desc':  $sqlMod1 .= " ORDER BY d.nome ASC, l.quantidade DESC"; break;
    case 'nome_asc':    $sqlMod1 .= " ORDER BY d.nome ASC, p.nome ASC"; break;
    case 'data_desc':   $sqlMod1 .= " ORDER BY d.nome ASC, l.data_lancamento DESC"; break;
    case 'valor_desc':
    default:            $sqlMod1 .= " ORDER BY d.nome ASC, l.valor_total DESC"; break;
}

$stmtMod1 = $pdo->prepare($sqlMod1);
$stmtMod1->execute($paramsMod1);
$lancamentosMod1 = $stmtMod1->fetchAll();

$relatorioAgrupado = [];
$totalGeralPecasMod1 = 0;
$totalGeralValorMod1 = 0;

foreach ($lancamentosMod1 as $item) {
    $depNome = $item['departamento_nome'];
    if (!isset($relatorioAgrupado[$depNome])) {
        $relatorioAgrupado[$depNome] = [
            'itens' => [],
            'subtotal_pecas' => 0,
            'subtotal_valor' => 0
        ];
    }
    $relatorioAgrupado[$depNome]['itens'][] = $item;
    $relatorioAgrupado[$depNome]['subtotal_pecas'] += $item['quantidade'];
    $relatorioAgrupado[$depNome]['subtotal_valor'] += $item['valor_total'];
    $totalGeralPecasMod1 += $item['quantidade'];
    $totalGeralValorMod1 += $item['valor_total'];
}

// ==========================================================================
// CONSULTA MODELO 2: CONSOLIDADO POR PEÇAS (RANKING)
// ==========================================================================
$sqlMod2 = "
    SELECT COALESCE(p.id, l.produto_id) AS id, 
           COALESCE(p.nome, l.produto_nome_snapshot, 'Peça Removida') AS nome,
           SUM(l.quantidade) AS total_pecas,
           SUM(l.valor_total) AS total_valor,
           AVG(l.valor_unitario) AS preco_medio,
           COUNT(l.id) AS total_lancamentos,
           GROUP_CONCAT(DISTINCT COALESCE(d.nome, l.departamento_nome_snapshot, 'Setor Removido')) AS departamentos_nomes
    FROM lancamentos l
    LEFT JOIN produtos p ON p.id = l.produto_id
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    WHERE l.deleted_at IS NULL AND $whereData
";
$paramsMod2 = $paramsData;

if ($depFiltro > 0) {
    $sqlMod2 .= " AND l.departamento_id = ?";
    $paramsMod2[] = $depFiltro;
}
if ($prodFiltro > 0) {
    $sqlMod2 .= " AND l.produto_id = ?";
    $paramsMod2[] = $prodFiltro;
}

$sqlMod2 .= " GROUP BY COALESCE(p.id, l.produto_id), COALESCE(p.nome, l.produto_nome_snapshot)";

switch ($ordemFiltro) {
    case 'valor_asc':   $sqlMod2 .= " ORDER BY total_valor ASC"; break;
    case 'pecas_desc':  $sqlMod2 .= " ORDER BY total_pecas DESC, total_valor DESC"; break;
    case 'nome_asc':    $sqlMod2 .= " ORDER BY nome ASC"; break;
    case 'valor_desc':
    default:            $sqlMod2 .= " ORDER BY total_valor DESC, total_pecas DESC"; break;
}

$stmtMod2 = $pdo->prepare($sqlMod2);
$stmtMod2->execute($paramsMod2);
$pecasConsolidadas = $stmtMod2->fetchAll();

$totalPecasMod2 = array_sum(array_column($pecasConsolidadas, 'total_pecas'));
$totalValorMod2 = array_sum(array_column($pecasConsolidadas, 'total_valor'));

// ==========================================================================
// CONSULTA MODELO 3: RESUMO POR CENTRO DE CUSTO (DEPARTAMENTO)
// ==========================================================================
$sqlMod3 = "
    SELECT d.id, d.nome,
           COUNT(l.id) AS total_lancamentos,
           COALESCE(SUM(l.quantidade), 0) AS total_pecas,
           COALESCE(SUM(l.valor_total), 0) AS total_valor,
           COUNT(DISTINCT l.produto_id) AS variedade_pecas
    FROM departamentos d
    LEFT JOIN lancamentos l ON l.departamento_id = d.id 
         AND l.deleted_at IS NULL 
         AND $whereData
";
$paramsMod3 = $paramsData;

if ($prodFiltro > 0) {
    $sqlMod3 .= " AND l.produto_id = ?";
    $paramsMod3[] = $prodFiltro;
}

$sqlMod3 .= " WHERE d.deleted_at IS NULL";

if ($depFiltro > 0) {
    $sqlMod3 .= " AND d.id = ?";
    $paramsMod3[] = $depFiltro;
}

$sqlMod3 .= " GROUP BY d.id, d.nome";

switch ($ordemFiltro) {
    case 'valor_asc':   $sqlMod3 .= " ORDER BY total_valor ASC"; break;
    case 'pecas_desc':  $sqlMod3 .= " ORDER BY total_pecas DESC, total_valor DESC"; break;
    case 'nome_asc':    $sqlMod3 .= " ORDER BY d.nome ASC"; break;
    case 'valor_desc':
    default:            $sqlMod3 .= " ORDER BY total_valor DESC, total_pecas DESC"; break;
}

$stmtMod3 = $pdo->prepare($sqlMod3);
$stmtMod3->execute($paramsMod3);
$departamentosResumo = $stmtMod3->fetchAll();

// Exibe apenas os centros de custo que tiveram movimento no período/filtros atuais
$totalDepartamentosMod3 = count($departamentosResumo);
$departamentosResumo = array_values(array_filter(
    $departamentosResumo,
    static function (array $d): bool {
        return (int)$d['total_lancamentos'] > 0
            || (int)$d['total_pecas'] > 0
            || (float)$d['total_valor'] > 0;
    }
));

$totalPecasMod3 = array_sum(array_column($departamentosResumo, 'total_pecas'));
$totalValorMod3 = array_sum(array_column($departamentosResumo, 'total_valor'));
$depsAtivosPeriodo = count($departamentosResumo);

// ==========================================================================
// EXPORTAÇÃO CSV
// ==========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!usuarioTemPermissao('relatorios_exportar')) {
        http_response_code(403);
        exit('Acesso negado: você não tem permissão para exportar relatórios.');
    }
    $periodoSufixo = 'todos';
    if ($tipoPeriodo === 'mes') {
        $periodoSufixo = $mesSel;
    } elseif ($tipoPeriodo === 'dia') {
        $periodoSufixo = $dataSel;
    } elseif ($tipoPeriodo === 'intervalo') {
        $periodoSufixo = $dataInicio . '_' . $dataFim;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=relatorio_' . $modelo . '_' . $periodoSufixo . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    if ($modelo === 'departamento_detalhado') {
        fputcsv($output, ['Departamento', 'Peça', 'Data', 'Quantidade', 'Valor Unitário (R$)', 'Total (R$)']);
        foreach ($relatorioAgrupado as $dep => $dados) {
            foreach ($dados['itens'] as $it) {
                fputcsv($output, [
                    $dep,
                    $it['produto_nome'],
                    date('d/m/Y', strtotime($it['data_lancamento'])),
                    $it['quantidade'],
                    number_format($it['valor_unitario'], 2, ',', '.'),
                    number_format($it['valor_total'], 2, ',', '.')
                ]);
            }
        }
        fputcsv($output, ['TOTAL GERAL', '', '', $totalGeralPecasMod1, '', number_format($totalGeralValorMod1, 2, ',', '.')]);
    } elseif ($modelo === 'pecas_consolidado') {
        fputcsv($output, ['Ranking', 'Peça / Produto', 'Total Peças', 'Preço Médio (R$)', 'Valor Total (R$)', '% Total']);
        $rk = 1;
        foreach ($pecasConsolidadas as $p) {
            $perc = $totalValorMod2 > 0 ? ($p['total_valor'] / $totalValorMod2) * 100 : 0;
            fputcsv($output, [
                $rk++,
                $p['nome'],
                $p['total_pecas'],
                number_format($p['preco_medio'], 2, ',', '.'),
                number_format($p['total_valor'], 2, ',', '.'),
                number_format($perc, 1, ',', '.') . '%'
            ]);
        }
        fputcsv($output, ['TOTAL GERAL', '', $totalPecasMod2, '', number_format($totalValorMod2, 2, ',', '.'), '100%']);
    } else {
        fputcsv($output, ['Departamento (Centro de Custo)', 'Lançamentos', 'Variedade Peças', 'Total Peças', 'Custo Total (R$)', 'Ticket Médio/Peça (R$)', '% Custo Global']);
        foreach ($departamentosResumo as $d) {
            $ticketMedio = $d['total_pecas'] > 0 ? $d['total_valor'] / $d['total_pecas'] : 0;
            $perc = $totalValorMod3 > 0 ? ($d['total_valor'] / $totalValorMod3) * 100 : 0;
            fputcsv($output, [
                $d['nome'],
                $d['total_lancamentos'],
                $d['variedade_pecas'],
                $d['total_pecas'],
                number_format($d['total_valor'], 2, ',', '.'),
                number_format($ticketMedio, 2, ',', '.'),
                number_format($perc, 1, ',', '.') . '%'
            ]);
        }
        fputcsv($output, ['TOTAL GERAL', '', '', $totalPecasMod3, number_format($totalValorMod3, 2, ',', '.'), '', '100%']);
    }

    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Estilos Específicos para Relatório Compacto & Impressão Formal -->
<style>
/* Layout Executivo Compacto */
.report-compact-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 12px;
}
.report-tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    margin-bottom: 16px;
    background: #eef2f7;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow-x: auto;
}
.report-tab-btn {
    padding: 9px 14px;
    font-size: 0.83rem;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-radius: 8px;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.report-tab-btn:hover {
    color: #1e293b;
    background: rgba(255, 255, 255, 0.65);
}
.report-tab-btn.active {
    color: #1d4ed8;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
}

/* Painel de Filtros */
.report-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    position: relative;
    z-index: 30;
}
.report-filter-form {
    display: grid;
    grid-template-columns: minmax(240px, 1.25fr) minmax(180px, 1fr) minmax(180px, 1fr) minmax(170px, 0.9fr) auto;
    gap: 12px;
    align-items: end;
}
.report-filter-field { min-width: 0; }
.filter-label-compact {
    font-size: 0.69rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 6px;
    display: block;
    letter-spacing: 0.05em;
}
.report-filter-field .form-control {
    height: 42px;
    padding: 8px 9px;
    font-size: 0.86rem;
    border-radius: 10px;
}
.report-clear-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 42px;
    padding: 0 14px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    color: #64748b;
    background: #ffffff;
    text-decoration: none;
    font-size: 0.82rem;
    font-weight: 700;
    white-space: nowrap;
    justify-self: start;
    transition: all 0.15s ease;
}
.report-clear-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}

/* Atalhos de mês */
.report-month-shortcuts {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid #f1f5f9;
}
.report-month-shortcuts-label {
    font-size: 0.69rem;
    color: #64748b;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-right: 2px;
}
.report-month-chip {
    display: inline-flex;
    align-items: center;
    padding: 3px 7px;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #f8fafc;
    color: #475569;
    font-size: 0.72rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.report-month-chip:hover {
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
}
.report-month-chip.active {
    border-color: #2563eb;
    background: #2563eb;
    color: #ffffff;
}

/* Calendário do período */
.cal-wrap { position: relative; }
.cal-trigger {
    width: 100%;
    height: 42px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s ease;
}
.cal-trigger:hover { border-color: #93c5fd; }
.cal-trigger.active {
    border-color: #2563eb;
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
    font-size: 0.85rem;
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
    width: 336px;
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
.cal-mode-switch {
    display: flex;
    gap: 3px;
    background: #f1f5f9;
    border-radius: 8px;
    padding: 3px;
    margin-bottom: 10px;
}
.cal-mode-btn {
    flex: 1;
    border: none;
    background: transparent;
    border-radius: 6px;
    padding: 5px 8px;
    font-size: 0.71rem;
    font-weight: 800;
    color: #64748b;
    cursor: pointer;
    transition: all 0.15s ease;
}
.cal-mode-btn.active {
    background: #ffffff;
    color: #1d4ed8;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
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
    transition: all 0.15s ease;
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
.cal-day.selecionado,
.cal-day.intervalo-inicio,
.cal-day.intervalo-fim {
    background: #2563eb;
    color: #ffffff;
    font-weight: 800;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.32);
}
.cal-day.selecionado:hover,
.cal-day.intervalo-inicio:hover,
.cal-day.intervalo-fim:hover {
    background: #1d4ed8;
    color: #ffffff;
}
.cal-day.em-intervalo {
    background: #dbeafe;
    color: #1d4ed8;
    font-weight: 700;
}
.cal-dot {
    position: absolute;
    bottom: 4px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #22c55e;
}
.cal-day.selecionado .cal-dot,
.cal-day.intervalo-inicio .cal-dot,
.cal-day.intervalo-fim .cal-dot { background: #ffffff; }
.cal-range-info {
    margin-top: 8px;
    padding: 6px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    color: #475569;
    font-size: 0.71rem;
    font-weight: 600;
    text-align: center;
}
.cal-range-info[hidden] { display: none !important; }
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
.cal-foot-actions[hidden] { display: none !important; }
.cal-foot-actions .btn { font-size: 0.72rem; padding: 5px 9px; }
.cal-foot-actions .btn:disabled { opacity: 0.45; cursor: not-allowed; }

@media (max-width: 1280px) {
    .report-filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .report-filter-field.period { grid-column: 1 / -1; }
}
@media (max-width: 700px) {
    .report-filter-form { grid-template-columns: 1fr; }
    .report-filter-field.period { grid-column: auto; }
    .report-clear-btn { width: 100%; justify-self: stretch; }
}

/* KPIs Executivos */
.kpi-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}
.kpi-strip-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.kpi-strip-item:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
.kpi-strip-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kpi-strip-title {
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #64748b;
    line-height: 1.2;
    letter-spacing: 0.03em;
}
.kpi-strip-value {
    font-size: 1.35rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    margin-top: 2px;
}

/* Tabela Profissional Compacta */
.table-compact-report {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.table-compact-report th {
    background: #1e293b;
    color: #f8fafc;
    font-weight: 700;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 9px 12px;
    border: 1px solid #334155;
}
.table-compact-report td {
    padding: 7px 12px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
}
.table-compact-report tbody tr:hover {
    background: #f8fafc;
}
.table-compact-report .row-highlight {
    background: #f1f5f9;
    font-weight: 700;
}

.card-report {
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
    border-radius: 12px;
    overflow: hidden;
}

/* Bloco de Assinaturas Institucional */
.signature-box {
    margin-top: 28px;
    padding-top: 20px;
    border-top: 2px dashed #cbd5e1;
}
.signature-grid {
    display: grid;
    gap: 32px;
    margin-top: 15px;
    transition: all 0.2s ease;
}
.signature-item {
    text-align: center;
}
.signature-line {
    border-bottom: 1.5px solid #475569;
    height: 1px;
    margin-bottom: 8px;
}
.signature-title {
    font-weight: 700;
    font-size: 0.84rem;
    color: #0f172a;
    outline: none;
    min-height: 1.2em;
}
.signature-title[contenteditable="true"]:hover,
.signature-subtitle[contenteditable="true"]:hover {
    background: #f1f5f9;
    border-radius: 3px;
    cursor: text;
}
.signature-subtitle {
    font-size: 0.74rem;
    color: #64748b;
    outline: none;
}
.signature-date {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 4px;
}

/* Regras de Página e Impressão (@page & @media print) */
@page {
    size: auto;
    margin: 10mm 12mm 12mm 12mm;
}
@media print {
    *, *:before, *:after {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    .no-print, .sidebar, .topbar-mobile, .page-header, .report-tabs, .report-filter-card, .report-compact-header {
        display: none !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 9.5pt !important;
    }
    .print-header {
        display: block !important;
        text-align: center;
        border-bottom: 2px solid #0f172a;
        padding-bottom: 12px;
        margin-bottom: 16px;
    }
    .card, .card-report, .card-body, .table-responsive {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        overflow: visible !important;
        display: block !important;
    }
    table, .table-compact-report {
        width: 100% !important;
        border-collapse: separate !important;
        border-spacing: 0 !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
    }
    thead {
        display: table-header-group !important;
    }
    tfoot {
        display: table-footer-group !important;
    }
    tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    td, th {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        padding: 6px 8px !important;
        font-size: 8.5pt !important;
    }
    .table-compact-report th {
        background: #f1f5f9 !important;
        color: #000000 !important;
        border: 1px solid #94a3b8 !important;
    }
    .table-compact-report td {
        border-bottom: 1px solid #cbd5e1 !important;
        border-right: 1px solid #e2e8f0 !important;
    }
    .table-compact-report td:first-child {
        border-left: 1px solid #cbd5e1 !important;
    }
    .table-compact-report tr[style*="border-top: 2px solid"] {
        page-break-after: avoid !important;
        break-after: avoid !important;
    }
    .signature-box {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        margin-top: 25px !important;
        padding-top: 15px !important;
        border-top: 1.5px dashed #475569 !important;
    }
    .kpi-strip {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        margin-bottom: 12px !important;
    }
}

@media print {
    /* No tema escuro, a impressão continua em papel branco */
    [data-theme="dark"] .card,
    [data-theme="dark"] .card-report,
    [data-theme="dark"] .card-body,
    [data-theme="dark"] .table-responsive,
    [data-theme="dark"] .card-header,
    [data-theme="dark"] .kpi-strip-item,
    [data-theme="dark"] .signature-box {
        background: #ffffff !important;
    }

    [data-theme="dark"] .card-header *,
    [data-theme="dark"] .table-compact-report td,
    [data-theme="dark"] .table-compact-report td *,
    [data-theme="dark"] .kpi-strip-item,
    [data-theme="dark"] .kpi-strip-item *,
    [data-theme="dark"] .signature-box,
    [data-theme="dark"] .signature-box * {
        color: #000000 !important;
        background: transparent !important;
    }

    [data-theme="dark"] .kpi-strip-icon {
        background: #f1f5f9 !important;
        color: #000000 !important;
    }

    [data-theme="dark"] .table-compact-report th {
        background: #f1f5f9 !important;
        color: #000000 !important;
    }

    [data-theme="dark"] .table-compact-report tr.row-highlight,
    [data-theme="dark"] .table-compact-report tr[style*="background"] {
        background: #f1f5f9 !important;
    }
}

@media screen {
    .print-header {
        display: none;
    }
}
</style>

<!-- Cabeçalho Timbrado Exclusivo para Impressão -->
<?php 
$sysConfigRel = obterConfiguracoes();
$temLogoSysRel = storedImageExists($sysConfigRel['sistema_logo'] ?? '');
?>
<div class="print-header">
    <div style="display: flex; align-items: center; justify-content: center; gap: 14px; margin-bottom: 6px;">
        <?php if ($temLogoSysRel): ?>
            <img src="<?= htmlspecialchars(storedImageSrc($sysConfigRel['sistema_logo']), ENT_QUOTES, 'UTF-8') ?>" style="width: 44px; height: 44px; object-fit: contain; border-radius: 8px;" alt="Logo">
        <?php endif; ?>
        <div style="text-align: center;">
            <div style="font-size: 15pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #0f172a; line-height: 1.1;">
                <?= htmlspecialchars(mb_strtoupper($sysConfigRel['sistema_nome'])) ?>
            </div>
            <?php if (!empty($sysConfigRel['sistema_subtitulo'])): ?>
                <div style="font-size: 8.5pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px;">
                    <?= htmlspecialchars($sysConfigRel['sistema_subtitulo']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div style="font-size: 11pt; font-weight: 700; margin-top: 3px; color: #1e293b;">
        <?php 
        if ($modelo === 'departamento_detalhado') echo "RELATÓRIO OPERACIONAL DETALHADO POR DEPARTAMENTO";
        elseif ($modelo === 'pecas_consolidado') echo "RELATÓRIO CONSOLIDADO DE PEÇAS E VOLUMES";
        else echo "DEMONSTRATIVO DE CUSTOS POR CENTRO DE CUSTO / DEPARTAMENTO";
        ?>
    </div>
    <div style="font-size: 8.5pt; color: #475569; margin-top: 4px;">
        Período de Referência: <strong><?= htmlspecialchars($periodoLabel) ?></strong> &bull; 
        Emissão: <?= date('d/m/Y H:i') ?> &bull; Operador: <?= htmlspecialchars($currentUser['nome']) ?>
    </div>
</div>

<!-- Cabeçalho da Página (Tela) -->
<div class="report-compact-header no-print">
    <div class="page-title-group">
        <h1 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin-bottom: 2px;">
            Relatórios - <?= htmlspecialchars($sysConfigRel['sistema_nome']) ?>
        </h1>
        <p style="font-size: 0.85rem; color: #64748b; margin: 0;">
            Demonstrativos operacionais, prestação de contas e custos rateados por centro de custo
        </p>
        <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 4px;">
            💡 <em>Dica: desmarque <strong>"Cabeçalhos e rodapés"</strong> na impressão para remover URL e data do navegador.</em>
        </div>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <!-- Seletor de Quantidade de Assinaturas -->
        <div style="display: inline-flex; align-items: center; gap: 6px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 3px 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
            <label for="select_qtd_assinaturas" style="font-size: 0.78rem; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">
                ✍️ Assinaturas:
            </label>
            <select id="select_qtd_assinaturas" class="form-control" style="font-size: 0.8rem; padding: 2px 8px; height: 30px; width: auto; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 600; color: #0f172a; cursor: pointer;" onchange="alterarQtdAssinaturas(this.value)">
                <option value="2" <?= $qtdAssinaturas === 2 ? 'selected' : '' ?>>2 Assinaturas (Padrão)</option>
                <option value="1" <?= $qtdAssinaturas === 1 ? 'selected' : '' ?>>1 Assinatura (Lavanderia)</option>
                <option value="3" <?= $qtdAssinaturas === 3 ? 'selected' : '' ?>>3 Assinaturas (+ Supervisão)</option>
                <option value="0" <?= $qtdAssinaturas === 0 ? 'selected' : '' ?>>0 (Sem Assinaturas)</option>
            </select>
        </div>

        <?php if (usuarioTemPermissao('relatorios_exportar')): ?>
            <?php 
            $queryExport = $_GET;
            $queryExport['export'] = 'csv';
            $queryExport['qtd_assinaturas'] = $qtdAssinaturas;
            ?>
            <a href="?<?= htmlspecialchars(http_build_query($queryExport)) ?>" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                <span>📥</span> Exportar CSV
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                <span>🖨️</span> Imprimir / PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Seletor de Modelos em Abas Compactas -->
<div class="report-tabs no-print">
    <a href="<?= htmlspecialchars($linkFiltros(['modelo' => 'resumo_departamentos'])) ?>"
       class="report-tab-btn <?= $modelo === 'resumo_departamentos' ? 'active' : '' ?>">
        📊 1. Resumo por Centro de Custo
    </a>
    <a href="<?= htmlspecialchars($linkFiltros(['modelo' => 'departamento_detalhado'])) ?>"
       class="report-tab-btn <?= $modelo === 'departamento_detalhado' ? 'active' : '' ?>">
        🏢 2. Geral por Departamento
    </a>
    <a href="<?= htmlspecialchars($linkFiltros(['modelo' => 'pecas_consolidado'])) ?>"
       class="report-tab-btn <?= $modelo === 'pecas_consolidado' ? 'active' : '' ?>">
        🧺 3. Consolidado por Peças
    </a>
</div>

<!-- Painel de Filtros -->
<div class="report-filter-card no-print">
    <form method="GET" action="relatorios.php" id="formFiltrosRelatorio" class="report-filter-form">
        <input type="hidden" name="modelo" value="<?= htmlspecialchars($modelo) ?>">
        <input type="hidden" name="qtd_assinaturas" id="hidden_filtro_qtd_assinaturas" value="<?= $qtdAssinaturas ?>">
        <input type="hidden" name="tipo_periodo" id="rel_filtro_tipo" value="<?= htmlspecialchars($tipoPeriodo) ?>">
        <input type="hidden" name="mes" id="rel_filtro_mes" value="<?= htmlspecialchars($mesSel) ?>">
        <input type="hidden" name="data" id="rel_filtro_data" value="<?= htmlspecialchars($dataSel) ?>">
        <input type="hidden" name="data_inicio" id="rel_filtro_inicio" value="<?= htmlspecialchars($dataInicio) ?>">
        <input type="hidden" name="data_fim" id="rel_filtro_fim" value="<?= htmlspecialchars($dataFim) ?>">

        <div class="report-filter-field period">
            <label class="filter-label-compact" for="relCalTrigger">Período</label>
            <div class="cal-wrap">
                <button type="button" class="cal-trigger" id="relCalTrigger"
                        title="<?= htmlspecialchars($periodoLabel) ?>"
                        aria-haspopup="dialog" aria-expanded="false" aria-controls="relCalPopover">
                    <span class="cal-trigger-icon">📅</span>
                    <span class="cal-trigger-text">
                        <strong id="relCalTriggerText"><?= htmlspecialchars($periodoLabelCurto) ?></strong>
                        <small id="relCalTriggerHint"><?= htmlspecialchars($periodoHint) ?></small>
                    </span>
                    <span class="cal-trigger-caret">▼</span>
                </button>

                <div class="cal-popover" id="relCalPopover" role="dialog" aria-label="Selecionar período" hidden>
                    <div class="cal-mode-switch" role="tablist" aria-label="Modo de seleção">
                        <button type="button" class="cal-mode-btn <?= $tipoPeriodo !== 'intervalo' ? 'active' : '' ?>"
                                data-mode="single" role="tab"
                                aria-selected="<?= $tipoPeriodo !== 'intervalo' ? 'true' : 'false' ?>">
                            Dia / Mês
                        </button>
                        <button type="button" class="cal-mode-btn <?= $tipoPeriodo === 'intervalo' ? 'active' : '' ?>"
                                data-mode="range" role="tab"
                                aria-selected="<?= $tipoPeriodo === 'intervalo' ? 'true' : 'false' ?>">
                            Intervalo
                        </button>
                    </div>

                    <div class="cal-head">
                        <button type="button" class="cal-nav" id="relCalPrev" aria-label="Mês anterior">‹</button>
                        <div class="cal-title" id="relCalTitle"><?= htmlspecialchars($labelMesCalendario) ?></div>
                        <button type="button" class="cal-nav" id="relCalNext" aria-label="Próximo mês">›</button>
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

                    <div class="cal-grid" id="relCalGrid"></div>
                    <div class="cal-range-info" id="relCalRangeInfo" hidden></div>

                    <div class="cal-foot">
                        <button type="button" class="cal-foot-link" id="relCalTodoPeriodo">Todo o período</button>

                        <div class="cal-foot-actions" id="relCalSingleActions" <?= $tipoPeriodo === 'intervalo' ? 'hidden' : '' ?>>
                            <button type="button" class="btn btn-outline btn-sm" id="relCalHoje">Hoje</button>
                            <button type="button" class="btn btn-primary btn-sm" id="relCalMesInteiro">Mês inteiro</button>
                        </div>

                        <div class="cal-foot-actions" id="relCalRangeActions" <?= $tipoPeriodo === 'intervalo' ? '' : 'hidden' ?>>
                            <button type="button" class="btn btn-outline btn-sm" id="relCalLimparIntervalo">Limpar seleção</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="report-filter-field">
            <label class="filter-label-compact" for="rel_filtro_dep">Departamento</label>
            <select name="departamento_id" id="rel_filtro_dep" class="form-control">
                <option value="0">Todos os Departamentos</option>
                <?php foreach ($todosDepartamentos as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $depFiltro ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="report-filter-field">
            <label class="filter-label-compact" for="rel_filtro_prod">Peça / Produto</label>
            <select name="produto_id" id="rel_filtro_prod" class="form-control">
                <option value="0">Todas as Peças</option>
                <?php foreach ($todosProdutos as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $prodFiltro ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="report-filter-field">
            <label class="filter-label-compact" for="rel_filtro_ordem">Classificar por</label>
            <select name="ordem" id="rel_filtro_ordem" class="form-control">
                <option value="valor_desc" <?= $ordemFiltro === 'valor_desc' ? 'selected' : '' ?>>Maior Valor (R$)</option>
                <option value="pecas_desc" <?= $ordemFiltro === 'pecas_desc' ? 'selected' : '' ?>>Maior Qtd. Peças</option>
                <option value="nome_asc" <?= $ordemFiltro === 'nome_asc' ? 'selected' : '' ?>>Ordem Alfabética (A-Z)</option>
                <option value="valor_asc" <?= $ordemFiltro === 'valor_asc' ? 'selected' : '' ?>>Menor Valor (R$)</option>
            </select>
        </div>

        <a href="?modelo=<?= urlencode($modelo) ?>" class="report-clear-btn" title="Limpar filtros e voltar ao mês padrão">
            ✕ Limpar
        </a>
    </form>

    <div class="report-month-shortcuts">
        <span class="report-month-shortcuts-label">Ir para o mês de <?= htmlspecialchars($anoAtalhos) ?>:</span>
        <?php foreach ($mesesPt as $numMes => $nomeMes):
            $ym = $anoAtalhos . '-' . $numMes;
            $ativo = ($tipoPeriodo === 'mes' && $ym === $mesSel);
        ?>
            <a href="<?= htmlspecialchars($linkFiltros([
                'tipo_periodo' => 'mes',
                'mes' => $ym,
                'data' => '',
                'data_inicio' => '',
                'data_fim' => '',
            ])) ?>"
               class="report-month-chip <?= $ativo ? 'active' : '' ?>"
               title="Ir para <?= htmlspecialchars($nomeMes) ?> de <?= htmlspecialchars($anoAtalhos) ?>"
               <?= $ativo ? 'aria-current="page"' : '' ?>>
                <?= htmlspecialchars($nomeMes) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ========================================================================
     MODELO 3: RESUMO POR CENTRO DE CUSTO (PADRÃO / CONTRATUAL)
     ======================================================================== -->
<?php if ($modelo === 'resumo_departamentos'): ?>
    <!-- Métricas Executivas Compactas -->
    <div class="kpi-strip">
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #eff6ff; color: #2563eb;">🏢</div>
            <div>
                <div class="kpi-strip-title">Departamentos Ativos</div>
                <div class="kpi-strip-value"><?= $depsAtivosPeriodo ?> <span style="font-size: 0.8rem; font-weight: normal; color: #64748b;">/ <?= $totalDepartamentosMod3 ?></span></div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #fef3c7; color: #d97706;">🧺</div>
            <div>
                <div class="kpi-strip-title">Volume de Peças</div>
                <div class="kpi-strip-value"><?= number_format($totalPecasMod3, 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #ecfdf5; color: #059669;">💰</div>
            <div>
                <div class="kpi-strip-title">Custo Global Total</div>
                <div class="kpi-strip-value" style="color: #047857;">R$ <?= number_format($totalValorMod3, 2, ',', '.') ?></div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #f5f3ff; color: #7c3aed;">📊</div>
            <div>
                <div class="kpi-strip-title">Média por Centro de Custo</div>
                <div class="kpi-strip-value">R$ <?= $depsAtivosPeriodo > 0 ? number_format($totalValorMod3 / $depsAtivosPeriodo, 2, ',', '.') : '0,00' ?></div>
            </div>
        </div>
    </div>

    <!-- Tabela Executiva de Centros de Custo -->
    <div class="card card-report">
        <div style="background: #0f172a; color: #f8fafc; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <div style="font-size: 0.92rem; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase;">
                    DEMONSTRATIVO DE CENTROS DE CUSTO &bull; <?= htmlspecialchars($periodoLabel) ?>
                </div>
                <div style="font-size: 0.74rem; color: #94a3b8;">
                    Quadro analítico consolidado para prestação de contas, auditoria interna e rateio por departamento
                </div>
            </div>
            <span style="font-size: 0.74rem; background: #334155; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                <?= count($departamentosResumo) ?> <?= count($departamentosResumo) === 1 ? 'setor com movimento' : 'setores com movimento' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table-compact-report">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">#</th>
                        <th>Departamento (Centro de Custo)</th>
                        <th style="width: 110px; text-align: center;">Lançamentos</th>
                        <th style="width: 120px; text-align: center;">Variedade</th>
                        <th style="width: 120px; text-align: center;">Total Peças</th>
                        <th style="width: 140px; text-align: right;">Custo Total (R$)</th>
                        <th style="width: 130px; text-align: right;">Méd/Peça</th>
                        <th style="width: 170px;">% do Total Global</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departamentosResumo)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhum centro de custo com movimento no período selecionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $contador = 1;
                        foreach ($departamentosResumo as $d): 
                            $percentual = $totalValorMod3 > 0 ? ($d['total_valor'] / $totalValorMod3) * 100 : 0;
                            $ticketMedio = $d['total_pecas'] > 0 ? $d['total_valor'] / $d['total_pecas'] : 0;
                        ?>
                            <tr>
                                <td style="text-align: center; font-size: 0.75rem; color: #94a3b8;"><?= $contador++ ?></td>
                                <td>
                                    <strong style="color: #0f172a; font-size: 0.88rem;"><?= htmlspecialchars($d['nome']) ?></strong>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?= $d['total_lancamentos'] ?></td>
                                <td style="text-align: center; color: #64748b;"><?= $d['variedade_pecas'] ?> <?= (int)$d['variedade_pecas'] === 1 ? 'peça' : 'peças' ?></td>
                                <td style="text-align: center; font-weight: 700; color: #0f172a;">
                                    <?= number_format($d['total_pecas'], 0, ',', '.') ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #047857; font-size: 0.9rem;">
                                    R$ <?= number_format($d['total_valor'], 2, ',', '.') ?>
                                </td>
                                <td style="text-align: right; color: #64748b; font-size: 0.82rem;">
                                    R$ <?= number_format($ticketMedio, 2, ',', '.') ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                            <div style="width: <?= min(100, $percentual) ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #1d4ed8);"></div>
                                        </div>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #334155; width: 44px; text-align: right;">
                                            <?= number_format($percentual, 1, ',', '.') ?>%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Linha de Total Geral -->
                        <tr class="row-highlight" style="background: #f8fafc; border-top: 2px solid #0f172a;">
                            <td colspan="2" style="font-weight: 800; text-transform: uppercase; color: #0f172a; font-size: 0.88rem;">
                                &bull; TOTAL GERAL CONSOLIDADO
                            </td>
                            <td style="text-align: center; font-weight: 800;"><?= array_sum(array_column($departamentosResumo, 'total_lancamentos')) ?></td>
                            <td></td>
                            <td style="text-align: center; font-weight: 800; font-size: 0.95rem; color: #0f172a;">
                                <?= number_format($totalPecasMod3, 0, ',', '.') ?> peças
                            </td>
                            <td style="text-align: right; font-weight: 900; font-size: 1.05rem; color: #047857;">
                                R$ <?= number_format($totalValorMod3, 2, ',', '.') ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #475569;">
                                R$ <?= $totalPecasMod3 > 0 ? number_format($totalValorMod3 / $totalPecasMod3, 2, ',', '.') : '0,00' ?>
                            </td>
                            <td style="font-weight: 800; color: #0f172a;">100.0%</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ========================================================================
     MODELO 1: DETALHADO POR DEPARTAMENTO (EXTRATO DE PEÇAS)
     ======================================================================== -->
<?php elseif ($modelo === 'departamento_detalhado'): ?>
    <div class="kpi-strip">
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #eff6ff; color: #2563eb;">🏢</div>
            <div>
                <div class="kpi-strip-title">Departamentos Atendidos</div>
                <div class="kpi-strip-value"><?= count($relatorioAgrupado) ?></div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #fef3c7; color: #d97706;">🧺</div>
            <div>
                <div class="kpi-strip-title">Total Geral de Peças</div>
                <div class="kpi-strip-value"><?= number_format($totalGeralPecasMod1, 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #ecfdf5; color: #059669;">💰</div>
            <div>
                <div class="kpi-strip-title">Valor Total Geral</div>
                <div class="kpi-strip-value" style="color: #047857;">R$ <?= number_format($totalGeralValorMod1, 2, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <div class="card card-report">
        <div style="background: #0f172a; color: #f8fafc; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <div style="font-size: 0.92rem; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase;">
                    EXTRATO DETALHADO POR DEPARTAMENTO &bull; <?= htmlspecialchars($periodoLabel) ?>
                </div>
                <div style="font-size: 0.74rem; color: #94a3b8;">
                    Relação item por item com quantidades, datas e valores unitários
                </div>
            </div>
            <span style="font-size: 0.74rem; background: #334155; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                <?= count($lancamentosMod1) ?> <?= count($lancamentosMod1) === 1 ? 'lançamento registrado' : 'lançamentos registrados' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table-compact-report">
                <thead>
                    <tr>
                        <th>Departamento / Peça</th>
                        <th style="width: 110px; text-align: center;">Data</th>
                        <th style="width: 100px; text-align: center;">Qtde</th>
                        <th style="width: 130px; text-align: right;">Unitário (R$)</th>
                        <th style="width: 140px; text-align: right;">Subtotal (R$)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($relatorioAgrupado)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhum lançamento encontrado para os filtros selecionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($relatorioAgrupado as $depNome => $dados): ?>
                            <!-- Cabeçalho do Grupo de Departamento -->
                            <tr style="background: #f1f5f9; border-top: 2px solid #cbd5e1;">
                                <td colspan="3" style="padding: 8px 12px; font-weight: 800; color: #0f172a; font-size: 0.88rem;">
                                    🏢 <?= htmlspecialchars($depNome) ?>
                                </td>
                                <td style="text-align: right; font-weight: 800; color: #475569; font-size: 0.8rem;">
                                    Subtotal (<?= $dados['subtotal_pecas'] ?> <?= (int)$dados['subtotal_pecas'] === 1 ? 'peça' : 'peças' ?>):
                                </td>
                                <td style="text-align: right; font-weight: 800; color: #047857; font-size: 0.9rem;">
                                    R$ <?= number_format($dados['subtotal_valor'], 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php foreach ($dados['itens'] as $it): ?>
                                <tr>
                                    <td style="padding-left: 28px; color: #334155;">
                                        &bull; <?= htmlspecialchars($it['produto_nome']) ?>
                                    </td>
                                    <td style="text-align: center; color: #64748b; font-size: 0.8rem;">
                                        <?= date('d/m/Y', strtotime($it['data_lancamento'])) ?>
                                    </td>
                                    <td style="text-align: center; font-weight: 600; color: #0f172a;">
                                        <?= $it['quantidade'] ?>
                                    </td>
                                    <td style="text-align: right; color: #64748b;">
                                        R$ <?= number_format($it['valor_unitario'], 2, ',', '.') ?>
                                    </td>
                                    <td style="text-align: right; font-weight: 600; color: #0f172a;">
                                        R$ <?= number_format($it['valor_total'], 2, ',', '.') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <!-- Total Geral -->
                        <tr class="row-highlight" style="border-top: 2px solid #0f172a; background: #f8fafc;">
                            <td colspan="2" style="font-weight: 900; color: #0f172a; text-transform: uppercase;">
                                &bull; TOTAL GERAL DETALHADO
                            </td>
                            <td style="text-align: center; font-weight: 900; font-size: 0.95rem; color: #0f172a;">
                                <?= number_format($totalGeralPecasMod1, 0, ',', '.') ?> peças
                            </td>
                            <td></td>
                            <td style="text-align: right; font-weight: 900; font-size: 1.05rem; color: #047857;">
                                R$ <?= number_format($totalGeralValorMod1, 2, ',', '.') ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ========================================================================
     MODELO 2: CONSOLIDADO POR PEÇAS (RANKING DE DEMANDA)
     ======================================================================== -->
<?php elseif ($modelo === 'pecas_consolidado'): ?>
    <div class="kpi-strip">
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #eff6ff; color: #2563eb;">🧺</div>
            <div>
                <div class="kpi-strip-title">Variedade de Peças</div>
                <div class="kpi-strip-value"><?= count($pecasConsolidadas) ?> tipos</div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #fef3c7; color: #d97706;">📦</div>
            <div>
                <div class="kpi-strip-title">Volume Total Lavado</div>
                <div class="kpi-strip-value"><?= number_format($totalPecasMod2, 0, ',', '.') ?> peças</div>
            </div>
        </div>
        <div class="kpi-strip-item">
            <div class="kpi-strip-icon" style="background: #ecfdf5; color: #059669;">💰</div>
            <div>
                <div class="kpi-strip-title">Faturamento Total Peças</div>
                <div class="kpi-strip-value" style="color: #047857;">R$ <?= number_format($totalValorMod2, 2, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <div class="card card-report">
        <div style="background: #0f172a; color: #f8fafc; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <div style="font-size: 0.92rem; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase;">
                    RANKING DE DEMANDA POR PEÇA &bull; <?= htmlspecialchars($periodoLabel) ?>
                </div>
                <div style="font-size: 0.74rem; color: #94a3b8;">
                    Estatística de produção, custo por tipo de roupa e principais setores demandantes
                </div>
            </div>
            <span style="font-size: 0.74rem; background: #334155; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                <?= count($pecasConsolidadas) ?> itens ranqueados
            </span>
        </div>

        <div class="table-responsive">
            <table class="table-compact-report">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">Pos.</th>
                        <th>Peça / Produto</th>
                        <th style="width: 120px; text-align: center;">Volume Peças</th>
                        <th style="width: 130px; text-align: right;">Preço Médio</th>
                        <th style="width: 140px; text-align: right;">Total (R$)</th>
                        <th style="width: 160px;">% do Volume</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pecasConsolidadas)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhuma peça registrada no período selecionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $pos = 1;
                        foreach ($pecasConsolidadas as $p): 
                            $percPecas = $totalPecasMod2 > 0 ? ($p['total_pecas'] / $totalPecasMod2) * 100 : 0;
                        ?>
                            <tr>
                                <td style="text-align: center; font-weight: 700; color: #64748b; font-size: 0.78rem;">
                                    #<?= $pos++ ?>
                                </td>
                                <td>
                                    <strong style="color: #0f172a; font-size: 0.88rem;"><?= htmlspecialchars($p['nome']) ?></strong>
                                    <?php if (!empty($p['departamentos_nomes'])): ?>
                                        <div style="font-size: 0.74rem; color: #64748b; margin-top: 1px;">
                                            Setores: <?= htmlspecialchars($p['departamentos_nomes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #0f172a;">
                                    <?= number_format($p['total_pecas'], 0, ',', '.') ?>
                                </td>
                                <td style="text-align: right; color: #64748b;">
                                    R$ <?= number_format($p['preco_medio'], 2, ',', '.') ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #047857;">
                                    R$ <?= number_format($p['total_valor'], 2, ',', '.') ?>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                            <div style="width: <?= min(100, $percPecas) ?>%; height: 100%; background: linear-gradient(90deg, #3b82f6, #1d4ed8);"></div>
                                        </div>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #334155; width: 44px; text-align: right;">
                                            <?= number_format($percPecas, 1, ',', '.') ?>%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Total Geral -->
                        <tr class="row-highlight" style="border-top: 2px solid #0f172a; background: #f8fafc;">
                            <td colspan="2" style="font-weight: 900; color: #0f172a; text-transform: uppercase;">
                                &bull; TOTAL GERAL CONSOLIDADO
                            </td>
                            <td style="text-align: center; font-weight: 900; font-size: 0.95rem; color: #0f172a;">
                                <?= number_format($totalPecasMod2, 0, ',', '.') ?> peças
                            </td>
                            <td></td>
                            <td style="text-align: right; font-weight: 900; font-size: 1.05rem; color: #047857;">
                                R$ <?= number_format($totalValorMod2, 2, ',', '.') ?>
                            </td>
                            <td style="font-weight: 800; color: #0f172a;">100.0%</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================
     BLOCO DE FORMALIZAÇÃO & LINHAS PARA ASSINATURA (TELA & IMPRESSÃO)
     ======================================================================== -->
<div class="signature-box" id="box_assinaturas" style="<?= $qtdAssinaturas === 0 ? 'display: none;' : '' ?>">
    <div class="signature-grid" id="grid_assinaturas" style="display: grid; gap: 32px; grid-template-columns: <?= $qtdAssinaturas === 1 ? '1fr' : ($qtdAssinaturas === 3 ? 'repeat(3, 1fr)' : 'repeat(2, 1fr)') ?>; max-width: <?= $qtdAssinaturas === 1 ? '380px' : '100%' ?>; margin: 15px auto 0 auto;">
        
        <!-- 1. Responsável Lavanderia -->
        <div class="signature-item" id="sig_item_1" style="<?= $qtdAssinaturas >= 1 ? '' : 'display: none;' ?>">
            <div class="signature-line"></div>
            <div class="signature-title" contenteditable="true" title="Clique para editar se desejar"></div>
            <div class="signature-subtitle" contenteditable="true"><?= htmlspecialchars($sysConfig['relatorio_assinatura_1_cargo'] ?? 'Responsável pela Lavanderia (Emissão & Conferência)') ?></div>
            <div class="signature-date">Data: ____/____/________</div>
        </div>

        <!-- 2. Gestor / Setor -->
        <div class="signature-item" id="sig_item_2" style="<?= $qtdAssinaturas >= 2 ? '' : 'display: none;' ?>">
            <div class="signature-line"></div>
            <div class="signature-title" contenteditable="true" title="Clique para editar se desejar"><?= htmlspecialchars($sysConfig['relatorio_assinatura_2_titulo'] ?? 'Gestor / Responsável pelo Setor') ?></div>
            <div class="signature-subtitle" contenteditable="true"><?= htmlspecialchars($sysConfig['relatorio_assinatura_2_cargo'] ?? 'Centro de Custo (De Acordo & Recebimento)') ?></div>
            <div class="signature-date">Data: ____/____/________</div>
        </div>

        <!-- 3. Supervisão Geral / Controladoria -->
        <div class="signature-item" id="sig_item_3" style="<?= $qtdAssinaturas >= 3 ? '' : 'display: none;' ?>">
            <div class="signature-line"></div>
            <div class="signature-title" contenteditable="true" title="Clique para editar se desejar"><?= htmlspecialchars($sysConfig['relatorio_assinatura_3_titulo'] ?? 'Supervisão Geral / Controladoria') ?></div>
            <div class="signature-subtitle" contenteditable="true"><?= htmlspecialchars($sysConfig['relatorio_assinatura_3_cargo'] ?? 'Visto de Auditoria Interna & Rateio') ?></div>
            <div class="signature-date">Data: ____/____/________</div>
        </div>
    </div>
</div>

<script>
// ==========================================================================
// Filtros automáticos + calendário do período (dia, mês ou intervalo)
// ==========================================================================
(function () {
    const form = document.getElementById('formFiltrosRelatorio');
    if (!form) return;

    const inputTipo = document.getElementById('rel_filtro_tipo');
    const inputMes = document.getElementById('rel_filtro_mes');
    const inputData = document.getElementById('rel_filtro_data');
    const inputInicio = document.getElementById('rel_filtro_inicio');
    const inputFim = document.getElementById('rel_filtro_fim');
    const selectDep = document.getElementById('rel_filtro_dep');
    const selectProd = document.getElementById('rel_filtro_prod');
    const selectOrdem = document.getElementById('rel_filtro_ordem');

    // Os selects aplicam o filtro automaticamente
    [selectDep, selectProd, selectOrdem].forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', function () {
            form.submit();
        });
    });

    const trigger = document.getElementById('relCalTrigger');
    const popover = document.getElementById('relCalPopover');
    if (!trigger || !popover) return;

    const grid = document.getElementById('relCalGrid');
    const calTitle = document.getElementById('relCalTitle');
    const rangeInfo = document.getElementById('relCalRangeInfo');
    const singleActions = document.getElementById('relCalSingleActions');
    const rangeActions = document.getElementById('relCalRangeActions');
    const modeButtons = Array.from(document.querySelectorAll('.cal-mode-btn'));

    const MESES = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'
    ];

    const estado = {
        mes: <?= json_encode($mesCalendario) ?>,
        modo: <?= json_encode($tipoPeriodo === 'intervalo' ? 'range' : 'single') ?>,
        selecionado: <?= json_encode($dataSel) ?>,
        inicio: <?= json_encode($dataInicio) ?>,
        fim: <?= json_encode($dataFim) ?>,
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

    function formatarDia(iso) {
        const partes = iso.split('-');
        return partes[2] + '/' + partes[1] + '/' + partes[0];
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
            let selecionado = false;

            if (iso === hojeISO) classes.push('hoje');

            if (estado.modo === 'range') {
                if (iso === estado.inicio) {
                    classes.push('intervalo-inicio');
                    selecionado = true;
                } else if (iso === estado.fim) {
                    classes.push('intervalo-fim');
                    selecionado = true;
                } else if (estado.inicio && estado.fim && iso > estado.inicio && iso < estado.fim) {
                    classes.push('em-intervalo');
                }
            } else if (iso === estado.selecionado) {
                classes.push('selecionado');
                selecionado = true;
            }

            let titulo = 'Sem lançamentos';
            if (info && info.qtd > 0) {
                const valor = Number(info.valor).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                const rotuloLancamentos = info.qtd === 1 ? ' lançamento' : ' lançamentos';
                const rotuloPecas = info.pecas === 1 ? ' peça' : ' peças';
                titulo = info.qtd + rotuloLancamentos + ' • ' + info.pecas + rotuloPecas + ' • R$ ' + valor;
            }

            html += '<button type="button" class="' + classes.join(' ') + '"'
                + ' data-data="' + iso + '"'
                + ' aria-label="' + dia + ' de ' + formatarMes(estado.mes) + '"'
                + ' aria-pressed="' + (selecionado ? 'true' : 'false') + '"'
                + ' title="' + titulo + '">'
                + '<span>' + dia + '</span>'
                + (info && info.qtd > 0 ? '<span class="cal-dot"></span>' : '')
                + '</button>';
        }

        grid.innerHTML = html;
    }

    function renderizarIntervalo() {
        if (!rangeInfo) return;

        if (!estado.inicio && !estado.fim) {
            rangeInfo.textContent = 'Clique na data inicial do intervalo.';
        } else if (estado.inicio && !estado.fim) {
            rangeInfo.textContent = 'Início: ' + formatarDia(estado.inicio) + ' — escolha a data final.';
        } else {
            rangeInfo.textContent = formatarDia(estado.inicio) + ' até ' + formatarDia(estado.fim);
        }
    }

    function atualizarModo() {
        modeButtons.forEach(function (botao) {
            const ativo = botao.getAttribute('data-mode') === estado.modo;
            botao.classList.toggle('active', ativo);
            botao.setAttribute('aria-selected', ativo ? 'true' : 'false');
        });

        const modoIntervalo = estado.modo === 'range';
        if (singleActions) singleActions.hidden = modoIntervalo;
        if (rangeActions) rangeActions.hidden = !modoIntervalo;
        if (rangeInfo) rangeInfo.hidden = !modoIntervalo;

        if (modoIntervalo) {
            renderizarIntervalo();
        }
    }

    async function carregarDiasDoMes(mes) {
        const params = new URLSearchParams({ ajax: 'dias', cal_mes: mes });
        if (selectDep && selectDep.value && selectDep.value !== '0') {
            params.set('departamento_id', selectDep.value);
        }
        if (selectProd && selectProd.value && selectProd.value !== '0') {
            params.set('produto_id', selectProd.value);
        }

        try {
            const resposta = await fetch('relatorios.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if (!resposta.ok) return;

            const dados = await resposta.json();
            if (dados && dados.dias && mes === estado.mes) {
                estado.dias = dados.dias;
                renderizarCalendario();
            }
        } catch (erro) {
            // Mantém o calendário sem os pontos em caso de falha
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
        popover.hidden = false;
        trigger.classList.add('active');
        trigger.setAttribute('aria-expanded', 'true');
        renderizarCalendario();
        atualizarModo();
    }

    function fecharCalendario() {
        popover.hidden = true;
        trigger.classList.remove('active');
        trigger.setAttribute('aria-expanded', 'false');
    }

    function aplicarMes(mes) {
        inputTipo.value = 'mes';
        inputMes.value = mes;
        inputData.value = '';
        inputInicio.value = '';
        inputFim.value = '';
        form.submit();
    }

    function aplicarDia(iso) {
        inputTipo.value = 'dia';
        inputMes.value = '';
        inputData.value = iso;
        inputInicio.value = '';
        inputFim.value = '';
        form.submit();
    }

    function aplicarIntervalo() {
        if (!estado.inicio || !estado.fim) return;

        inputTipo.value = 'intervalo';
        inputMes.value = '';
        inputData.value = '';
        inputInicio.value = estado.inicio;
        inputFim.value = estado.fim;
        form.submit();
    }

    function aplicarTodoPeriodo() {
        inputTipo.value = 'todos';
        inputMes.value = '';
        inputData.value = '';
        inputInicio.value = '';
        inputFim.value = '';
        form.submit();
    }

    trigger.addEventListener('click', function (event) {
        event.stopPropagation();
        if (popover.hidden) {
            abrirCalendario();
        } else {
            fecharCalendario();
        }
    });

    document.getElementById('relCalPrev').addEventListener('click', function () {
        mudarMes(-1);
    });

    document.getElementById('relCalNext').addEventListener('click', function () {
        mudarMes(1);
    });

    modeButtons.forEach(function (botao) {
        botao.addEventListener('click', function () {
            estado.modo = botao.getAttribute('data-mode') === 'range' ? 'range' : 'single';
            atualizarModo();
            renderizarCalendario();
        });
    });

    grid.addEventListener('click', function (event) {
        const botaoDia = event.target.closest('.cal-day');
        if (!botaoDia) return;

        const iso = botaoDia.getAttribute('data-data');
        if (!iso) return;

        if (estado.modo === 'range') {
            if (!estado.inicio || (estado.inicio && estado.fim)) {
                estado.inicio = iso;
                estado.fim = '';
                atualizarModo();
                renderizarCalendario();
                return;
            }

            if (iso < estado.inicio) {
                estado.fim = estado.inicio;
                estado.inicio = iso;
            } else {
                estado.fim = iso;
            }

            aplicarIntervalo();
            return;
        }

        aplicarDia(iso);
    });

    document.getElementById('relCalMesInteiro').addEventListener('click', function () {
        aplicarMes(estado.mes);
    });

    document.getElementById('relCalHoje').addEventListener('click', function () {
        aplicarDia(hojeISO);
    });

    document.getElementById('relCalTodoPeriodo').addEventListener('click', function () {
        aplicarTodoPeriodo();
    });

    document.getElementById('relCalLimparIntervalo').addEventListener('click', function () {
        estado.inicio = '';
        estado.fim = '';
        atualizarModo();
        renderizarCalendario();
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

    atualizarModo();
    renderizarCalendario();
})();

function alterarQtdAssinaturas(qtd) {
    qtd = parseInt(qtd, 10);
    const box = document.getElementById('box_assinaturas');
    const grid = document.getElementById('grid_assinaturas');
    const s1 = document.getElementById('sig_item_1');
    const s2 = document.getElementById('sig_item_2');
    const s3 = document.getElementById('sig_item_3');
    const hidden = document.getElementById('hidden_filtro_qtd_assinaturas');
    const sel = document.getElementById('select_qtd_assinaturas');
    
    if (sel && sel.value !== String(qtd)) {
        sel.value = qtd;
    }
    if (hidden) {
        hidden.value = qtd;
    }
    
    if (qtd === 0) {
        if (box) box.style.display = 'none';
        return;
    }
    
    if (box) box.style.display = 'block';
    
    if (qtd === 1) {
        if (grid) {
            grid.style.gridTemplateColumns = '1fr';
            grid.style.maxWidth = '380px';
        }
        if (s1) s1.style.display = 'block';
        if (s2) s2.style.display = 'none';
        if (s3) s3.style.display = 'none';
    } else if (qtd === 2) {
        if (grid) {
            grid.style.gridTemplateColumns = 'repeat(2, 1fr)';
            grid.style.maxWidth = '100%';
        }
        if (s1) s1.style.display = 'block';
        if (s2) s2.style.display = 'block';
        if (s3) s3.style.display = 'none';
    } else if (qtd >= 3) {
        if (grid) {
            grid.style.gridTemplateColumns = 'repeat(3, 1fr)';
            grid.style.maxWidth = '100%';
        }
        if (s1) s1.style.display = 'block';
        if (s2) s2.style.display = 'block';
        if (s3) s3.style.display = 'block';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
