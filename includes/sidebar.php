<?php
// includes/sidebar.php - Menu lateral com perfil e submenus retráteis

$pdo = getDB();
$sysConfig = obterConfiguracoes();

// Contagem de itens na lixeira para o badge
$stmtLixeira = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM lancamentos WHERE deleted_at IS NOT NULL) +
        (SELECT COUNT(*) FROM departamentos WHERE deleted_at IS NOT NULL) +
        (SELECT COUNT(*) FROM produtos WHERE deleted_at IS NOT NULL) as total_lixeira
");
$totalLixeira = (int) $stmtLixeira->fetchColumn();

// Página atual para definir itens ativos
$paginaAtual = basename($_SERVER['PHP_SELF']);
$isCadastrosActive = in_array($paginaAtual, ['departamentos.php', 'produtos.php']);
?>

<aside class="sidebar">
    <!-- Cabeçalho da Marca -->
    <div class="sidebar-header">
        <div class="brand-icon">
            <?php if (storedImageExists($sysConfig['sistema_logo'] ?? '')): ?>
                <img src="<?= htmlspecialchars(storedImageSrc($sysConfig['sistema_logo']), ENT_QUOTES, 'UTF-8') ?>"
                     alt="Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
            <?php else: ?>
                🧺
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <h2><?= htmlspecialchars($sysConfig['sistema_nome']) ?></h2>
            <span><?= htmlspecialchars($sysConfig['sistema_subtitulo']) ?></span>
        </div>
    </div>

    <!-- Perfil do Usuário Logado -->
    <a href="perfil.php" class="user-profile-card" title="Editar Perfil">
        <div class="avatar-container">
            <?php if (storedImageExists($currentUser['avatar'] ?? '')): ?>
                <img src="<?= htmlspecialchars(storedImageSrc($currentUser['avatar']), ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" class="avatar-img">
            <?php else: ?>
                <div class="avatar-img" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                    <?= strtoupper(substr($currentUser['nome'] ?? 'U', 0, 1)) ?>
                </div>
            <?php endif; ?>
            <span class="status-indicator" title="Online no sistema"></span>
        </div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($currentUser['nome'] ?? 'Usuário') ?></div>
            <span class="user-role"><?= htmlspecialchars($currentUser['cargo'] ?? 'Operador') ?></span>
        </div>
    </a>

    <!-- Navegação Principal -->
    <nav class="sidebar-nav">
        <div class="nav-section-title">Navegação Principal</div>

        <!-- Dashboard -->
        <?php if (usuarioTemPermissao('dashboard_acesso')): ?>
        <a href="dashboard.php" class="nav-item <?= $paginaAtual === 'dashboard.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
            <span>Dashboard</span>
        </a>
        <?php endif; ?>

        <!-- Lançamentos -->
        <?php if (usuarioTemPermissao('lancamentos_ver')): ?>
        <a href="lancamentos.php" class="nav-item <?= $paginaAtual === 'lancamentos.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
            <span>Lançamentos</span>
        </a>
        <?php endif; ?>

        <!-- Cadastros com Submenu Retrátil -->
        <?php 
        $podeDep  = usuarioTemPermissao('departamentos_gerenciar');
        $podeProd = usuarioTemPermissao('produtos_gerenciar');
        if ($podeDep || $podeProd): 
        ?>
        <div class="submenu-wrapper <?= $isCadastrosActive ? 'open' : '' ?>">
            <button type="button" class="nav-item submenu-toggle <?= $isCadastrosActive ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
                <span>Cadastros</span>
                <span class="submenu-arrow">▼</span>
            </button>
            <div class="submenu-items">
                <?php if ($podeDep): ?>
                <a href="departamentos.php" class="subnav-item <?= $paginaAtual === 'departamentos.php' ? 'active' : '' ?>">
                    <span class="subnav-dot"></span>
                    <span>Departamentos</span>
                </a>
                <?php endif; ?>
                <?php if ($podeProd): ?>
                <a href="produtos.php" class="subnav-item <?= $paginaAtual === 'produtos.php' ? 'active' : '' ?>">
                    <span class="subnav-dot"></span>
                    <span>Produtos / Peças</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Relatórios com Submenu Retrátil -->
        <?php if (usuarioTemPermissao('relatorios_ver')): ?>
        <?php 
        $isRelatoriosActive = ($paginaAtual === 'relatorios.php');
        $modeloAtual = $_GET['modelo'] ?? 'departamento_detalhado';
        ?>
        <div class="submenu-wrapper <?= $isRelatoriosActive ? 'open' : '' ?>">
            <button type="button" class="nav-item submenu-toggle <?= $isRelatoriosActive ? 'active' : '' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <span>Relatórios</span>
                <span class="submenu-arrow">▼</span>
            </button>
            <div class="submenu-items">
                <a href="relatorios.php?modelo=departamento_detalhado" class="subnav-item <?= ($isRelatoriosActive && $modeloAtual === 'departamento_detalhado') ? 'active' : '' ?>">
                    <span class="subnav-dot"></span>
                    <span>Por Departamento</span>
                </a>
                <a href="relatorios.php?modelo=pecas_consolidado" class="subnav-item <?= ($isRelatoriosActive && $modeloAtual === 'pecas_consolidado') ? 'active' : '' ?>">
                    <span class="subnav-dot"></span>
                    <span>Consolidado Peças</span>
                </a>
                <a href="relatorios.php?modelo=resumo_departamentos" class="subnav-item <?= ($isRelatoriosActive && $modeloAtual === 'resumo_departamentos') ? 'active' : '' ?>">
                    <span class="subnav-dot"></span>
                    <span>Centro de Custo</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lixeira (com contador e aviso de 60 dias) -->
        <?php if (usuarioTemPermissao('lixeira_gerenciar')): ?>
        <a href="lixeira.php" class="nav-item <?= $paginaAtual === 'lixeira.php' ? 'active' : '' ?>" title="Lixeira com retenção de 60 dias">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            <span>Lixeira</span>
            <?php if ($totalLixeira > 0): ?>
                <span class="nav-badge danger"><?= $totalLixeira ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <div class="nav-section-title" style="margin-top: 10px;">Sistema</div>

        <!-- Usuários -->
        <?php if (usuarioTemPermissao('usuarios_gerenciar')): ?>
        <a href="usuarios.php" class="nav-item <?= $paginaAtual === 'usuarios.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <span>Usuários</span>
        </a>
        <?php endif; ?>

        <!-- Meu Perfil -->
        <a href="perfil.php" class="nav-item <?= $paginaAtual === 'perfil.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span>Meu Perfil</span>
        </a>

        <!-- Configurações do Sistema (somente administradores) -->
        <?php if (usuarioTemPermissao('usuarios_gerenciar')): ?>
        <a href="configuracoes.php" class="nav-item <?= $paginaAtual === 'configuracoes.php' ? 'active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>Configurações</span>
        </a>
        <?php endif; ?>
    </nav>

    <!-- Rodapé com Tema e Botão Sair -->
    <div class="sidebar-footer">
        <form method="POST" action="tema.php" class="theme-toggle-form js-theme-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="tema" value="<?= $temaUsuario === 'escuro' ? 'claro' : 'escuro' ?>">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/lavanderia/views/dashboard.php', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="theme-toggle" aria-label="<?= $temaUsuario === 'escuro' ? 'Ativar tema claro' : 'Ativar tema escuro' ?>" title="<?= $temaUsuario === 'escuro' ? 'Ativar tema claro' : 'Ativar tema escuro' ?>">
                <span class="theme-toggle-icon" aria-hidden="true"><?= $temaUsuario === 'escuro' ? '☀️' : '🌙' ?></span>
                <span class="theme-toggle-label"><?= $temaUsuario === 'escuro' ? 'Tema claro' : 'Tema escuro' ?></span>
                <span class="theme-toggle-switch" aria-hidden="true"><span class="theme-toggle-knob"></span></span>
            </button>
        </form>
        <form method="POST" action="logout.php" class="logout-form" onsubmit="return confirm('Deseja realmente encerrar a sessão?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(tokenCsrfAtual(), ENT_QUOTES, 'UTF-8') ?>">
            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            <button type="submit" class="btn-logout">Encerrar Sessão</button>
        </form>
    </div>
</aside>
