<?php
// index.php - Redirecionamento inicial
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/permissoes.php';

$usuario = obterUsuarioAutenticado();

if ($usuario) {
    $destino = obterPrimeiraPaginaPermitida($usuario);
    header("Location: $destino");
} else {
    header('Location: views/login.php');
}
exit;
