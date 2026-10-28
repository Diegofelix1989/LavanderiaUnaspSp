<?php
// views/departamentos.php - Gestão de Departamentos da Lavanderia

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('departamentos_gerenciar');
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

        if (empty($nome)) {
            definirAlerta('danger', 'O nome do departamento não pode ser vazio.');
        } else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE departamentos SET nome = ? WHERE id = ?");
                    $stmt->execute([$nome, $id]);
                    definirAlerta('success', 'Departamento atualizado com sucesso!');
                } else {
                    $stmt = $pdo->prepare("INSERT INTO departamentos (nome) VALUES (?)");
                    $stmt->execute([$nome]);
                    definirAlerta('success', 'Departamento cadastrado com sucesso!');
                }
            } catch (PDOException $e) {
                definirAlerta('danger', 'Já existe um departamento com este nome.');
            }
            header('Location: departamentos.php');
            exit;
        }
    } elseif ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE departamentos SET deleted_at = datetime('now', 'localtime') WHERE id = ?");
            $stmt->execute([$id]);
            definirAlerta('warning', 'Departamento movido para a Lixeira.');
        }
        header('Location: departamentos.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Busca e listagem
$busca = trim($_GET['busca'] ?? '');
if (!empty($busca)) {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               (SELECT COUNT(*) FROM lancamentos l WHERE l.departamento_id = d.id AND l.deleted_at IS NULL) as total_lancamentos
        FROM departamentos d
        WHERE d.deleted_at IS NULL AND d.nome LIKE ?
        ORDER BY d.nome ASC
    ");
    $stmt->execute(["%$busca%"]);
} else {
    $stmt = $pdo->query("
        SELECT d.*, 
               (SELECT COUNT(*) FROM lancamentos l WHERE l.departamento_id = d.id AND l.deleted_at IS NULL) as total_lancamentos
        FROM departamentos d
        WHERE d.deleted_at IS NULL
        ORDER BY d.nome ASC
    ");
}
$departamentos = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-title-group">
        <h1>Departamentos</h1>
        <p>Setores e centros de custo atendidos pela lavanderia</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="abrirModalNovoDep()">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            <span>Novo Departamento</span>
        </button>
    </div>
</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="departamentos.php" style="display: flex; gap: 12px; align-items: center;">
            <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar departamento por nome..." class="form-control" style="max-width: 380px;">
            <button type="submit" class="btn btn-primary">Buscar</button>
            <?php if (!empty($busca)): ?>
                <a href="departamentos.php" class="btn btn-outline">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>🏢</span>
            <span>Lista de Departamentos</span>
        </div>
        <span style="font-size: 0.85rem; color: #64748b;"><?= count($departamentos) ?> cadastrado(s)</span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 80px;">#ID</th>
                    <th>Nome do Departamento</th>
                    <th style="width: 180px; text-align: center;">Total Lançamentos</th>
                    <th style="width: 140px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($departamentos)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px; color: #94a3b8;">
                            Nenhum departamento encontrado.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($departamentos as $dep): ?>
                        <tr>
                            <td>#<?= $dep['id'] ?></td>
                            <td><strong><?= htmlspecialchars($dep['nome']) ?></strong></td>
                            <td style="text-align: center;">
                                <span class="badge badge-info"><?= $dep['total_lancamentos'] ?> registro(s)</span>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <button type="button" class="btn btn-outline btn-sm btn-icon" 
                                            onclick='editarDep(<?= json_encode($dep) ?>)' title="Editar">
                                        ✏️
                                    </button>
                                    <form method="POST" action="departamentos.php" style="display: inline;" 
                                          onsubmit="return confirm('Mover este departamento para a Lixeira?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="acao" value="deletar">
                                        <input type="hidden" name="id" value="<?= $dep['id'] ?>">
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

<!-- Modal Departamento -->
<div class="modal-overlay" id="modalDep">
    <div class="modal-container">
        <form method="POST" action="departamentos.php" id="formDep">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="dep_id" value="">


            <div class="modal-header">
                <h3 class="modal-title" id="depModalTitulo">Novo Departamento</h3>
                <button type="button" class="modal-close" onclick="fecharModal('modalDep')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="dep_nome">Nome do Departamento *</label>
                    <input type="text" name="nome" id="dep_nome" class="form-control" placeholder="Ex: Biblioteca, Ensino Médio, Coordenação..." required autofocus>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modalDep')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Departamento</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalNovoDep() {
    document.getElementById('formDep').reset();
    document.getElementById('dep_id').value = '';
    document.getElementById('depModalTitulo').innerText = 'Novo Departamento';
    abrirModal('modalDep');
}

function editarDep(dep) {
    document.getElementById('dep_id').value = dep.id;
    document.getElementById('depModalTitulo').innerText = 'Editar Departamento';
    document.getElementById('dep_nome').value = dep.nome;
    abrirModal('modalDep');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
