<?php
// includes/permissoes.php - Sistema Central de Controle de Permissões Granulares

require_once __DIR__ . '/../config/database.php';

/**
 * Retorna o catálogo completo de permissões disponíveis no sistema,
 * organizadas por grupo com título, ícone e descrição explicativa.
 */
function obterCatalogoPermissoes() {
    return [
        'dashboard' => [
            'titulo' => 'Painel Inicial (Dashboard)',
            'icone' => '📊',
            'permissoes' => [
                'dashboard_acesso' => [
                    'nome' => 'Acessar Dashboard',
                    'descricao' => 'Visualizar indicadores de desempenho, faturamento mensal, gráficos e comparativos.'
                ]
            ]
        ],
        'lancamentos' => [
            'titulo' => 'Lançamentos de Lavanderia',
            'icone' => '🧺',
            'permissoes' => [
                'lancamentos_ver' => [
                    'nome' => 'Visualizar Lançamentos',
                    'descricao' => 'Consultar a lista de registros de lavanderia, filtros e histórico.'
                ],
                'lancamentos_criar' => [
                    'nome' => 'Adicionar Lançamentos',
                    'descricao' => 'Registrar novos lançamentos de peças para os departamentos.'
                ],
                'lancamentos_editar' => [
                    'nome' => 'Editar Lançamentos',
                    'descricao' => 'Alterar informações, quantidades e datas de lançamentos existentes.'
                ],
                'lancamentos_excluir' => [
                    'nome' => 'Excluir Lançamentos',
                    'descricao' => 'Mover lançamentos para a lixeira do sistema.'
                ]
            ]
        ],
        'cadastros' => [
            'titulo' => 'Cadastros Básicos',
            'icone' => '📁',
            'permissoes' => [
                'departamentos_gerenciar' => [
                    'nome' => 'Gerenciar Departamentos',
                    'descricao' => 'Visualizar, cadastrar, renomear e excluir departamentos atendidos.'
                ],
                'produtos_gerenciar' => [
                    'nome' => 'Gerenciar Produtos / Peças',
                    'descricao' => 'Visualizar, cadastrar, editar valores padrão e excluir peças de roupa.'
                ]
            ]
        ],
        'relatorios' => [
            'titulo' => 'Relatórios e Inteligência',
            'icone' => '📈',
            'permissoes' => [
                'relatorios_ver' => [
                    'nome' => 'Visualizar Relatórios',
                    'descricao' => 'Consultar relatórios por departamento, consolidados de peças e centros de custo.'
                ],
                'relatorios_exportar' => [
                    'nome' => 'Exportar Relatórios',
                    'descricao' => 'Permissão para imprimir e exportar relatórios nos formatos PDF e Excel.'
                ]
            ]
        ],
        'lixeira' => [
            'titulo' => 'Lixeira e Recuperação',
            'icone' => '🗑️',
            'permissoes' => [
                'lixeira_gerenciar' => [
                    'nome' => 'Gerenciar Lixeira',
                    'descricao' => 'Visualizar itens excluídos temporariamente, restaurar dados ou expurgar permanentemente.'
                ]
            ]
        ],
        'sistema' => [
            'titulo' => 'Administração do Sistema',
            'icone' => '⚙️',
            'permissoes' => [
                'usuarios_gerenciar' => [
                    'nome' => 'Gerenciar Usuários e Permissões',
                    'descricao' => 'Criar novos operadores, resetar senhas, ativar/desativar contas e configurar permissões.'
                ]
            ]
        ]
    ];
}

/**
 * Retorna uma lista simples contendo todos os slugs de permissões do sistema.
 */
function obterTodosSlugsPermissoes() {
    $slugs = [];
    foreach (obterCatalogoPermissoes() as $grupo) {
        foreach (array_keys($grupo['permissoes']) as $slug) {
            $slugs[] = $slug;
        }
    }
    return $slugs;
}

/**
 * Carrega a lista de permissões concedidas a um usuário específico.
 * Se o usuário for Administrador ou Supervisor Geral e não tiver registros salvos,
 * recebe todas as permissões automaticamente como perfil padrão.
 */
function obterPermissoesDoUsuario($usuarioId, $pdo = null) {
    if (!$pdo) {
        $pdo = getDB();
    }

    $usuarioId = (int)$usuarioId;
    if ($usuarioId <= 0) {
        return [];
    }

    // Busca permissões salvas no banco
    $stmt = $pdo->prepare("SELECT permissao FROM permissoes_usuario WHERE usuario_id = ?");
    $stmt->execute([$usuarioId]);
    $permissoes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Se já existem permissões configuradas no banco para o usuário, retorna-as
    if (!empty($permissoes)) {
        return $permissoes;
    }

    // Se o usuário ainda não tiver registros na tabela permissoes_usuario,
    // verifica o cargo para definir o comportamento inicial:
    $stmtUser = $pdo->prepare("SELECT id, cargo FROM usuarios WHERE id = ?");
    $stmtUser->execute([$usuarioId]);
    $user = $stmtUser->fetch();

    if ($user) {
        $cargoLower = mb_strtolower($user['cargo'] ?? '');
        $isAdmin = (strpos($cargoLower, 'admin') !== false) || 
                   (strpos($cargoLower, 'supervisor') !== false) ||
                   ((int)$user['id'] === 1);

        if ($isAdmin) {
            // Administradores recebem todas as permissões por padrão
            return obterTodosSlugsPermissoes();
        } else {
            // Operadores comuns recebem permissões operacionais padrão:
            return [
                'dashboard_acesso',
                'lancamentos_ver',
                'lancamentos_criar'
            ];
        }
    }

    return [];
}

/**
 * Verifica se um usuário possui uma determinada permissão.
 * Se $usuario não for informado, utiliza o $currentUser da sessão.
 */
function usuarioTemPermissao($permissao, $usuario = null) {
    if ($usuario === null) {
        global $currentUser;
        $usuario = $currentUser;
    }

    if (!$usuario || empty($usuario['id'])) {
        return false;
    }

    $usuarioId = (int)$usuario['id'];

    // Se o usuário for Administrador Superuser (id = 1), sempre tem permissão plena
    if ($usuarioId === 1) {
        return true;
    }

    // Cache na requisição do usuário logado
    static $permissoesCache = [];
    if (!isset($permissoesCache[$usuarioId])) {
        $permissoesCache[$usuarioId] = obterPermissoesDoUsuario($usuarioId);
    }

    return in_array($permissao, $permissoesCache[$usuarioId], true);
}

/**
 * Exige que o usuário atual tenha a permissão informada.
 * Caso não tenha, interrompe o carregamento e renderiza uma tela 403 moderna ou bloqueia a requisição POST.
 */
function exigirPermissao($permissao) {
    if (!usuarioTemPermissao($permissao)) {
        // Se for requisição POST ou AJAX, retorna código HTTP 403 e encerra
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            http_response_code(403);
            if (function_exists('definirAlerta')) {
                definirAlerta('danger', 'Acesso negado: você não tem permissão para realizar esta operação.');
            }
            $origem = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
            header("Location: $origem");
            exit;
        }

        // Se for visualização de página, renderiza a tela estilizada de Acesso Negado (403)
        renderizarTelaAcessoNegado($permissao);
        exit;
    }
}

/**
 * Salva as permissões de um usuário no banco de dados.
 */
function salvarPermissoesDoUsuario($usuarioId, array $permissoesSelecionadas, $pdo = null) {
    if (!$pdo) {
        $pdo = getDB();
    }

    $usuarioId = (int)$usuarioId;
    if ($usuarioId <= 0) {
        return false;
    }

    $slugsValidos = obterTodosSlugsPermissoes();
    // Filtra apenas slugs permitidos
    $permissoesParaSalvar = array_intersect($permissoesSelecionadas, $slugsValidos);

    $pdo->beginTransaction();
    try {
        $stmtDel = $pdo->prepare("DELETE FROM permissoes_usuario WHERE usuario_id = ?");
        $stmtDel->execute([$usuarioId]);

        if (!empty($permissoesParaSalvar)) {
            $stmtIns = $pdo->prepare("INSERT INTO permissoes_usuario (usuario_id, permissao) VALUES (?, ?)");
            foreach ($permissoesParaSalvar as $perm) {
                $stmtIns->execute([$usuarioId, $perm]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Retorna a primeira página que o usuário tem permissão de acessar
 * para uso em redirecionamentos inteligentes de login e tela inicial.
 */
function obterPrimeiraPaginaPermitida($usuario = null) {
    if (usuarioTemPermissao('dashboard_acesso', $usuario)) {
        return 'views/dashboard.php';
    }
    if (usuarioTemPermissao('lancamentos_ver', $usuario)) {
        return 'views/lancamentos.php';
    }
    if (usuarioTemPermissao('relatorios_ver', $usuario)) {
        return 'views/relatorios.php';
    }
    if (usuarioTemPermissao('departamentos_gerenciar', $usuario)) {
        return 'views/departamentos.php';
    }
    if (usuarioTemPermissao('produtos_gerenciar', $usuario)) {
        return 'views/produtos.php';
    }
    if (usuarioTemPermissao('lixeira_gerenciar', $usuario)) {
        return 'views/lixeira.php';
    }
    if (usuarioTemPermissao('usuarios_gerenciar', $usuario)) {
        return 'views/usuarios.php';
    }
    return 'views/perfil.php';
}

/**
 * Renderiza página de erro 403 moderna, visualmente consistente com o design do sistema.
 */
function renderizarTelaAcessoNegado($permissaoRequerida) {
    http_response_code(403);
    $pageTitle = 'Acesso Negado (403)';
    
    // Tenta incluir cabeçalho se ainda não foi enviado
    if (!headers_sent() && file_exists(__DIR__ . '/header.php')) {
        require_once __DIR__ . '/header.php';
    }
    ?>
    <div style="max-width: 620px; margin: 40px auto; padding: 36px; background: #fff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); text-align: center; border: 1px solid #e2e8f0;">
        <div style="width: 80px; height: 80px; border-radius: 50%; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 38px;">
            🔒
        </div>
        <h2 style="font-size: 1.6rem; font-weight: 700; color: #1e293b; margin-bottom: 10px;">Acesso Restrito</h2>
        <p style="font-size: 0.95rem; color: #64748b; line-height: 1.6; margin-bottom: 24px;">
            Você não possui permissão para acessar esta página ou recurso (<code><?= htmlspecialchars($permissaoRequerida) ?></code>).
            Se você precisa de acesso a este módulo, solicite ao administrador do sistema para conceder a permissão na sua conta.
        </p>
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn btn-primary" style="padding: 10px 22px; font-weight: 600;">
                Ir para o Painel Principal
            </a>
            <a href="perfil.php" class="btn btn-outline" style="padding: 10px 22px; font-weight: 600;">
                Meu Perfil
            </a>
        </div>
    </div>
    <?php
    if (file_exists(__DIR__ . '/footer.php')) {
        require_once __DIR__ . '/footer.php';
    }
    exit;
}
