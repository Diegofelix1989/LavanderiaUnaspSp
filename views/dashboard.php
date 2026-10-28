<?php
// views/dashboard.php - Painel de Controle Principal do Sistema

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('dashboard_acesso');
$pdo = getDB();

$pageTitle = 'Dashboard';

// ── Período de análise (padrão: mês atual) ──────────────────────────────────
$mesSel  = isset($_GET['mes'])  ? (int)$_GET['mes']  : (int)date('m');
$anoSel  = isset($_GET['ano'])  ? (int)$_GET['ano']  : (int)date('Y');
if ($mesSel < 1 || $mesSel > 12) { $mesSel = (int)date('m'); }
if ($anoSel < 2020 || $anoSel > 2100) { $anoSel = (int)date('Y'); }

$depFiltro  = isset($_GET['departamento_id']) ? (int)$_GET['departamento_id'] : 0;
$prodFiltro = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;

$listaDepartamentos = $pdo->query("
    SELECT id, nome FROM departamentos
    WHERE deleted_at IS NULL
    ORDER BY nome ASC
")->fetchAll();
$listaProdutos = $pdo->query("
    SELECT id, nome FROM produtos
    WHERE deleted_at IS NULL
    ORDER BY nome ASC
")->fetchAll();

$idsDepartamentos = array_map('intval', array_column($listaDepartamentos, 'id'));
$idsProdutos      = array_map('intval', array_column($listaProdutos, 'id'));
if ($depFiltro > 0 && !in_array($depFiltro, $idsDepartamentos, true)) { $depFiltro = 0; }
if ($prodFiltro > 0 && !in_array($prodFiltro, $idsProdutos, true)) { $prodFiltro = 0; }

$filtrosLancamentosSql = '';
$paramsFiltros = [];
if ($depFiltro > 0) {
    $filtrosLancamentosSql .= ' AND l.departamento_id = ?';
    $paramsFiltros[] = $depFiltro;
}
if ($prodFiltro > 0) {
    $filtrosLancamentosSql .= ' AND l.produto_id = ?';
    $paramsFiltros[] = $prodFiltro;
}

$inicioPeriodo = sprintf('%04d-%02d-01', $anoSel, $mesSel);
$fimPeriodo    = date('Y-m-t', mktime(0, 0, 0, $mesSel, 1, $anoSel));

// ── KPIs do período selecionado ─────────────────────────────────────────────
$stmtKpi = $pdo->prepare("
    SELECT
        COUNT(*)            AS total_registros,
        COALESCE(SUM(valor_total), 0) AS receita_total,
        COALESCE(SUM(quantidade), 0)  AS total_pecas,
        COUNT(DISTINCT departamento_id) AS deps_ativos
    FROM lancamentos l
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
");
$stmtKpi->execute(array_merge([$inicioPeriodo, $fimPeriodo], $paramsFiltros));
$kpi = $stmtKpi->fetch();

// ── KPIs do mês anterior (para calcular variação %) ─────────────────────────
$mesAnt = $mesSel - 1;
$anoAnt = $anoSel;
if ($mesAnt < 1) { $mesAnt = 12; $anoAnt--; }
$iniAnt = sprintf('%04d-%02d-01', $anoAnt, $mesAnt);
$fimAnt = date('Y-m-t', mktime(0, 0, 0, $mesAnt, 1, $anoAnt));

$stmtAnt = $pdo->prepare("
    SELECT
        COUNT(*)                      AS total_registros,
        COALESCE(SUM(valor_total), 0) AS receita_total,
        COALESCE(SUM(quantidade), 0)  AS total_pecas
    FROM lancamentos l
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
");
$stmtAnt->execute(array_merge([$iniAnt, $fimAnt], $paramsFiltros));
$kpiAnt = $stmtAnt->fetch();

function calcVar($atual, $anterior) {
    if ((float)$anterior == 0) { return null; }
    return round((((float)$atual - (float)$anterior) / (float)$anterior) * 100, 1);
}

$varReceita  = calcVar($kpi['receita_total'], $kpiAnt['receita_total']);
$varRegistros = calcVar($kpi['total_registros'], $kpiAnt['total_registros']);
$varPecas    = calcVar($kpi['total_pecas'], $kpiAnt['total_pecas']);

// ── Top 5 departamentos do período ──────────────────────────────────────────
$stmtTopDep = $pdo->prepare("
    SELECT
        COALESCE(departamento_nome_snapshot, d.nome, 'Desconhecido') AS dep_nome,
        COUNT(*)                      AS total_lanc,
        COALESCE(SUM(l.valor_total), 0) AS total_valor
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
    GROUP BY l.departamento_id, departamento_nome_snapshot
    ORDER BY total_valor DESC
    LIMIT 5
");
$stmtTopDep->execute(array_merge([$inicioPeriodo, $fimPeriodo], $paramsFiltros));
$topDeps = $stmtTopDep->fetchAll();

// ── Top 5 produtos do período ────────────────────────────────────────────────
$stmtTopProd = $pdo->prepare("
    SELECT
        COALESCE(produto_nome_snapshot, p.nome, 'Desconhecido') AS prod_nome,
        COALESCE(SUM(l.quantidade), 0)    AS total_qtd,
        COALESCE(SUM(l.valor_total), 0)   AS total_valor
    FROM lancamentos l
    LEFT JOIN produtos p ON p.id = l.produto_id
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
    GROUP BY l.produto_id, produto_nome_snapshot
    ORDER BY total_qtd DESC
    LIMIT 5
");
$stmtTopProd->execute(array_merge([$inicioPeriodo, $fimPeriodo], $paramsFiltros));
$topProds = $stmtTopProd->fetchAll();

// ── Evolução diária do período (para sparkline) ──────────────────────────────
$stmtDiario = $pdo->prepare("
    SELECT
        data_lancamento,
        COALESCE(SUM(valor_total), 0) AS valor_dia,
        COUNT(*) AS count_dia
    FROM lancamentos l
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
    GROUP BY l.data_lancamento
    ORDER BY l.data_lancamento ASC
");
$stmtDiario->execute(array_merge([$inicioPeriodo, $fimPeriodo], $paramsFiltros));
$evolucaoDiaria = $stmtDiario->fetchAll();

// ── Evolução mensal do ano selecionado ──────────────────────────────────────
$inicioAno = sprintf('%04d-01-01', $anoSel);
$fimAno    = sprintf('%04d-12-31', $anoSel);

$stmtMensal = $pdo->prepare("
    SELECT
        DATE_FORMAT(data_lancamento, '%m') AS mes,
        COALESCE(SUM(valor_total), 0) AS valor_mes,
        COUNT(*) AS count_mes
    FROM lancamentos l
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
    GROUP BY DATE_FORMAT(l.data_lancamento, '%m')
    ORDER BY mes ASC
");
$stmtMensal->execute(array_merge([$inicioAno, $fimAno], $paramsFiltros));

$mensalPorMes = [];
foreach ($stmtMensal->fetchAll() as $linhaMes) {
    $mensalPorMes[(int)$linhaMes['mes']] = $linhaMes;
}

// ── Últimos 8 lançamentos ────────────────────────────────────────────────────
$stmtRecentes = $pdo->prepare("
    SELECT
        l.id,
        l.data_lancamento,
        l.quantidade,
        l.valor_unitario,
        l.valor_total,
        COALESCE(l.departamento_nome_snapshot, d.nome, 'Removido') AS dep_nome,
        COALESCE(l.produto_nome_snapshot, p.nome, 'Removido')      AS prod_nome,
        u.nome AS usuario_nome,
        l.criado_em
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    LEFT JOIN produtos p      ON p.id = l.produto_id
    LEFT JOIN usuarios u      ON u.id = l.usuario_id
    WHERE l.deleted_at IS NULL
      AND l.data_lancamento BETWEEN ? AND ?
      {$filtrosLancamentosSql}
    ORDER BY l.criado_em DESC, l.id DESC
    LIMIT 8
");
$stmtRecentes->execute(array_merge([$inicioPeriodo, $fimPeriodo], $paramsFiltros));
$lancamentosRecentes = $stmtRecentes->fetchAll();

// ── Contagem global ──────────────────────────────────────────────────────────
$totalDeps = (int)$pdo->query("SELECT COUNT(*) FROM departamentos WHERE deleted_at IS NULL")->fetchColumn();

// ── Gerar lista de meses/anos para o filtro ──────────────────────────────────
$stmtMeses = $pdo->query("
    SELECT DISTINCT
        DATE_FORMAT(data_lancamento, '%Y') AS ano,
        DATE_FORMAT(data_lancamento, '%m') AS mes
    FROM lancamentos
    WHERE deleted_at IS NULL
    ORDER BY ano DESC, mes DESC
    LIMIT 36
");
$mesesDisponiveis = $stmtMeses->fetchAll();

// Prepara dados JSON para o gráfico
$chartLabels = [];
$chartValues = [];
$maxValor = 0;
foreach ($evolucaoDiaria as $d) {
    $chartLabels[] = date('d/m', strtotime($d['data_lancamento']));
    $chartValues[] = round((float)$d['valor_dia'], 2);
    if ((float)$d['valor_dia'] > $maxValor) { $maxValor = (float)$d['valor_dia']; }
}

$nomesMeses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$mesesAbrev = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

$evolucaoMensal = [];
for ($m = 1; $m <= 12; $m++) {
    $linhaMes = $mensalPorMes[$m] ?? null;
    $evolucaoMensal[] = [
        'mes'       => $m,
        'nome'      => $nomesMeses[$m - 1],
        'valor_mes' => (float)($linhaMes['valor_mes'] ?? 0),
        'count_mes' => (int)($linhaMes['count_mes'] ?? 0),
    ];
}
$totalAnoMensal = array_sum(array_column($evolucaoMensal, 'valor_mes'));
$temLancamentosAno = array_sum(array_column($evolucaoMensal, 'count_mes')) > 0;
$maxMensal = max(array_column($evolucaoMensal, 'valor_mes')) ?: 1;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════════ CABEÇALHO -->
<div class="page-header">
    <div class="page-title-group">
        <h1>📊 Dashboard</h1>
        <p>
            Visão geral do sistema &mdash;
            <strong><?= $nomesMeses[$mesSel - 1] . ' ' . $anoSel ?></strong>
        </p>
    </div>
    <div class="page-actions">
        <form method="GET" action="dashboard.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <select name="mes" class="form-control" style="width:130px;" aria-label="Mês" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $mesSel === $m ? 'selected' : '' ?>>
                        <?= $nomesMeses[$m - 1] ?>
                    </option>
                <?php endfor; ?>
            </select>
            <select name="ano" class="form-control" style="width:90px;" aria-label="Ano" onchange="this.form.submit()">
                <?php for ($a = (int)date('Y'); $a >= 2024; $a--): ?>
                    <option value="<?= $a ?>" <?= $anoSel === $a ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
            <select name="departamento_id" class="form-control" style="width:175px;" aria-label="Departamento" onchange="this.form.submit()">
                <option value="0">Todos os Departamentos</option>
                <?php foreach ($listaDepartamentos as $dep): ?>
                    <option value="<?= (int)$dep['id'] ?>" <?= $depFiltro === (int)$dep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dep['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="produto_id" class="form-control" style="width:175px;" aria-label="Peça / Produto" onchange="this.form.submit()">
                <option value="0">Todas as Peças</option>
                <?php foreach ($listaProdutos as $prod): ?>
                    <option value="<?= (int)$prod['id'] ?>" <?= $prodFiltro === (int)$prod['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($prod['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <a href="dashboard.php" class="btn btn-outline" style="gap:6px;" title="Limpar filtros">
                ✕ Limpar
            </a>
        </form>
        <a href="lancamentos.php" class="btn btn-outline" style="gap:6px;">
            <svg style="width:15px;height:15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Novo Lançamento
        </a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ KPI GRID -->
<?php
function varBadge($v) {
    if ($v === null) return '<span style="font-size:.75rem;color:#94a3b8;">–</span>';
    $cor   = $v >= 0 ? '#059669' : '#dc2626';
    $bg    = $v >= 0 ? '#ecfdf5' : '#fef2f2';
    $seta  = $v >= 0 ? '▲' : '▼';
    return "<span style='font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:9999px;background:{$bg};color:{$cor};'>{$seta} " . abs($v) . "%</span>";
}
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px;margin-bottom:28px;">

    <!-- Receita Total -->
    <div class="kpi-card" style="position:relative;overflow:hidden;cursor:default;">
        <div style="position:absolute;top:-18px;right:-18px;width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(37,99,235,.1),rgba(6,182,212,.06));"></div>
        <div class="kpi-icon blue">💰</div>
        <div class="kpi-data">
            <span>Receita do Período</span>
            <h3>R$ <?= number_format((float)$kpi['receita_total'], 2, ',', '.') ?></h3>
            <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                <?= varBadge($varReceita) ?>
                <span style="font-size:.75rem;color:#94a3b8;">vs mês anterior</span>
            </div>
        </div>
    </div>

    <!-- Total de Registros -->
    <div class="kpi-card" style="position:relative;overflow:hidden;">
        <div style="position:absolute;top:-18px;right:-18px;width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(16,185,129,.1),rgba(52,211,153,.06));"></div>
        <div class="kpi-icon green">📋</div>
        <div class="kpi-data">
            <span>Lançamentos</span>
            <h3><?= number_format((int)$kpi['total_registros'], 0, ',', '.') ?></h3>
            <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                <?= varBadge($varRegistros) ?>
                <span style="font-size:.75rem;color:#94a3b8;">vs mês anterior</span>
            </div>
        </div>
    </div>

    <!-- Total de Peças -->
    <div class="kpi-card" style="position:relative;overflow:hidden;">
        <div style="position:absolute;top:-18px;right:-18px;width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(139,92,246,.1),rgba(167,139,250,.06));"></div>
        <div class="kpi-icon purple">🧺</div>
        <div class="kpi-data">
            <span>Peças Processadas</span>
            <h3><?= number_format((int)$kpi['total_pecas'], 0, ',', '.') ?></h3>
            <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                <?= varBadge($varPecas) ?>
                <span style="font-size:.75rem;color:#94a3b8;">vs mês anterior</span>
            </div>
        </div>
    </div>

    <!-- Departamentos Ativos -->
    <div class="kpi-card" style="position:relative;overflow:hidden;">
        <div style="position:absolute;top:-18px;right:-18px;width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(251,191,36,.06));"></div>
        <div class="kpi-icon amber">🏢</div>
        <div class="kpi-data">
            <span>Departamentos Ativos</span>
            <h3><?= (int)$kpi['deps_ativos'] ?></h3>
            <div style="margin-top:4px;">
                <span style="font-size:.75rem;color:#94a3b8;">de <?= $totalDeps ?> cadastrados</span>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════ GRÁFICO DE EVOLUÇÃO -->
<div style="margin-bottom:24px;">

    <!-- Gráfico de Barras: Evolução Diária -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-title">
                <span>📈</span>
                <span>Evolução Diária — <?= $nomesMeses[$mesSel - 1] ?> <?= $anoSel ?></span>
            </div>
            <?php if (!empty($chartValues)): ?>
            <span style="font-size:.82rem;color:#64748b;">
                Pico: R$ <?= number_format(max($chartValues), 2, ',', '.') ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:20px 24px 24px;">
            <?php if (empty($evolucaoDiaria)): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <div style="font-size:40px;margin-bottom:10px;">📭</div>
                    <p>Nenhum lançamento neste período.</p>
                </div>
            <?php else: ?>
                <!-- Gráfico de barras SVG-like com canvas HTML/CSS -->
                <div id="chartContainer" style="position:relative;height:200px;display:flex;align-items:flex-end;gap:4px;overflow-x:auto;overflow-y:hidden;padding-bottom:28px;">
                    <?php
                    $maxV = max(array_column($evolucaoDiaria, 'valor_dia')) ?: 1;
                    foreach ($evolucaoDiaria as $dia):
                        $perc = round(((float)$dia['valor_dia'] / $maxV) * 172);
                        $label = date('d', strtotime($dia['data_lancamento']));
                        $tooltip = date('d/m', strtotime($dia['data_lancamento'])) . ': R$ ' . number_format((float)$dia['valor_dia'], 2, ',', '.');
                    ?>
                    <div class="chart-bar-wrap" title="<?= htmlspecialchars($tooltip) ?>" style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:28px;cursor:pointer;">
                        <div class="chart-bar" style="
                            width:100%;
                            height:<?= $perc ?>px;
                            background:linear-gradient(180deg,#3b82f6,#06b6d4);
                            border-radius:4px 4px 0 0;
                            transition:opacity .15s;
                            position:relative;
                        "></div>
                        <span style="font-size:10px;color:#94a3b8;white-space:nowrap;"><?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#94a3b8;margin-top:4px;">
                    <span>R$ 0</span>
                    <span>R$ <?= number_format($maxV / 2, 0, ',', '.') ?></span>
                    <span>R$ <?= number_format($maxV, 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Gráfico de Barras: Evolução Mensal -->
    <div class="card" style="margin-top:20px;margin-bottom:0;">
        <div class="card-header">
            <div class="card-title">
                <span>📅</span>
                <span>Evolução Mensal — <?= $anoSel ?></span>
            </div>
            <?php if ($temLancamentosAno): ?>
            <span style="font-size:.82rem;color:#64748b;">
                Total no ano: R$ <?= number_format($totalAnoMensal, 2, ',', '.') ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:20px 24px 24px;">
            <?php if (!$temLancamentosAno): ?>
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <div style="font-size:40px;margin-bottom:10px;">📭</div>
                    <p>Nenhum lançamento neste ano.</p>
                </div>
            <?php else: ?>
                <div id="chartMensal" style="position:relative;height:200px;display:flex;align-items:flex-end;gap:8px;overflow-x:auto;overflow-y:hidden;padding-bottom:28px;">
                    <?php foreach ($evolucaoMensal as $mes):
                        $percMes = $mes['valor_mes'] > 0
                            ? max(2, round(($mes['valor_mes'] / $maxMensal) * 172))
                            : 0;
                        $tooltipMes = $mes['nome'] . ' de ' . $anoSel . ': R$ ' . number_format($mes['valor_mes'], 2, ',', '.');
                    ?>
                    <div class="chart-bar-wrap" title="<?= htmlspecialchars($tooltipMes) ?>" style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:44px;cursor:pointer;">
                        <div class="chart-bar" style="
                            width:100%;
                            height:<?= $percMes ?>px;
                            background:linear-gradient(180deg,#8b5cf6,#6366f1);
                            border-radius:4px 4px 0 0;
                            transition:opacity .15s;
                            position:relative;
                        "></div>
                        <span style="font-size:10px;color:#94a3b8;white-space:nowrap;"><?= $mesesAbrev[$mes['mes'] - 1] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#94a3b8;margin-top:4px;">
                    <span>R$ 0</span>
                    <span>R$ <?= number_format($maxMensal / 2, 0, ',', '.') ?></span>
                    <span>R$ <?= number_format($maxMensal, 0, ',', '.') ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════ TOP DEPS + TOP PRODUTOS + FEED -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

    <!-- Top Departamentos -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-title">
                <span>🏆</span>
                <span>Top Departamentos</span>
            </div>
            <a href="relatorios.php?modelo=resumo_departamentos" class="btn btn-outline btn-sm">Ver Relatório</a>
        </div>
        <div class="card-body" style="padding:16px 20px;">
            <?php if (empty($topDeps)): ?>
                <p style="text-align:center;color:#94a3b8;padding:20px 0;">Sem dados no período.</p>
            <?php else: ?>
                <?php
                $maxDep = max(array_column($topDeps, 'total_valor')) ?: 1;
                $posColors = ['#f59e0b','#94a3b8','#b45309','#3b82f6','#10b981'];
                foreach ($topDeps as $idx => $dep):
                    $percBar = round(((float)$dep['total_valor'] / $maxDep) * 100);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                            <span style="
                                width:22px;height:22px;border-radius:50%;
                                background:<?= $posColors[$idx] ?? '#e2e8f0' ?>;
                                color:#fff;font-weight:800;font-size:11px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;
                            "><?= $idx + 1 ?></span>
                            <span style="font-weight:600;font-size:.88rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;" title="<?= htmlspecialchars($dep['dep_nome']) ?>">
                                <?= htmlspecialchars($dep['dep_nome']) ?>
                            </span>
                        </div>
                        <div style="text-align:right;flex-shrink:0;margin-left:8px;">
                            <div style="font-weight:700;font-size:.88rem;color:#1e293b;">R$ <?= number_format((float)$dep['total_valor'], 2, ',', '.') ?></div>
                            <div style="font-size:.75rem;color:#94a3b8;"><?= (int)$dep['total_lanc'] ?> lançamento(s)</div>
                        </div>
                    </div>
                    <div style="background:#f1f5f9;border-radius:9999px;height:6px;overflow:hidden;">
                        <div style="background:linear-gradient(90deg,<?= $posColors[$idx] ?? '#3b82f6' ?>,<?= $posColors[$idx] ?? '#3b82f6' ?>88);height:100%;width:<?= $percBar ?>%;border-radius:9999px;transition:width .4s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Produtos -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-title">
                <span>👕</span>
                <span>Peças Mais Processadas</span>
            </div>
            <a href="relatorios.php?modelo=pecas_consolidado" class="btn btn-outline btn-sm">Ver Relatório</a>
        </div>
        <div class="card-body" style="padding:16px 20px;">
            <?php if (empty($topProds)): ?>
                <p style="text-align:center;color:#94a3b8;padding:20px 0;">Sem dados no período.</p>
            <?php else: ?>
                <?php
                $maxProd = max(array_column($topProds, 'total_qtd')) ?: 1;
                $prodColors = ['#8b5cf6','#06b6d4','#10b981','#f59e0b','#ef4444'];
                foreach ($topProds as $idx => $prod):
                    $percBar = round(((float)$prod['total_qtd'] / $maxProd) * 100);
                ?>
                <div style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                            <span style="
                                width:22px;height:22px;border-radius:50%;
                                background:<?= $prodColors[$idx] ?? '#e2e8f0' ?>;
                                color:#fff;font-weight:800;font-size:11px;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;
                            "><?= $idx + 1 ?></span>
                            <span style="font-weight:600;font-size:.88rem;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;" title="<?= htmlspecialchars($prod['prod_nome']) ?>">
                                <?= htmlspecialchars($prod['prod_nome']) ?>
                            </span>
                        </div>
                        <div style="text-align:right;flex-shrink:0;margin-left:8px;">
                            <div style="font-weight:700;font-size:.88rem;color:#1e293b;"><?= number_format((int)$prod['total_qtd'], 0, ',', '.') ?> un.</div>
                            <div style="font-size:.75rem;color:#94a3b8;">R$ <?= number_format((float)$prod['total_valor'], 2, ',', '.') ?></div>
                        </div>
                    </div>
                    <div style="background:#f1f5f9;border-radius:9999px;height:6px;overflow:hidden;">
                        <div style="background:linear-gradient(90deg,<?= $prodColors[$idx] ?? '#8b5cf6' ?>,<?= $prodColors[$idx] ?? '#8b5cf6' ?>88);height:100%;width:<?= $percBar ?>%;border-radius:9999px;transition:width .4s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════ FEED DE ÚLTIMOS LANÇAMENTOS -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>🕐</span>
            <span>Últimos Lançamentos do Período</span>
        </div>
        <a href="lancamentos.php" class="btn btn-outline btn-sm">Ver Todos</a>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width:80px;">Data</th>
                    <th>Departamento</th>
                    <th>Peça / Produto</th>
                    <th style="width:90px;text-align:center;">Qtd.</th>
                    <th style="width:130px;text-align:right;">Valor Total</th>
                    <th style="width:130px;">Operador</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lancamentosRecentes)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;padding:48px;color:#94a3b8;">
                            <div style="font-size:36px;margin-bottom:8px;">📭</div>
                            Nenhum lançamento para os filtros selecionados.
                            <br><a href="lancamentos.php" class="btn btn-primary btn-sm" style="margin-top:12px;">Registrar Agora</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($lancamentosRecentes as $l): ?>
                        <tr>
                            <td>
                                <span style="font-size:.85rem;font-weight:600;color:#1e293b;"><?= date('d/m', strtotime($l['data_lancamento'])) ?></span>
                                <div style="font-size:.72rem;color:#94a3b8;"><?= date('Y', strtotime($l['data_lancamento'])) ?></div>
                            </td>
                            <td>
                                <span class="dep-chip" style="max-width:180px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;" title="<?= htmlspecialchars($l['dep_nome']) ?>">
                                    <?= htmlspecialchars($l['dep_nome']) ?>
                                </span>
                            </td>
                            <td style="font-weight:500;font-size:.88rem;"><?= htmlspecialchars($l['prod_nome']) ?></td>
                            <td style="text-align:center;">
                                <span class="badge badge-info"><?= (int)$l['quantidade'] ?> un.</span>
                            </td>
                            <td style="text-align:right;" class="cell-total">
                                R$ <?= number_format((float)$l['valor_total'], 2, ',', '.') ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:7px;">
                                    <div style="
                                        width:26px;height:26px;border-radius:50%;
                                        background:linear-gradient(135deg,#3b82f6,#06b6d4);
                                        color:#fff;font-weight:700;font-size:11px;
                                        display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                    "><?= strtoupper(substr($l['usuario_nome'] ?? 'U', 0, 1)) ?></div>
                                    <span style="font-size:.82rem;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:90px;" title="<?= htmlspecialchars($l['usuario_nome'] ?? '') ?>">
                                        <?= htmlspecialchars($l['usuario_nome'] ?? 'Sistema') ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ ESTILOS EXCLUSIVOS -->
<style>
.chart-bar-wrap:hover .chart-bar {
    opacity: .75;
}

.chart-bar-wrap:hover {
    background: rgba(59,130,246,.04);
    border-radius: 4px;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
