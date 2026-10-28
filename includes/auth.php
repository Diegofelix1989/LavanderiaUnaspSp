<?php
// includes/auth.php - Middleware de autenticação

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/permissoes.php';

// Garante que a sessão PHP seja iniciada antes de qualquer saída HTML,
// permitindo gerar e validar o token CSRF corretamente.
iniciarSessaoWeb();

$currentUser = obterUsuarioAutenticado();

if (!$currentUser) {
    header('Location: login.php');
    exit;
}

