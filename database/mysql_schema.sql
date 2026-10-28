-- ============================================================
-- Sistema de Lavanderia - Schema MySQL
-- Compatível com MySQL 5.7+/8.0+ e MariaDB 10.4+
--
-- Observação: o código PHP atual ainda usa SQLite. Este script
-- cria a estrutura no MySQL; a conexão e as consultas do sistema
-- precisam ser adaptadas para MySQL na próxima etapa.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `lavanderia`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `lavanderia`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Usuários
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `senha` VARCHAR(255) NOT NULL,
    `cargo` VARCHAR(100) NOT NULL DEFAULT 'Operador',
    `avatar` VARCHAR(255) NOT NULL DEFAULT '',
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `senha_alterada` TINYINT(1) NOT NULL DEFAULT 1,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `tema` ENUM('claro', 'escuro') NOT NULL DEFAULT 'claro',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuarios_email` (`email`),
    KEY `idx_usuarios_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Sessões persistidas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessoes` (
    `id` VARCHAR(64) NOT NULL,
    `usuario_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `ultimo_acesso` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expira_em` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sessoes_usuario` (`usuario_id`),
    KEY `idx_sessoes_expira_em` (`expira_em`),
    CONSTRAINT `fk_sessoes_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Departamentos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departamentos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(255) NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_departamentos_nome` (`nome`),
    KEY `idx_departamentos_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Produtos / peças
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `produtos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(255) NOT NULL,
    `valor_padrao` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_produtos_nome` (`nome`),
    KEY `idx_produtos_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Lançamentos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lancamentos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `data_lancamento` DATE NOT NULL,
    `departamento_id` INT UNSIGNED NOT NULL,
    `produto_id` INT UNSIGNED NOT NULL,
    `quantidade` INT NOT NULL DEFAULT 1,
    `valor_unitario` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `valor_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `observacoes` TEXT NULL,
    `usuario_id` INT UNSIGNED NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    `departamento_nome_snapshot` VARCHAR(255) NULL DEFAULT NULL,
    `produto_nome_snapshot` VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lancamentos_ativos_data` (`deleted_at`, `data_lancamento`),
    KEY `idx_lancamentos_departamento_data` (`departamento_id`, `deleted_at`, `data_lancamento`),
    KEY `idx_lancamentos_produto_data` (`produto_id`, `deleted_at`, `data_lancamento`),
    KEY `idx_lancamentos_usuario` (`usuario_id`),
    CONSTRAINT `fk_lancamentos_departamento`
        FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_lancamentos_produto`
        FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_lancamentos_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Permissões granulares por usuário
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissoes_usuario` (
    `usuario_id` INT UNSIGNED NOT NULL,
    `permissao` VARCHAR(100) NOT NULL,
    `concedida_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `permissao`),
    CONSTRAINT `fk_permissoes_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Configurações do sistema
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `configuracoes` (
    `chave` VARCHAR(100) NOT NULL,
    `valor` VARCHAR(1000) NOT NULL DEFAULT '',
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações iniciais (não sobrescreve valores já existentes)
INSERT IGNORE INTO `configuracoes` (`chave`, `valor`) VALUES
    ('sistema_nome', 'Lavanderia'),
    ('sistema_subtitulo', 'Sistema Integrado'),
    ('sistema_logo', '');

-- ============================================================
-- Fim do schema
-- ============================================================
