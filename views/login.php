<?php
// views/login.php - Autenticação com sessão salva no banco de dados MySQL

require_once __DIR__ . '/../config/session.php';
iniciarSessaoWeb();

// Se já estiver logado, redireciona
if (obterUsuarioAutenticado()) {
    header('Location: lancamentos.php');
    exit;
}

$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validarTokenCsrf()) {
        http_response_code(403);
        exit('Requisição inválida.');
    }

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($email) || empty($senha)) {
        $erro = 'Por favor, preencha o e-mail e a senha.';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            if (isset($usuario['status']) && (int)$usuario['status'] === 0) {
                $erro = 'Este usuário está inativo no sistema. Contate o administrador.';
            } else {
                // Cria a sessão na tabela 'sessoes' do MySQL
                iniciarSessaoUsuario($usuario);
                definirAlerta('success', 'Bem-vindo de volta, ' . $usuario['nome'] . '!');
                header('Location: lancamentos.php');
                exit;
            }
        } else {
            $erro = 'Credenciais inválidas. Verifique seu e-mail e senha.';
        }
    }
}

$sysConfig = obterConfiguracoes();
$temLogo = storedImageExists($sysConfig['sistema_logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - <?= htmlspecialchars($sysConfig['sistema_nome']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: radial-gradient(circle at 10% 20%, #0f172a 0%, #1e293b 90%);
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 440px;
            padding: 38px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
        }
        .login-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #3b82f6, #06b6d4);
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 12px;
            box-shadow: 0 8px 18px rgba(59, 130, 246, 0.35);
            overflow: hidden;
        }
        .login-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: var(--radius-md);
        }
        .login-header h1 {
            font-size: 1.55rem;
            font-weight: 800;
            color: #0f172a;
        }
        .login-header p {
            color: #64748b;
            font-size: 0.88rem;
            margin-top: 4px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="login-icon">
            <?php if ($temLogo): ?>
                <img src="<?= htmlspecialchars(storedImageSrc($sysConfig['sistema_logo']), ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
            <?php else: ?>
                🧺
            <?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($sysConfig['sistema_nome']) ?></h1>
        <p><?= htmlspecialchars(!empty($sysConfig['sistema_subtitulo']) ? $sysConfig['sistema_subtitulo'] : 'Acesse com seu e-mail e senha') ?></p>
    </div>

    <?php if (!empty($erro)): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <span>⚠️</span>
            <div><?= htmlspecialchars($erro) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label class="form-label" for="email">E-mail</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="seu@email.com" autocomplete="username" required autofocus>
        </div>

        <div class="form-group" style="margin-bottom: 24px;">
            <label class="form-label" for="senha">Senha</label>
            <input type="password" id="senha" name="senha" class="form-control" placeholder="••••••••" autocomplete="current-password" required>
        </div>

        <label for="lembrarLogin" style="display: flex; align-items: center; gap: 8px; margin: -8px 0 20px; color: #475569; font-size: 0.9rem; cursor: pointer;">
            <input type="checkbox" id="lembrarLogin" style="width: 16px; height: 16px; accent-color: #3b82f6;">
            Lembrar meu login neste dispositivo
        </label>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">
            Entrar no Sistema
        </button>
    </form>
</div>

<script>
const loginEmailKey = 'lavanderia_login_email';
const emailInput = document.getElementById('email');
const rememberLogin = document.getElementById('lembrarLogin');
const savedEmail = localStorage.getItem(loginEmailKey);

if (savedEmail) {
    emailInput.value = savedEmail;
    rememberLogin.checked = true;
}

rememberLogin.addEventListener('change', function() {
    if (!this.checked) {
        localStorage.removeItem(loginEmailKey);
    }
});

document.querySelector('form').addEventListener('submit', function() {
    if (rememberLogin.checked && emailInput.value.trim()) {
        localStorage.setItem(loginEmailKey, emailInput.value.trim());
    } else {
        localStorage.removeItem(loginEmailKey);
    }
});
</script>

</body>
</html>
