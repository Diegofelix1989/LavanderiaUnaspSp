<?php
// views/tema.php - Alternância do tema claro/escuro persistida por usuário

require_once __DIR__ . '/../includes/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$aceitaJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function responderTema(bool $ok, string $mensagem, string $tema = '', int $status = 200): void {
    global $aceitaJson;

    http_response_code($status);
    if ($aceitaJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'mensagem' => $mensagem,
            'tema' => $tema,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        definirAlerta('success', $mensagem);
    } else {
        definirAlerta('danger', $mensagem);
    }

    $redirect = $_POST['redirect'] ?? 'dashboard.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')
        || str_contains($redirect, "\r") || str_contains($redirect, "\n")) {
        $redirect = 'dashboard.php';
    }

    header('Location: ' . $redirect);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responderTema(false, 'Método não permitido.', '', 405);
}

if (!validarTokenCsrf()) {
    responderTema(false, 'Requisição inválida. Atualize a página e tente novamente.', '', 403);
}

$temaRecebido = $_POST['tema'] ?? '';
if (!in_array($temaRecebido, ['claro', 'escuro'], true)) {
    responderTema(false, 'Tema inválido.', '', 422);
}

$pdo = getDB();
$stmt = $pdo->prepare("UPDATE usuarios SET tema = ? WHERE id = ?");
$stmt->execute([$temaRecebido, $currentUser['id']]);

$currentUser['tema'] = $temaRecebido;
$mensagem = $temaRecebido === 'escuro' ? 'Tema escuro ativado.' : 'Tema claro ativado.';

responderTema(true, $mensagem, $temaRecebido);
