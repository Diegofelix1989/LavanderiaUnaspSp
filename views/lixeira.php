<?php
// views/lixeira.php - Lixeira com política de retenção de 60 dias

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('lixeira_gerenciar');
$pdo = getDB();

// Processamento de Ações: Restaurar, Excluir Definitivamente ou Esvaziar
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $acao = $_POST['acao'] ?? '';
    $tipo = $_POST['tipo'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    $tabelasValidas = [
        'lancamento' => 'lancamentos',
        'departamento' => 'departamentos',
        'produto' => 'produtos'
    ];

    if ($acao === 'restaurar' && isset($tabelasValidas[$tipo]) && $id > 0) {
        $tabela = $tabelasValidas[$tipo];
        $stmt = $pdo->prepare("UPDATE $tabela SET deleted_at = NULL WHERE id = ?");
        $stmt->execute([$id]);
        definirAlerta('success', 'Item restaurado com sucesso para o sistema!');
        $abaDestino = ($tipo === 'lancamento' ? 'lancamentos' : ($tipo === 'departamento' ? 'departamentos' : 'produtos'));
        header("Location: lixeira.php?aba=$abaDestino");
        exit;
    } elseif ($acao === 'excluir_definitivo' && isset($tabelasValidas[$tipo]) && $id > 0) {
        $tabela = $tabelasValidas[$tipo];
        $stmt = $pdo->prepare("DELETE FROM $tabela WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        definirAlerta('info', 'Item excluído permanentemente do banco de dados.');
        $abaDestino = ($tipo === 'lancamento' ? 'lancamentos' : ($tipo === 'departamento' ? 'departamentos' : 'produtos'));
        header("Location: lixeira.php?aba=$abaDestino");
        exit;
    } elseif ($acao === 'esvaziar_tudo') {
        $pdo->exec("DELETE FROM lancamentos WHERE deleted_at IS NOT NULL");
        $pdo->exec("DELETE FROM produtos WHERE deleted_at IS NOT NULL");
        $pdo->exec("DELETE FROM departamentos WHERE deleted_at IS NOT NULL");
        definirAlerta('warning', 'A lixeira foi completamente esvaziada.');
        header('Location: lixeira.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Executa limpeza automática de itens com mais de 60 dias
executarLimpezaLixeira($pdo);

// Aba ativa pela URL
$abaAtiva = $_GET['aba'] ?? 'lancamentos';

// Consulta de Lançamentos Deletados
$stmtLancamentos = $pdo->query("
    SELECT l.*, 
           COALESCE(d.nome, l.departamento_nome_snapshot, 'Setor Removido') AS departamento_nome, 
           COALESCE(p.nome, l.produto_nome_snapshot, 'Peça Removida') AS produto_nome,
           CAST((julianday('now') - julianday(l.deleted_at)) AS INTEGER) as dias_passados
    FROM lancamentos l
    LEFT JOIN departamentos d ON d.id = l.departamento_id
    LEFT JOIN produtos p ON p.id = l.produto_id
    WHERE l.deleted_at IS NOT NULL
    ORDER BY l.deleted_at DESC
");
$lancamentosDeletados = $stmtLancamentos->fetchAll();

// Departamentos Deletados
$stmtDeps = $pdo->query("
    SELECT *, CAST((julianday('now') - julianday(deleted_at)) AS INTEGER) as dias_passados
    FROM departamentos
    WHERE deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
");
$departamentosDeletados = $stmtDeps->fetchAll();

// Produtos Deletados
$stmtProds = $pdo->query("
    SELECT *, CAST((julianday('now') - julianday(deleted_at)) AS INTEGER) as dias_passados
    FROM produtos
    WHERE deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
");
$produtosDeletados = $stmtProds->fetchAll();

$totalItensLixeira = count($lancamentosDeletados) + count($departamentosDeletados) + count($produtosDeletados);
?>

<div class="page-header">
    <div class="page-title-group">
        <h1>Lixeira do Sistema</h1>
        <p>Os itens excluídos permanecem aqui por <strong>60 dias</strong> antes da eliminação definitiva automática.</p>
    </div>
    <div class="page-actions">
        <?php if ($totalItensLixeira > 0): ?>
            <form method="POST" action="lixeira.php" onsubmit="return confirm('ATENÇÃO: Todos os itens da lixeira serão removidos permanentemente. Confirmar limpeza total?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="esvaziar_tudo">
                <button type="submit" class="btn btn-danger">
                    <span>🔥</span> Esvaziar Toda a Lixeira
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Informativo de Política de 60 Dias -->
<div class="alert alert-warning" style="margin-bottom: 24px;">
    <span>⏳</span>
    <div>
        <strong>Política de Retenção Ativa:</strong> Todos os registros movidos para a lixeira contam com tolerância de <strong>60 dias</strong> para restauração. O sistema executa rotinas periódicas para limpeza de itens expirados.
    </div>
</div>

<!-- Abas de Navegação -->
<div class="tabs-nav">
    <button type="button" class="tab-btn <?= $abaAtiva === 'lancamentos' ? 'active' : '' ?>" data-tab="lancamentos" onclick="alternarAba('lancamentos')">
        Lançamentos (<?= count($lancamentosDeletados) ?>)
    </button>
    <button type="button" class="tab-btn <?= $abaAtiva === 'departamentos' ? 'active' : '' ?>" data-tab="departamentos" onclick="alternarAba('departamentos')">
        Departamentos (<?= count($departamentosDeletados) ?>)
    </button>
    <button type="button" class="tab-btn <?= $abaAtiva === 'produtos' ? 'active' : '' ?>" data-tab="produtos" onclick="alternarAba('produtos')">
        Produtos / Peças (<?= count($produtosDeletados) ?>)
    </button>
</div>

<!-- Aba 1: Lançamentos Deletados -->
<div class="tab-content" id="tab-lancamentos" style="display: <?= $abaAtiva === 'lancamentos' ? 'block' : 'none' ?>;">

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>🧺</span>
                <span>Lançamentos na Lixeira</span>
            </div>
            <span style="font-size: 0.85rem; color: #64748b;"><?= count($lancamentosDeletados) ?> registro(s)</span>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Data Orig.</th>
                        <th>Departamento</th>
                        <th>Peça</th>
                        <th style="text-align: center;">Qtde</th>
                        <th style="text-align: right;">Total</th>
                        <th>Deletado em</th>
                        <th>Permanência</th>
                        <th style="text-align: center; width: 180px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lancamentosDeletados)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhum lançamento na lixeira.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lancamentosDeletados as $item): 
                            $diasRestantes = max(0, 60 - $item['dias_passados']);
                        ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($item['data_lancamento'])) ?></td>
                                <td><?= htmlspecialchars($item['departamento_nome'] ?? 'Setor Removido') ?></td>
                                <td><?= htmlspecialchars($item['produto_nome'] ?? 'Peça Removida') ?></td>
                                <td style="text-align: center;"><?= $item['quantidade'] ?></td>
                                <td style="text-align: right;" class="cell-currency">
                                    R$ <?= number_format($item['valor_total'], 2, ',', '.') ?>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($item['deleted_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $diasRestantes <= 10 ? 'badge-danger' : 'badge-warning' ?>">
                                        <?= $diasRestantes ?> dia(s) restante(s)
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: inline-flex; gap: 8px;">
                                        <form method="POST" action="lixeira.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="restaurar">
                                            <input type="hidden" name="tipo" value="lancamento">
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" title="Restaurar este lançamento">
                                                Restaurar
                                            </button>
                                        </form>
                                        <form method="POST" action="lixeira.php" onsubmit="return confirm('Excluir definitivamente? Não será possível recuperar.');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="excluir_definitivo">
                                            <input type="hidden" name="tipo" value="lancamento">
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: #ef4444;" title="Excluir Definitivo">
                                                Excluir
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Aba 2: Departamentos Deletados -->
<div class="tab-content" id="tab-departamentos" style="display: <?= $abaAtiva === 'departamentos' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>🏢</span>
                <span>Departamentos na Lixeira</span>
            </div>
            <span style="font-size: 0.85rem; color: #64748b;"><?= count($departamentosDeletados) ?> registro(s)</span>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">#ID</th>
                        <th>Nome do Departamento</th>
                        <th>Deletado em</th>
                        <th>Permanência</th>
                        <th style="text-align: center; width: 180px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departamentosDeletados)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhum departamento na lixeira.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departamentosDeletados as $dep): 
                            $diasRestantes = max(0, 60 - $dep['dias_passados']);
                        ?>
                            <tr>
                                <td>#<?= $dep['id'] ?></td>
                                <td><strong><?= htmlspecialchars($dep['nome']) ?></strong></td>
                                <td><?= date('d/m/Y H:i', strtotime($dep['deleted_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $diasRestantes <= 10 ? 'badge-danger' : 'badge-warning' ?>">
                                        <?= $diasRestantes ?> dia(s) restante(s)
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: inline-flex; gap: 8px;">
                                        <form method="POST" action="lixeira.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="restaurar">
                                            <input type="hidden" name="tipo" value="departamento">
                                            <input type="hidden" name="id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Restaurar</button>
                                        </form>
                                        <form method="POST" action="lixeira.php" onsubmit="return confirm('Excluir este departamento definitivamente?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="excluir_definitivo">
                                            <input type="hidden" name="tipo" value="departamento">
                                            <input type="hidden" name="id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: #ef4444;">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Aba 3: Produtos / Peças Deletados -->
<div class="tab-content" id="tab-produtos" style="display: <?= $abaAtiva === 'produtos' ? 'block' : 'none' ?>;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <span>🏷️</span>
                <span>Produtos / Peças na Lixeira</span>
            </div>
            <span style="font-size: 0.85rem; color: #64748b;"><?= count($produtosDeletados) ?> registro(s)</span>
        </div>

        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">#ID</th>
                        <th>Nome da Peça</th>
                        <th style="text-align: right;">Preço Padrão</th>
                        <th>Deletado em</th>
                        <th>Permanência</th>
                        <th style="text-align: center; width: 180px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produtosDeletados)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 36px; color: #94a3b8;">
                                Nenhuma peça na lixeira.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($produtosDeletados as $prod): 
                            $diasRestantes = max(0, 60 - $prod['dias_passados']);
                        ?>
                            <tr>
                                <td>#<?= $prod['id'] ?></td>
                                <td><strong><?= htmlspecialchars($prod['nome']) ?></strong></td>
                                <td style="text-align: right;" class="cell-currency">
                                    R$ <?= number_format($prod['valor_padrao'], 2, ',', '.') ?>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($prod['deleted_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $diasRestantes <= 10 ? 'badge-danger' : 'badge-warning' ?>">
                                        <?= $diasRestantes ?> dia(s) restante(s)
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: inline-flex; gap: 8px;">
                                        <form method="POST" action="lixeira.php">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="restaurar">
                                            <input type="hidden" name="tipo" value="produto">
                                            <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Restaurar</button>
                                        </form>
                                        <form method="POST" action="lixeira.php" onsubmit="return confirm('Excluir esta peça definitivamente?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="excluir_definitivo">
                                            <input type="hidden" name="tipo" value="produto">
                                            <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color: #ef4444;">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
