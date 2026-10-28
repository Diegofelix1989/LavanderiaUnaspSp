<?php
// views/usuarios.php - Gestão Completa de Usuários do Sistema

require_once __DIR__ . '/../includes/auth.php';
exigirPermissao('usuarios_gerenciar');
$pdo = getDB();
$adminLogado = $currentUser;

// Processamento de Ações POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $cargo = trim($_POST['cargo'] ?? 'Operador');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
        $senha = $_POST['senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';
        $forcarTroca = !empty($_POST['forcar_troca']) ? 0 : 1;

        // Validações Básicas
        if (empty($nome)) {
            definirAlerta('danger', 'O nome completo do usuário é obrigatório.');
            header('Location: usuarios.php');
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            definirAlerta('danger', 'Informe um endereço de e-mail válido.');
            header('Location: usuarios.php');
            exit;
        }

        // Validação de e-mail duplicado
        if ($id) {
            $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
            $stmtCheck->execute([$email, $id]);
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmtCheck->execute([$email]);
        }

        if ($stmtCheck->fetch()) {
            definirAlerta('danger', 'Este e-mail já está sendo utilizado por outro usuário.');
            header('Location: usuarios.php');
            exit;
        }

        // Segurança: usuário logado não pode inativar a si próprio
        if ($id && $id === (int)$adminLogado['id']) {
            $status = 1;
        }

        if ($id) {
            // Edição de Usuário Existente
            if (!empty($senha)) {
                if (strlen($senha) < 6) {
                    definirAlerta('danger', 'A nova senha deve ter no mínimo 6 caracteres.');
                    header('Location: usuarios.php');
                    exit;
                }
                if ($senha !== $confirmarSenha) {
                    definirAlerta('danger', 'As senhas informadas não coincidem.');
                    header('Location: usuarios.php');
                    exit;
                }

                $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET nome = ?, email = ?, cargo = ?, status = ?, senha = ?, senha_alterada = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $email, $cargo, $status, $hashSenha, $forcarTroca, $id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET nome = ?, email = ?, cargo = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $email, $cargo, $status, $id]);
            }

            // Se o usuário foi inativado, encerra suas sessões ativas
            if ($status === 0) {
                $stmtDelSessoes = $pdo->prepare("DELETE FROM sessoes WHERE usuario_id = ?");
                $stmtDelSessoes->execute([$id]);
            }

            definirAlerta('success', 'Usuário "' . $nome . '" atualizado com sucesso!');
        } else {
            // Criação de Novo Usuário
            if (empty($senha)) {
                definirAlerta('danger', 'A senha é obrigatória para o cadastro de novo usuário.');
                header('Location: usuarios.php');
                exit;
            }
            if (strlen($senha) < 6) {
                definirAlerta('danger', 'A senha provisória deve ter no mínimo 6 caracteres.');
                header('Location: usuarios.php');
                exit;
            }
            if ($senha !== $confirmarSenha) {
                definirAlerta('danger', 'As senhas informadas não coincidem.');
                header('Location: usuarios.php');
                exit;
            }

            $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nome, email, senha, senha_alterada, cargo, avatar, status)
                VALUES (?, ?, ?, ?, ?, '', ?)
            ");
            $stmt->execute([$nome, $email, $hashSenha, $forcarTroca, $cargo, $status]);

            definirAlerta('success', 'Novo usuário "' . $nome . '" cadastrado com sucesso!');
        }

        header('Location: usuarios.php');
        exit;

    } elseif ($acao === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            definirAlerta('danger', 'Usuário inválido.');
            header('Location: usuarios.php');
            exit;
        }

        if ($id === (int)$adminLogado['id']) {
            definirAlerta('danger', 'Você não pode desativar o seu próprio usuário logado.');
            header('Location: usuarios.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT nome, COALESCE(status, 1) as status FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $userAlvo = $stmt->fetch();

        if ($userAlvo) {
            $novoStatus = ((int)$userAlvo['status'] === 1) ? 0 : 1;
            $stmtUp = $pdo->prepare("UPDATE usuarios SET status = ? WHERE id = ?");
            $stmtUp->execute([$novoStatus, $id]);

            if ($novoStatus === 0) {
                // Encerra sessões ativas do usuário desativado
                $stmtDelSessoes = $pdo->prepare("DELETE FROM sessoes WHERE usuario_id = ?");
                $stmtDelSessoes->execute([$id]);
                definirAlerta('warning', 'O acesso de "' . $userAlvo['nome'] . '" foi desativado.');
            } else {
                definirAlerta('success', 'O usuário "' . $userAlvo['nome'] . '" foi reativado com sucesso.');
            }
        }

        header('Location: usuarios.php');
        exit;

    } elseif ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            definirAlerta('danger', 'Usuário inválido.');
            header('Location: usuarios.php');
            exit;
        }

        if ($id === (int)$adminLogado['id']) {
            definirAlerta('danger', 'Você não pode excluir o seu próprio usuário logado.');
            header('Location: usuarios.php');
            exit;
        }

        // Verifica se há lançamentos vinculados a este usuário
        $stmtLanc = $pdo->prepare("SELECT COUNT(*) FROM lancamentos WHERE usuario_id = ?");
        $stmtLanc->execute([$id]);
        $totalLancamentos = (int)$stmtLanc->fetchColumn();

        if ($totalLancamentos > 0) {
            definirAlerta('danger', "Não é possível excluir este usuário pois ele possui {$totalLancamentos} lançamento(s) registrado(s). Para revogar o acesso com segurança, desative o usuário.");
            header('Location: usuarios.php');
            exit;
        }

        // Deleta sessões e o usuário
        $stmtDelSessoes = $pdo->prepare("DELETE FROM sessoes WHERE usuario_id = ?");
        $stmtDelSessoes->execute([$id]);

        $stmtDel = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmtDel->execute([$id]);

        definirAlerta('success', 'Usuário excluído com sucesso.');
        header('Location: usuarios.php');
        exit;

    } elseif ($acao === 'salvar_permissoes') {
        $id = (int)($_POST['usuario_id'] ?? 0);
        $permissoes = isset($_POST['permissoes']) && is_array($_POST['permissoes']) ? $_POST['permissoes'] : [];

        if ($id <= 0) {
            definirAlerta('danger', 'Usuário inválido para configuração de permissões.');
            header('Location: usuarios.php');
            exit;
        }

        // Medida de segurança: o usuário logado não pode revogar de si próprio o acesso a usuários
        if ($id === (int)$adminLogado['id'] && !in_array('usuarios_gerenciar', $permissoes, true)) {
            $permissoes[] = 'usuarios_gerenciar';
        }

        if (salvarPermissoesDoUsuario($id, $permissoes, $pdo)) {
            definirAlerta('success', 'Permissões de acesso salvas com sucesso!');
        } else {
            definirAlerta('danger', 'Ocorreu um erro ao salvar as permissões do usuário.');
        }

        header('Location: usuarios.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';

// Filtros e Busca
$busca = trim($_GET['busca'] ?? '');
$filtroStatus = $_GET['status'] ?? '';
$filtroCargo = $_GET['cargo'] ?? '';

// Montagem da query
$sql = "
    SELECT 
        u.id, 
        u.nome, 
        u.email, 
        u.cargo, 
        COALESCE(u.status, 1) as status, 
        u.senha_alterada,
        u.avatar, 
        u.criado_em,
        (SELECT COUNT(*) FROM lancamentos l WHERE l.usuario_id = u.id AND l.deleted_at IS NULL) as total_lancamentos,
        (SELECT MAX(s.ultimo_acesso) FROM sessoes s WHERE s.usuario_id = u.id) as ultimo_acesso,
        (SELECT COUNT(*) FROM sessoes s WHERE s.usuario_id = u.id AND s.expira_em > CURRENT_TIMESTAMP) as sessoes_ativas
    FROM usuarios u
    WHERE 1=1
";
$params = [];

if (!empty($busca)) {
    $sql .= " AND (u.nome LIKE ? OR u.email LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if ($filtroStatus !== '') {
    $sql .= " AND COALESCE(u.status, 1) = ?";
    $params[] = (int)$filtroStatus;
}

if (!empty($filtroCargo)) {
    $sql .= " AND u.cargo = ?";
    $params[] = $filtroCargo;
}

$sql .= " ORDER BY u.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

// Anexa permissões atuais a cada usuário para uso na tabela e no modal
foreach ($usuarios as &$u) {
    $u['permissoes'] = obterPermissoesDoUsuario($u['id'], $pdo);
}
unset($u);

// Estatísticas para os cards
$stmtStats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN COALESCE(status, 1) = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN COALESCE(status, 1) = 0 THEN 1 ELSE 0 END) as inativos
    FROM usuarios
");
$stats = $stmtStats->fetch() ?: ['total' => 0, 'ativos' => 0, 'inativos' => 0];

$stmtSessoesAtivas = $pdo->query("
    SELECT COUNT(DISTINCT usuario_id) 
    FROM sessoes 
    WHERE expira_em > CURRENT_TIMESTAMP
");
$totalOnline = (int)$stmtSessoesAtivas->fetchColumn();

// Cargos distintos existentes para o filtro
$stmtCargos = $pdo->query("SELECT DISTINCT cargo FROM usuarios WHERE cargo IS NOT NULL AND cargo != '' ORDER BY cargo ASC");
$listaCargos = $stmtCargos->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- Cabeçalho da Página -->
<div class="page-header">
    <div class="page-title-group">
        <h1>Gestão de Usuários</h1>
        <p>Controle de operadores, administradores e permissões de acesso ao sistema</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="abrirModalNovoUsuario()">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            <span>Novo Usuário</span>
        </button>
    </div>
</div>

<!-- Cards de Indicadores / KPIs -->
<div class="kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="padding: 18px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(59, 130, 246, 0.12); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 22px;">
            👥
        </div>
        <div>
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Total de Usuários</div>
            <div style="font-size: 1.6rem; font-weight: 700; color: #1e293b;"><?= (int)$stats['total'] ?></div>
        </div>
    </div>

    <div class="card" style="padding: 18px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 22px;">
            ✅
        </div>
        <div>
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Usuários Ativos</div>
            <div style="font-size: 1.6rem; font-weight: 700; color: #059669;"><?= (int)$stats['ativos'] ?></div>
        </div>
    </div>

    <div class="card" style="padding: 18px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(239, 68, 68, 0.12); color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 22px;">
            ⛔
        </div>
        <div>
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Inativos / Bloqueados</div>
            <div style="font-size: 1.6rem; font-weight: 700; color: #dc2626;"><?= (int)$stats['inativos'] ?></div>
        </div>
    </div>

    <div class="card" style="padding: 18px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(139, 92, 246, 0.12); color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 22px;">
            ⚡
        </div>
        <div>
            <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Conectados Agora</div>
            <div style="font-size: 1.6rem; font-weight: 700; color: #7c3aed;"><?= $totalOnline ?></div>
        </div>
    </div>
</div>

<!-- Filtros e Barra de Pesquisa -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="usuarios.php" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 240px;">
                <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar por nome ou e-mail..." class="form-control">
            </div>

            <div style="min-width: 160px;">
                <select name="cargo" class="form-control">
                    <option value="">Todos os Cargos</option>
                    <?php foreach ($listaCargos as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $filtroCargo === $c ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="min-width: 150px;">
                <select name="status" class="form-control">
                    <option value="">Todos os Status</option>
                    <option value="1" <?= $filtroStatus === '1' ? 'selected' : '' ?>>Somente Ativos</option>
                    <option value="0" <?= $filtroStatus === '0' ? 'selected' : '' ?>>Somente Inativos</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <span>Filtrar</span>
            </button>

            <?php if (!empty($busca) || $filtroStatus !== '' || !empty($filtroCargo)): ?>
                <a href="usuarios.php" class="btn btn-outline">Limpar Filtros</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Tabela de Usuários -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <span>👤</span>
            <span>Usuários Cadastrados</span>
        </div>
        <span style="font-size: 0.85rem; color: #64748b;"><?= count($usuarios) ?> usuário(s) listado(s)</span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 70px;">#ID</th>
                    <th>Usuário / Contato</th>
                    <th style="width: 140px;">Cargo / Função</th>
                    <th style="width: 120px; text-align: center;">Status</th>
                    <th style="width: 140px; text-align: center;">Lançamentos</th>
                    <th style="width: 160px;">Último Acesso</th>
                    <th style="width: 150px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usuarios)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: #94a3b8;">
                            Nenhum usuário encontrado com os filtros selecionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usuarios as $u): ?>
                        <?php 
                        $isVoce = ((int)$u['id'] === (int)$adminLogado['id']);
                        $isAtivo = ((int)$u['status'] === 1);
                        $isOnline = ((int)$u['sessoes_ativas'] > 0);
                        
                        // Letra inicial para avatar fallback
                        $letra = strtoupper(substr($u['nome'], 0, 1));
                        
                        // Cor do cargo
                        $cargoBadgeClass = 'badge-info';
                        if (stripos($u['cargo'], 'admin') !== false) {
                            $cargoBadgeClass = 'badge-info';
                        } elseif (stripos($u['cargo'], 'gerente') !== false) {
                            $cargoBadgeClass = 'badge-warning';
                        }
                        ?>
                        <tr style="<?= !$isAtivo ? 'opacity: 0.65; background: #fafafa;' : '' ?>">
                            <td>#<?= $u['id'] ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="position: relative; width: 40px; height: 40px; flex-shrink: 0;">
                                        <?php if (!empty($u['avatar']) && file_exists(__DIR__ . '/../' . $u['avatar'])): ?>
                                            <img src="../<?= htmlspecialchars($u['avatar']) ?>" alt="Avatar" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                                        <?php else: ?>
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-weight: 700; font-size: 16px; display: flex; align-items: center; justify-content: center; border: 2px solid #e2e8f0;">
                                                <?= $letra ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isOnline): ?>
                                            <span style="position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; background: #10b981; border: 2px solid #fff; border-radius: 50%;" title="Conectado agora"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                            <?= htmlspecialchars($u['nome']) ?>
                                            <?php if ($isVoce): ?>
                                                <span style="font-size: 0.7rem; font-weight: 700; background: #e0e7ff; color: #4338ca; padding: 2px 6px; border-radius: 4px;">VOCÊ</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.82rem; color: #64748b;">
                                            <?= htmlspecialchars($u['email']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $cargoBadgeClass ?>">
                                    <?= htmlspecialchars($u['cargo'] ?: 'Operador') ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($isAtivo): ?>
                                    <span class="badge badge-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-info" style="font-weight: 600;">
                                    <?= (int)$u['total_lancamentos'] ?> registro(s)
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($u['ultimo_acesso'])): ?>
                                    <div style="font-size: 0.85rem; color: #334155;">
                                        <?= date('d/m/Y H:i', strtotime($u['ultimo_acesso'])) ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;">
                                        <?= $isOnline ? '🟢 Ativo no momento' : 'Sessão anterior' ?>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 0.82rem; color: #94a3b8; font-style: italic;">Nunca acessou</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 6px; align-items: center;">
                                    <!-- Botão Permissões de Acesso -->
                                    <button type="button" class="btn btn-outline btn-sm btn-icon" 
                                            style="color: #4f46e5; border-color: #c7d2fe; background: #eef2ff;"
                                            onclick='abrirModalPermissoes(<?= json_encode($u) ?>)' 
                                            title="Configurar Permissões de Acesso">
                                        🛡️
                                    </button>

                                    <!-- Botão Editar -->
                                    <button type="button" class="btn btn-outline btn-sm btn-icon" 
                                            onclick='editarUsuario(<?= json_encode($u) ?>)' title="Editar Dados / Senha">
                                        ✏️
                                    </button>

                                    <!-- Botão Ativar / Desativar -->
                                    <?php if (!$isVoce): ?>
                                        <form method="POST" action="usuarios.php" style="display: inline;" 
                                              onsubmit="return confirm('Deseja realmente <?= $isAtivo ? 'DESATIVAR' : 'REATIVAR' ?> este usuário?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="toggle_status">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm btn-icon" 
                                                    style="color: <?= $isAtivo ? '#f59e0b' : '#10b981' ?>;" 
                                                    title="<?= $isAtivo ? 'Desativar Acesso' : 'Reativar Acesso' ?>">
                                                <?= $isAtivo ? '🔒' : '🔓' ?>
                                            </button>
                                        </form>

                                        <!-- Botão Excluir -->
                                        <form method="POST" action="usuarios.php" style="display: inline;" 
                                              onsubmit="return confirm('Deseja excluir definitivamente este usuário? Esta ação não pode ser desfeita.');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="acao" value="deletar">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline btn-sm btn-icon" 
                                                    style="color: #ef4444;" title="Excluir Usuário">
                                                🗑️
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">(Sua conta)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Novo / Editar Usuário -->
<div class="modal-overlay" id="modalUsuario">
    <div class="modal-container" style="max-width: 520px;">
        <form method="POST" action="usuarios.php" id="formUsuario">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar">
            <input type="hidden" name="id" id="usr_id" value="">

            <div class="modal-header">
                <h3 class="modal-title" id="usuarioModalTitulo">Novo Usuário</h3>
                <button type="button" class="modal-close" onclick="fecharModal('modalUsuario')">&times;</button>
            </div>

            <div class="modal-body" style="padding: 24px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="usr_nome">Nome Completo *</label>
                    <input type="text" name="nome" id="usr_nome" class="form-control" placeholder="Ex: Maria dos Santos" required>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="usr_email">E-mail de Acesso *</label>
                    <input type="email" name="email" id="usr_email" class="form-control" placeholder="Ex: operador@lavanderia.com" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label" for="usr_cargo">Cargo / Função *</label>
                        <select name="cargo" id="usr_cargo" class="form-control" required>
                            <option value="Operador">Operador</option>
                            <option value="Administrador">Administrador</option>
                            <option value="Gerente">Gerente</option>
                            <option value="Atendente">Atendente</option>
                            <option value="Supervisor">Supervisor</option>
                        </select>
                    </div>

                    <div class="form-group" id="grupoStatus">
                        <label class="form-label" for="usr_status">Status da Conta *</label>
                        <select name="status" id="usr_status" class="form-control" required>
                            <option value="1">Ativo (Acesso Liberado)</option>
                            <option value="0">Inativo (Bloqueado)</option>
                        </select>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 16px; margin-bottom: 16px;">
                    <div style="font-weight: 600; font-size: 0.9rem; color: #1e293b; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <span>🔑</span>
                        <span id="usr_senha_label">Definição de Senha</span>
                    </div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 14px;" id="usr_senha_dica">
                        Informe uma senha de no mínimo 6 caracteres para o acesso ao sistema.
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="usr_senha" style="font-size: 0.82rem;">Senha *</label>
                            <input type="password" name="senha" id="usr_senha" class="form-control" placeholder="Mínimo 6 dígitos" minlength="6">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="usr_confirmar_senha" style="font-size: 0.82rem;">Confirmar Senha *</label>
                            <input type="password" name="confirmar_senha" id="usr_confirmar_senha" class="form-control" placeholder="Repita a senha" minlength="6">
                        </div>
                    </div>

                    <div style="margin-top: 12px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="forcar_troca" id="usr_forcar_troca" value="1" checked>
                            <span>Exigir troca de senha no primeiro login</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modalUsuario')">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnSalvarUsuario">Salvar Usuário</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalNovoUsuario() {
    document.getElementById('formUsuario').reset();
    document.getElementById('usr_id').value = '';
    document.getElementById('usuarioModalTitulo').innerText = 'Novo Usuário';
    document.getElementById('usr_senha_label').innerText = 'Definir Senha de Acesso *';
    document.getElementById('usr_senha_dica').innerText = 'Informe uma senha provisória de no mínimo 6 caracteres.';
    
    const senhaInput = document.getElementById('usr_senha');
    const confirmarInput = document.getElementById('usr_confirmar_senha');
    senhaInput.required = true;
    confirmarInput.required = true;
    senhaInput.placeholder = 'Mínimo 6 dígitos';
    confirmarInput.placeholder = 'Repita a senha';

    document.getElementById('usr_forcar_troca').checked = true;
    document.getElementById('grupoStatus').style.display = 'block';

    abrirModal('modalUsuario');
}

function editarUsuario(u) {
    document.getElementById('formUsuario').reset();
    document.getElementById('usr_id').value = u.id;
    document.getElementById('usuarioModalTitulo').innerText = 'Editar Usuário: ' + u.nome;
    document.getElementById('usr_nome').value = u.nome;
    document.getElementById('usr_email').value = u.email;
    
    // Cargo
    const cargoSelect = document.getElementById('usr_cargo');
    let cargoFound = false;
    for (let i = 0; i < cargoSelect.options.length; i++) {
        if (cargoSelect.options[i].value === u.cargo) {
            cargoSelect.selectedIndex = i;
            cargoFound = true;
            break;
        }
    }
    if (!cargoFound && u.cargo) {
        const novaOpcao = new Option(u.cargo, u.cargo, true, true);
        cargoSelect.add(novaOpcao);
    }

    // Status
    document.getElementById('usr_status').value = u.status;

    // Senha opcional na edição
    document.getElementById('usr_senha_label').innerText = 'Alterar Senha (Opcional)';
    document.getElementById('usr_senha_dica').innerText = 'Deixe os campos de senha em branco se desejar manter a senha atual do usuário.';
    
    const senhaInput = document.getElementById('usr_senha');
    const confirmarInput = document.getElementById('usr_confirmar_senha');
    senhaInput.required = false;
    confirmarInput.required = false;
    senhaInput.placeholder = 'Manter senha atual';
    confirmarInput.placeholder = 'Manter senha atual';

    document.getElementById('usr_forcar_troca').checked = (parseInt(u.senha_alterada) === 0);

    // Se estiver editando a própria conta logada, desabilita mudar o próprio status
    const idLogado = <?= (int)$adminLogado['id'] ?>;
    const selectStatus = document.getElementById('usr_status');
    if (parseInt(u.id) === idLogado) {
        selectStatus.value = '1';
        selectStatus.disabled = true;
    } else {
        selectStatus.disabled = false;
    }

    abrirModal('modalUsuario');
}

// ── Funções de Permissões de Acesso ───────────────────────────────────────
function abrirModalPermissoes(u) {
    document.getElementById('perm_usuario_id').value = u.id;
    document.getElementById('perm_user_nome').innerText = u.nome;
    document.getElementById('perm_user_email').innerText = u.email;
    document.getElementById('perm_user_cargo').innerText = u.cargo || 'Operador';
    
    // Avatar inicial
    const avatarBox = document.getElementById('perm_user_avatar');
    if (avatarBox) {
        avatarBox.innerText = (u.nome ? u.nome.charAt(0).toUpperCase() : 'U');
    }

    // Desmarca todos primeiro
    const checkboxes = document.querySelectorAll('.perm-checkbox');
    checkboxes.forEach(cb => { cb.checked = false; });

    // Marca as permissões que o usuário já tem
    const userPerms = Array.isArray(u.permissoes) ? u.permissoes : [];
    userPerms.forEach(slug => {
        const cb = document.getElementById('perm_' + slug);
        if (cb) {
            cb.checked = true;
        }
    });

    // Se estiver configurando o próprio usuário logado, previne desmarcar 'usuarios_gerenciar'
    const idLogado = <?= (int)$adminLogado['id'] ?>;
    const cbUsuarios = document.getElementById('perm_usuarios_gerenciar');
    if (cbUsuarios) {
        if (parseInt(u.id) === idLogado) {
            cbUsuarios.checked = true;
            cbUsuarios.title = 'Você não pode desmarcar sua própria permissão de gerenciar usuários.';
        } else {
            cbUsuarios.title = '';
        }
    }

    abrirModal('modalPermissoes');
}

function aplicarPresetPermissoes(tipo) {
    const checkboxes = document.querySelectorAll('.perm-checkbox');
    
    if (tipo === 'todos') {
        checkboxes.forEach(cb => { cb.checked = true; });
    } else if (tipo === 'limpar') {
        checkboxes.forEach(cb => { 
            // Não desmarca gerenciar_usuarios se for o próprio usuário logado
            const idAtual = parseInt(document.getElementById('perm_usuario_id').value);
            const idLogado = <?= (int)$adminLogado['id'] ?>;
            if (cb.value === 'usuarios_gerenciar' && idAtual === idLogado) {
                cb.checked = true;
            } else {
                cb.checked = false; 
            }
        });
    } else if (tipo === 'operador') {
        checkboxes.forEach(cb => { cb.checked = false; });
        const permsOp = ['dashboard_acesso', 'lancamentos_ver', 'lancamentos_criar'];
        permsOp.forEach(p => {
            const cb = document.getElementById('perm_' + p);
            if (cb) cb.checked = true;
        });
    } else if (tipo === 'consulta') {
        checkboxes.forEach(cb => { cb.checked = false; });
        const permsConsulta = ['dashboard_acesso', 'relatorios_ver'];
        permsConsulta.forEach(p => {
            const cb = document.getElementById('perm_' + p);
            if (cb) cb.checked = true;
        });
    }
}
</script>

<!-- Modal de Permissões de Acesso por Usuário -->
<div class="modal-overlay" id="modalPermissoes">
    <div class="modal-container" style="max-width: 680px; max-height: 90vh; display: flex; flex-direction: column;">
        <form method="POST" action="usuarios.php" id="formPermissoes" style="display: flex; flex-direction: column; height: 100%; overflow: hidden;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar_permissoes">
            <input type="hidden" name="usuario_id" id="perm_usuario_id" value="">

            <!-- Cabeçalho do Modal -->
            <div class="modal-header" style="flex-shrink: 0;">
                <div>
                    <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                        <span>🛡️</span>
                        <span>Permissões de Acesso</span>
                    </h3>
                    <div style="font-size: 0.82rem; color: #64748b; margin-top: 2px;">
                        Defina exatamente o que este usuário tem autorização para visualizar e executar no sistema.
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="fecharModal('modalPermissoes')">&times;</button>
            </div>

            <!-- Corpo Rolável -->
            <div class="modal-body" style="padding: 20px; overflow-y: auto; flex: 1;">
                <!-- Cartão Resumo do Usuário Selecionado -->
                <div style="display: flex; align-items: center; gap: 14px; padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); margin-bottom: 18px;">
                    <div id="perm_user_avatar" style="width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #4f46e5, #3730a3); color: #fff; font-weight: 700; font-size: 18px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);">
                        U
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 700; font-size: 1rem; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                            <span id="perm_user_nome">Nome do Usuário</span>
                            <span class="badge badge-info" id="perm_user_cargo" style="font-size: 0.72rem;">Cargo</span>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;" id="perm_user_email">email@exemplo.com</div>
                    </div>
                </div>

                <!-- Botões de Atalho / Perfis Rápidos -->
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; letter-spacing: 0.5px;">
                        Atalhos de Configuração Rápida
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="aplicarPresetPermissoes('todos')" style="font-size: 0.78rem;">
                            ⚡ Acesso Total (Admin)
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="aplicarPresetPermissoes('operador')" style="font-size: 0.78rem;">
                            🧺 Perfil Operador
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="aplicarPresetPermissoes('consulta')" style="font-size: 0.78rem;">
                            📊 Somente Consulta
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="aplicarPresetPermissoes('limpar')" style="font-size: 0.78rem; color: #ef4444; border-color: #fecaca;">
                            ❌ Limpar Todas
                        </button>
                    </div>
                </div>

                <!-- Lista de Grupos de Permissões -->
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <?php 
                    $catalogo = obterCatalogoPermissoes();
                    foreach ($catalogo as $grupoKey => $grupo): 
                    ?>
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius-md); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                            <!-- Cabeçalho do Grupo -->
                            <div style="background: #f1f5f9; padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0;">
                                <div style="font-weight: 700; font-size: 0.88rem; color: #334155; display: flex; align-items: center; gap: 8px;">
                                    <span><?= $grupo['icone'] ?></span>
                                    <span><?= htmlspecialchars($grupo['titulo']) ?></span>
                                </div>
                                <span style="font-size: 0.75rem; color: #64748b;">
                                    <?= count($grupo['permissoes']) ?> opção(ões)
                                </span>
                            </div>

                            <!-- Itens do Grupo -->
                            <div style="padding: 12px 16px; display: flex; flex-direction: column; gap: 10px;">
                                <?php foreach ($grupo['permissoes'] as $slug => $perm): ?>
                                    <label for="perm_<?= $slug ?>" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 6px 8px; border-radius: 6px; transition: background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                        <input type="checkbox" 
                                               name="permissoes[]" 
                                               id="perm_<?= $slug ?>" 
                                               value="<?= $slug ?>" 
                                               class="perm-checkbox"
                                               style="width: 18px; height: 18px; margin-top: 2px; cursor: pointer; accent-color: var(--primary-color);">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; font-size: 0.88rem; color: #1e293b;">
                                                <?= htmlspecialchars($perm['nome']) ?>
                                            </div>
                                            <div style="font-size: 0.78rem; color: #64748b; line-height: 1.4;">
                                                <?= htmlspecialchars($perm['descricao']) ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Rodapé do Modal -->
            <div class="modal-footer" style="flex-shrink: 0; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modalPermissoes')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 6px;">
                    <span>💾</span>
                    <span>Salvar Permissões</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
