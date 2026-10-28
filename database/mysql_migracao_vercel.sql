-- Execute uma vez no banco remoto para persistir sessões PHP na Vercel.
CREATE TABLE IF NOT EXISTS `sessoes_php` (
    `id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `dados` MEDIUMBLOB NOT NULL,
    `atualizado_em` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sessoes_php_atualizado` (`atualizado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
