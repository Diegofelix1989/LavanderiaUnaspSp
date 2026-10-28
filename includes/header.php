<?php
// includes/header.php - Cabeçalho das páginas internas

require_once __DIR__ . '/auth.php';
$alert = obterAlerta();
$sysConfigHeader = obterConfiguracoes();
$sysNomeHeader   = $sysConfigHeader['sistema_nome'] ?? 'Lavanderia';
$temaUsuario     = (($currentUser['tema'] ?? 'claro') === 'escuro') ? 'escuro' : 'claro';
$temaHtml        = $temaUsuario === 'escuro' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $temaHtml ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="<?= $temaHtml === 'dark' ? 'dark' : 'light' ?>">
    <meta name="theme-color" content="<?= $temaHtml === 'dark' ? '#0b1120' : '#f4f6fa' ?>">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?><?= htmlspecialchars($sysNomeHeader) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
    <div class="app-wrapper">
        <!-- Barra de Navegação Lateral -->
        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Conteúdo Principal -->
        <main class="main-content">
            <!-- Barra Superior Mobile -->
            <div class="topbar-mobile">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" class="btn-menu-toggle" id="btnMenuToggle" aria-label="Abrir Menu">☰</button>
                    <strong><?= htmlspecialchars($sysNomeHeader) ?></strong>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                        <?= htmlspecialchars($currentUser['nome']) ?>
                    </span>
                    <button type="button" class="btn-theme-mobile" id="btnThemeMobile" aria-label="<?= $temaUsuario === 'escuro' ? 'Ativar tema claro' : 'Ativar tema escuro' ?>" title="<?= $temaUsuario === 'escuro' ? 'Ativar tema claro' : 'Ativar tema escuro' ?>">
                        <span aria-hidden="true"><?= $temaUsuario === 'escuro' ? '☀️' : '🌙' ?></span>
                    </button>
                </div>
            </div>

            <!-- Alertas e Notificações Flash -->
            <?php if (isset($currentUser['senha_alterada']) && (int)$currentUser['senha_alterada'] === 0 && basename($_SERVER['PHP_SELF']) !== 'perfil.php'): ?>
                <div class="alert alert-warning no-print" style="margin-bottom: 20px;">
                    <span>🔒</span>
                    <div>
                        <strong>Aviso de Segurança:</strong> Você ainda está utilizando a senha padrão inicial (<code>admin123</code>). 
                        Recomendamos <a href="perfil.php?trocar_senha=1" style="font-weight: 700; text-decoration: underline; color: inherit;">acessar seu perfil para alterar sua senha</a>.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($alert): ?>
                <div class="alert alert-<?= htmlspecialchars($alert['tipo']) ?>">
                    <?php if ($alert['tipo'] === 'success'): ?>
                        <span>✅</span>
                    <?php elseif ($alert['tipo'] === 'danger'): ?>
                        <span>⚠️</span>
                    <?php else: ?>
                        <span>ℹ️</span>
                    <?php endif; ?>
                    <div><?= htmlspecialchars($alert['msg']) ?></div>
                </div>
            <?php endif; ?>

