<?php
// views/logout.php - Encerra a sessão ativa no banco de dados e redireciona

require_once __DIR__ . '/../config/session.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !validarTokenCsrf()) {
    http_response_code(403);
    exit('Requisição inválida.');
}


destruirSessaoAtual();
definirAlerta('info', 'Sessão encerrada com sucesso.');
header('Location: login.php');
exit;
