<?php
// config/session.php - Gestão de autenticação e sessões persistidas no SQLite

require_once __DIR__ . '/database.php';

define('SESSION_COOKIE_NAME', 'lavanderia_session');
define('SESSION_LIFETIME_HOURS', 12);

function iniciarSessaoWeb() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function tokenCsrfAtual() {
    iniciarSessaoWeb();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarTokenCsrf() {
    iniciarSessaoWeb();
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Inicia sessão no banco de dados para o usuário autenticado
function iniciarSessaoUsuario($usuario) {
    iniciarSessaoWeb();
    session_regenerate_id(true);

    $pdo = getDB();
    $token = bin2hex(random_bytes(32));
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 255);
    $expiraEm = date('Y-m-d H:i:s', strtotime('+' . SESSION_LIFETIME_HOURS . ' hours'));

    $stmt = $pdo->prepare("
        INSERT INTO sessoes (id, usuario_id, ip_address, user_agent, ultimo_acesso, expira_em)
        VALUES (?, ?, ?, ?, datetime('now', 'localtime'), datetime('now', 'localtime', '+" . SESSION_LIFETIME_HOURS . " hours'))
    ");
    $stmt->execute([$token, $usuario['id'], $ip, $userAgent]);

    // Define cookie HTTPOnly
    setcookie(
        SESSION_COOKIE_NAME,
        $token,
        [
            'expires' => time() + (SESSION_LIFETIME_HOURS * 3600),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        ]
    );

    return $token;
}

// Obtém o usuário ativo baseado no token da tabela sessoes
function obterUsuarioAutenticado() {
    static $usuarioAtual = null;
    if ($usuarioAtual !== null) {
        return $usuarioAtual;
    }

    if (empty($_COOKIE[SESSION_COOKIE_NAME])) {
        return false;
    }

    $token = $_COOKIE[SESSION_COOKIE_NAME];
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT u.id, u.nome, u.email, u.senha_alterada, u.cargo, u.avatar, COALESCE(u.status, 1) as status,
               COALESCE(u.tema, 'claro') as tema, s.id as token_sessao, s.ultimo_acesso
        FROM sessoes s
        JOIN usuarios u ON u.id = s.usuario_id
        WHERE s.id = ? AND s.expira_em > datetime('now', 'localtime')
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Se a conta do usuário foi desativada, encerra imediatamente a sessão
        if ((int)$user['status'] === 0) {
            destruirSessaoAtual();
            return false;
        }

        // Renova o último acesso da sessão no banco
        $stmtUpdate = $pdo->prepare("
            UPDATE sessoes 
            SET ultimo_acesso = datetime('now', 'localtime'),
                expira_em = datetime('now', 'localtime', '+" . SESSION_LIFETIME_HOURS . " hours')
            WHERE id = ?
        ");
        $stmtUpdate->execute([$token]);

        $usuarioAtual = $user;
        return $usuarioAtual;
    }

    // Se a sessão expirou ou não existe no banco, limpa cookie
    destruirSessaoAtual();
    return false;
}

// Destrói a sessão atual do banco e remove o cookie
function destruirSessaoAtual() {
    if (!empty($_COOKIE[SESSION_COOKIE_NAME])) {
        $token = $_COOKIE[SESSION_COOKIE_NAME];
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM sessoes WHERE id = ?");
        $stmt->execute([$token]);
    }

    setcookie(SESSION_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ]);
}

// Encerra todas as outras sessões do usuário exceto a atual
function encerrarOutrasSessoes($usuarioId, $tokenAtual) {
    $pdo = getDB();
    $stmt = $pdo->prepare("DELETE FROM sessoes WHERE usuario_id = ? AND id != ?");
    return $stmt->execute([$usuarioId, $tokenAtual]);
}

// Lista sessões ativas do usuário
function listarSessoesUsuario($usuarioId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, ip_address, user_agent, ultimo_acesso, expira_em
        FROM sessoes
        WHERE usuario_id = ? AND expira_em > datetime('now', 'localtime')
        ORDER BY ultimo_acesso DESC
    ");
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll();
}

// Sistema de mensagens flash simples via cookie codificado
function definirAlerta($tipo, $mensagem) {
    setcookie('lav_alert', json_encode(['tipo' => $tipo, 'msg' => $mensagem]), [
        'expires' => time() + 10,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ]);
}

function obterAlerta() {
    if (!empty($_COOKIE['lav_alert'])) {
        $data = json_decode($_COOKIE['lav_alert'], true);
        setcookie('lav_alert', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        ]);
        return $data;
    }
    return null;
}
