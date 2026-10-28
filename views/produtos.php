<?php
// views/produtos.php - Gestão de Produtos / Peças e Preços

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('produtos_gerenciar');
$pdo = getDB();

// Processamento de Ações
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $nome = trim($_POST['nome'] ?? '');
        $valorPadrao = (float)str_replace(',', '.', $_POST['valor_padrao'] ?? 0);

        if (empty($nome) || $valorPadrao < 0) {
            definirAlerta('danger', 'Informe um nome válido e um valor maior ou igual a zero.');
        } else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, valor_padrao = ? WHERE id = ?");
                    $stmt->execute([$nome, $valorPadrao, $id]);
                    definirAlerta('success', 'Peça atualizada com sucesso!');
                } else {
                    $stmt = $pdo->prepare("INSERT INTO produtos (nome, valor_padrao) VALUES (?, ?)");
                    $stmt->execute([$nome, $valorPadrao]);
                    definirAlerta('success', 'Peça cadastrada com sucesso!');
                }
            } catch (PDOException $e) {
                definirAlerta('danger', 'Já existe uma peça cadastrada com este nome.');
            }
            header('Location: produtos.php');
            exit;
        }
    } elseif ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE produtos SET deleted_at = datetime('now', 'localtime') WHERE id = ?");
            $stmt->execute([$id]);
            definirAlerta('warning', 'Peça movida para a Lixeira.');
        }
        header('Location: produtos.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Busca e listagem
$busca = trim($_GET['busca'] ?? '');
if (!empty($busca)) {
    $stmt = $pdo->prepare("
        SELECT p.*,
               (SELECT COUNT(*) FROM lancamentos l WHERE l.produto_id = p.id AND l.deleted_at IS NULL) as total_usos
        FROM produtos p
        WHERE p.deleted_at IS NULL AND p.nome LIKE ?
        ORDER BY p.nome ASC
    ");
    $stmt->execute(["%$busca%"]);
} else {
    $stmt = $pdo->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM lancamentos l WHERE l.produto_id = p.id AND l.deleted_at IS NULL) as total_usos
        FROM produtos p
        WHERE p.deleted_at IS NULL
        ORDER BY p.nome ASC
    ");
}
$produtos = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-title-group">
        <h1>Produtos / Peças</h1>
        <p>Tabela de peças laváveis e valores unitários padrão</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="abrirModalNovoProduto()">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Nova Peça</span>
        </button>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="produtos.php" style="display: flex; gap: 12px; align-items: center;">
            <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar peça por nome (ex: Moletom, Beca, Tatame)..." class="form-control" style="max-width: 380px;">
            <button type="submit" class="btn btn-primary">Buscar</button>
            <?php if (!empty($busca)): ?>
                <a href="produtos.php" class="btn btn-outline">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>🏷️</span>
            <span>Tabela de Peças e Valores</span>
        </div>
        <span style="font-size: 0.85rem; color: #64748b;"><?= count($produtos) ?> peça(s) cadastrada(s)</span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 80px;">#ID</th>
                    <th>Nome da Peça</th>
                    <th style="width: 180px; text-align: right;">Valor Unitário Padrão</th>
                    <th style="width: 140px; text-align: center;">Usos em Lançamentos</th>
                    <th style="width: 140px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produtos)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">
                            Nenhuma peça encontrada.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($produtos as $prod): ?>
                        <tr>
                            <td>#<?= $prod['id'] ?></td>
                            <td><strong><?= htmlspecialchars($prod['nome']) ?></strong></td>
                            <td style="text-align: right;" class="cell-currency">
                                R$ <?= number_format($prod['valor_padrao'], 2, ',', '.') ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-info"><?= $prod['total_usos'] ?></span>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <button type="button" class="btn btn-outline btn-sm btn-icon" 
                                            onclick='editarProduto(<?= json_encode($prod) ?>)' title="Editar">
                                        ✏️
                                    </button>
                                    <form method="POST" action="produtos.php" style="display: inline;" 
                                          onsubmit="return confirm('Mover esta peça para a Lixeira?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="acao" value="deletar">
                                        <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm btn-icon" style="color: #ef4444;" title="Mover para Lixeira">
                                            🗑️
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

<!-- Modal Produto -->
<div class="modal-overlay" id="modalProd">
    <div class="modal-container">
        <form method="POST" action="produtos.php" id="formProd">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="prod_id" value="">


            <div class="modal-header">
                <h3 class="modal-title" id="prodModalTitulo">Nova Peça</h3>
                <button type="button" class="modal-close" onclick="fecharModal('modalProd')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="prod_nome">Nome da Peça *</label>
                    <input type="text" name="nome" id="prod_nome" class="form-control" placeholder="Ex: Toalha de mesa, Tatame, Colete..." required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="prod_valor">Valor Unitário Padrão (R$) *</label>
                    <input type="number" step="0.01" min="0" name="valor_padrao" id="prod_valor" class="form-control" placeholder="0.00" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modalProd')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Peça</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalNovoProduto() {
    document.getElementById('formProd').reset();
    document.getElementById('prod_id').value = '';
    document.getElementById('prodModalTitulo').innerText = 'Nova Peça';
    document.getElementById('prod_valor').value = '0.00';
    abrirModal('modalProd');
}

function editarProduto(prod) {
    document.getElementById('prod_id').value = prod.id;
    document.getElementById('prodModalTitulo').innerText = 'Editar Peça';
    document.getElementById('prod_nome').value = prod.nome;
    document.getElementById('prod_valor').value = parseFloat(prod.valor_padrao).toFixed(2);
    abrirModal('modalProd');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
