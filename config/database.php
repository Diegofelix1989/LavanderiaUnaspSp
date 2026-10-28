<?php
// config/database.php - Conexão e inicialização do banco SQLite

define('DB_FILE', __DIR__ . '/../database/lavanderia.db');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dbDir = dirname(DB_FILE);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        try {
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON;');
        } catch (PDOException $e) {
            die("Erro ao conectar ao banco de dados SQLite: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Criação automática das tabelas se não existirem
function inicializarBanco() {
    $pdo = getDB();

    // 1. Tabela de Usuários
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            senha TEXT NOT NULL,
            senha_alterada INTEGER NOT NULL DEFAULT 1,
            cargo TEXT DEFAULT 'Operador',
            avatar TEXT DEFAULT '',
            status INTEGER NOT NULL DEFAULT 1,
            tema TEXT NOT NULL DEFAULT 'claro',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $colunasUsuario = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll();
    $temColunaSenhaAlterada = false;
    $temColunaStatus = false;
    $temColunaTema = false;
    foreach ($colunasUsuario as $coluna) {
        if ($coluna['name'] === 'senha_alterada') {
            $temColunaSenhaAlterada = true;
        }
        if ($coluna['name'] === 'status') {
            $temColunaStatus = true;
        }
        if ($coluna['name'] === 'tema') {
            $temColunaTema = true;
        }
    }
    if (!$temColunaSenhaAlterada) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN senha_alterada INTEGER NOT NULL DEFAULT 1");
        $usuarios = $pdo->query("SELECT id, senha FROM usuarios")->fetchAll();
        $atualizarSenhaInicial = $pdo->prepare("UPDATE usuarios SET senha_alterada = 0 WHERE id = ?");
        foreach ($usuarios as $usuario) {
            if (password_verify('admin123', $usuario['senha'])) {
                $atualizarSenhaInicial->execute([$usuario['id']]);
            }
        }
    }
    if (!$temColunaStatus) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN status INTEGER NOT NULL DEFAULT 1");
    }
    if (!$temColunaTema) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN tema TEXT NOT NULL DEFAULT 'claro'");
    }

    // 2. Tabela de Sessões no Banco de Dados
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessoes (
            id TEXT PRIMARY KEY,
            usuario_id INTEGER NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            ultimo_acesso DATETIME DEFAULT CURRENT_TIMESTAMP,
            expira_em DATETIME NOT NULL,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        );
    ");

    // 3. Tabela de Departamentos (com suporte à Lixeira)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL UNIQUE,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL
        );
    ");

    // 4. Tabela de Produtos / Peças (com suporte à Lixeira)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produtos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL UNIQUE,
            valor_padrao REAL NOT NULL DEFAULT 0.00,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL
        );
    ");

    // 5. Tabela de Lançamentos de Lavanderia (com suporte à Lixeira)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lancamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data_lancamento DATE NOT NULL,
            departamento_id INTEGER NOT NULL,
            produto_id INTEGER NOT NULL,
            quantidade INTEGER NOT NULL DEFAULT 1,
            valor_unitario REAL NOT NULL,
            valor_total REAL NOT NULL,
            observacoes TEXT NULL,
            departamento_nome_snapshot TEXT NULL,
            produto_nome_snapshot TEXT NULL,
            usuario_id INTEGER NOT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            FOREIGN KEY (departamento_id) REFERENCES departamentos(id),
            FOREIGN KEY (produto_id) REFERENCES produtos(id),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        );
    ");

    $colunasLancamento = $pdo->query("PRAGMA table_info(lancamentos)")->fetchAll();
    $nomesColunasLancamento = array_column($colunasLancamento, 'name');
    if (!in_array('departamento_nome_snapshot', $nomesColunasLancamento, true)) {
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN departamento_nome_snapshot TEXT NULL");
    }
    if (!in_array('produto_nome_snapshot', $nomesColunasLancamento, true)) {
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN produto_nome_snapshot TEXT NULL");
    }
    $pdo->exec("
        UPDATE lancamentos
        SET departamento_nome_snapshot = COALESCE(departamento_nome_snapshot, (SELECT nome FROM departamentos WHERE id = lancamentos.departamento_id)),
            produto_nome_snapshot = COALESCE(produto_nome_snapshot, (SELECT nome FROM produtos WHERE id = lancamentos.produto_id))
        WHERE departamento_nome_snapshot IS NULL OR produto_nome_snapshot IS NULL
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lancamentos_ativos_data ON lancamentos (deleted_at, data_lancamento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lancamentos_departamento_data ON lancamentos (departamento_id, deleted_at, data_lancamento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lancamentos_produto_data ON lancamentos (produto_id, deleted_at, data_lancamento)");

    // 6. Tabela de Permissões de Acesso por Usuário
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissoes_usuario (
            usuario_id INTEGER NOT NULL,
            permissao TEXT NOT NULL,
            concedida_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (usuario_id, permissao),
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_permissoes_usuario ON permissoes_usuario (usuario_id)");

    // 7. Tabela de Configurações Globais do Sistema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            chave TEXT PRIMARY KEY,
            valor TEXT NOT NULL DEFAULT '',
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    // Seed inicial das configurações
    $pdo->exec("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES ('sistema_nome', 'Lavanderia')");
    $pdo->exec("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES ('sistema_subtitulo', 'Sistema Integrado')");
    $pdo->exec("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES ('sistema_logo', '')");

    // Executa a purga automática de 60 dias da lixeira
    executarLimpezaLixeira($pdo);

    // Seed inicial (se ainda não houver usuários cadastrados)
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
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO configuracoes (chave, valor, atualizado_em) VALUES (?, ?, datetime('now'))");
    return $stmt->execute([$chave, $valor]);
}

// Limpeza automática da lixeira após 60 dias
function executarLimpezaLixeira($pdo = null) {
    if (!$pdo) {
        $pdo = getDB();
    }
    // Remove registros que estão na lixeira há mais de 60 dias
    $pdo->exec("DELETE FROM lancamentos WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', '-60 days')");
    $pdo->exec("DELETE FROM produtos WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', '-60 days')");
    $pdo->exec("DELETE FROM departamentos WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now', '-60 days')");
    // Remove também sessões expiradas do banco
    $pdo->exec("DELETE FROM sessoes WHERE expira_em < datetime('now')");
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
        $stmtDep = $pdo->prepare("INSERT OR IGNORE INTO departamentos (nome) VALUES (?)");
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
        $stmtProd = $pdo->prepare("INSERT OR IGNORE INTO produtos (nome, valor_padrao) VALUES (?, ?)");
        foreach ($produtos as $prod) {
            $stmtProd->execute([$prod[0], $prod[1]]);
        }
    }
}

// Inicializa automaticamente
inicializarBanco();
