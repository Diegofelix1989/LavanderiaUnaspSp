<?php
// config/database.php - Conexão e inicialização do banco MySQL

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $localConfigPath = __DIR__ . '/database.local.php';
        $localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
        if (!is_array($localConfig)) {
            throw new RuntimeException('A configuração local do banco deve retornar um array.');
        }

        $host = getenv('DB_HOST') ?: ($localConfig['host'] ?? '');
        $database = getenv('DB_NAME') ?: ($localConfig['name'] ?? '');
        $username = getenv('DB_USER') ?: ($localConfig['user'] ?? '');
        $password = getenv('DB_PASSWORD');
        if ($password === false) {
            $password = $localConfig['password'] ?? '';
        }

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException(
                'Configure DB_HOST, DB_NAME e DB_USER no ambiente ou em config/database.local.php.'
            );
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Não foi possível conectar ao MySQL. Verifique a configuração e a conectividade do servidor.',
                0,
                $e
            );
        }
    }
    return $pdo;
}

// As tabelas são provisionadas pelo schema MySQL; aqui ficam apenas os seeds e tarefas recorrentes.
function inicializarBanco() {
    $pdo = getDB();
    $pdo->exec("
        INSERT IGNORE INTO configuracoes (chave, valor) VALUES
            ('sistema_nome', 'Lavanderia'),
            ('sistema_subtitulo', 'Sistema Integrado'),
            ('sistema_logo', '')
    ");

    $pdo->exec("
        UPDATE lancamentos
        SET departamento_nome_snapshot = COALESCE(
                departamento_nome_snapshot,
                (SELECT nome FROM departamentos WHERE id = lancamentos.departamento_id)
            ),
            produto_nome_snapshot = COALESCE(
                produto_nome_snapshot,
                (SELECT nome FROM produtos WHERE id = lancamentos.produto_id)
            )
        WHERE departamento_nome_snapshot IS NULL OR produto_nome_snapshot IS NULL
    ");

    executarLimpezaLixeira($pdo);
    popularDadosIniciais($pdo);
}

/**
 * Retorna as configurações globais do sistema como array associativo.
 * Usa cache estático para evitar múltiplas queries por requisição.
 */
function obterConfiguracoes() {
    static $config = null;
    if ($config === null) {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
        $config = [];
        foreach ($stmt->fetchAll() as $row) {
            $config[$row['chave']] = $row['valor'];
        }
        // Valores padrão de fallback
        $config['sistema_nome']                  = $config['sistema_nome'] ?? 'Lavanderia';
        $config['sistema_subtitulo']             = $config['sistema_subtitulo'] ?? 'Sistema Integrado';
        $config['sistema_logo']                  = $config['sistema_logo'] ?? '';
        $config['relatorio_assinatura_qtd']      = $config['relatorio_assinatura_qtd'] ?? '2';
        $config['relatorio_assinatura_1_cargo']  = $config['relatorio_assinatura_1_cargo'] ?? 'Responsável pela Lavanderia (Emissão & Conferência)';
        $config['relatorio_assinatura_2_titulo'] = $config['relatorio_assinatura_2_titulo'] ?? 'Gestor / Responsável pelo Setor';
        $config['relatorio_assinatura_2_cargo']  = $config['relatorio_assinatura_2_cargo'] ?? 'Centro de Custo (De Acordo & Recebimento)';
        $config['relatorio_assinatura_3_titulo'] = $config['relatorio_assinatura_3_titulo'] ?? 'Supervisão Geral / Controladoria';
        $config['relatorio_assinatura_3_cargo']  = $config['relatorio_assinatura_3_cargo'] ?? 'Visto de Auditoria Interna & Rateio';
    }
    return $config;
}

/**
 * Salva uma configuração global no banco.
 */
function salvarConfiguracao($chave, $valor) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO configuracoes (chave, valor, atualizado_em)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = CURRENT_TIMESTAMP
    ");
    return $stmt->execute([$chave, $valor]);
}

// Limpeza automática da lixeira após 60 dias
function executarLimpezaLixeira($pdo = null) {
    if (!$pdo) {
        $pdo = getDB();
    }
    // Remove registros que estão na lixeira há mais de 60 dias
    $pdo->exec("DELETE FROM lancamentos WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY)");
    $pdo->exec("DELETE FROM produtos WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY)");
    $pdo->exec("DELETE FROM departamentos WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 DAY)");
    $pdo->exec("DELETE FROM sessoes WHERE expira_em < CURRENT_TIMESTAMP");
}

// Popula dados base com base nas imagens enviadas pelo usuário
function popularDadosIniciais($pdo) {
    // 1. Usuário Administrador padrão
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    if ($stmt->fetchColumn() == 0) {
        $stmtUser = $pdo->prepare("
            INSERT INTO usuarios (nome, email, senha, cargo, avatar, senha_alterada) 
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $senhaHash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmtUser->execute([
            'Diego Felix',
            'admin@lavanderia.com',
            $senhaHash,
            'Supervisor Geral',
            'assets/img/avatar.png'
        ]);
    }

    // 2. Departamentos extraídos das imagens
    $stmt = $pdo->query("SELECT COUNT(*) FROM departamentos");
    if ($stmt->fetchColumn() == 0) {
        $departamentos = [
            'Acarte',
            'Adm. Ensino Superior',
            'Ensino Médio',
            'Adm. Superior',
            'ADM',
            'Audio visual',
            'Biblioteca',
            'Cenape',
            'Central Estágios',
            'COM. LABORATORIOS',
            'Coordenação Superior',
            'ESPAÇO MULTIFUNCIONAL',
            'NPP',
            'NUCLEO DE COMPUTAÇÃO',
            'Unasp Store'
        ];
        $stmtDep = $pdo->prepare("INSERT IGNORE INTO departamentos (nome) VALUES (?)");
        foreach ($departamentos as $dep) {
            $stmtDep->execute([$dep]);
        }
    }

    // 3. Peças / Produtos com valores padrão extraídos das imagens
    $stmt = $pdo->query("SELECT COUNT(*) FROM produtos");
    if ($stmt->fetchColumn() == 0) {
        $produtos = [
            ['Bandô para cortinas', 15.00],
            ['Beca de batismo', 18.00],
            ['Beca de coral', 18.00],
            ['Blusa moletom', 15.00],
            ['calça moletom', 15.00],
            ['Boné', 8.00],
            ['Calça G', 18.00],
            ['Calça M', 15.00],
            ['Calça P', 12.00],
            ['Camiseta G', 10.00],
            ['Camiseta M', 8.00],
            ['Camiseta P', 6.00],
            ['Camisolas', 15.00],
            ['Toalha de mesa', 25.00],
            ['CORTINA GRANDE', 50.00],
            ['CORTINA GRANDE FINA', 80.00],
            ['Colete', 10.00],
            ['COLETES - EDUCAÇÃO FÍSICA', 4.00],
            ['FLANELA', 4.50],
            ['TAPETE M', 40.00],
            ['TAPETE GRANDE', 80.00],
            ['TATAME', 40.00],
            ['Bandeira', 10.00]
        ];
        $stmtProd = $pdo->prepare("INSERT IGNORE INTO produtos (nome, valor_padrao) VALUES (?, ?)");
        foreach ($produtos as $prod) {
            $stmtProd->execute([$prod[0], $prod[1]]);
        }
    }
}

// Inicializa automaticamente
inicializarBanco();
