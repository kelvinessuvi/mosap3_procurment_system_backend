-- =============================================================================
-- MOSAP3 Procurement - Activação de conta por link e recuperação de senha por código
-- =============================================================================
-- Equivalente MySQL das migrations:
--   2026_09_23_000001_add_email_verification_to_users_table
--   2026_09_23_000002_create_verification_codes_table
--
-- Alvo:  MySQL 5.7+ / MariaDB 10.3+
-- Uso:   mysql -u UTILIZADOR -p NOME_DA_BD < 2026_09_23_000001_email_verification_codes_up.sql
--
-- O script é idempotente: pode ser executado mais do que uma vez sem duplicar
-- nada. Faça sempre backup antes (ver README.md nesta pasta).
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Contas existentes passam a estar confirmadas
-- -----------------------------------------------------------------------------
-- A partir desta versão o login exige email confirmado. Sem este passo, todos os
-- utilizadores actuais ficariam trancados fora do sistema. Só as contas criadas
-- depois desta migração é que terão de confirmar o código.

UPDATE `users`
   SET `email_verified_at` = NOW()
 WHERE `email_verified_at` IS NULL;

-- -----------------------------------------------------------------------------
-- 2. Tabela dos códigos de 6 dígitos
-- -----------------------------------------------------------------------------
-- Serve os dois mecanismos, distinguidos por `type`:
--   'email_verification' -> activação de conta: usa só `token` (o link do email),
--                           com `code_hash` a NULL.
--   'password_reset'     -> recuperação de senha: usa `code_hash` (código de 6
--                           dígitos) e, opcionalmente, `token` (o intermédio
--                           emitido por /api/password/verify-code).
--
-- `code_hash`  : o código NUNCA é guardado em claro, apenas o hash bcrypt.
-- `attempts`   : tentativas falhadas (máximo 5, depois o código é queimado).
-- `consumed_at`: preenchido quando o código/link é usado ou invalidado.
--
-- NOTA sobre `expires_at`: o DEFAULT CURRENT_TIMESTAMP é deliberado. Sem um
-- DEFAULT explícito, o MySQL com explicit_defaults_for_timestamp=OFF (a
-- predefinição no 5.7) acrescenta silenciosamente ON UPDATE CURRENT_TIMESTAMP à
-- primeira coluna TIMESTAMP NOT NULL — o que renovaria a validade do código a
-- cada tentativa falhada. A aplicação preenche sempre esta coluna na inserção.

CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id`               bigint unsigned  NOT NULL AUTO_INCREMENT,
  `email`            varchar(255)     NOT NULL,
  `type`             varchar(32)      NOT NULL,
  `code_hash`        varchar(255)         NULL DEFAULT NULL,
  `attempts`         tinyint unsigned NOT NULL DEFAULT '0',
  `expires_at`       timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `token`            varchar(64)          NULL DEFAULT NULL,
  `token_expires_at` timestamp            NULL DEFAULT NULL,
  `consumed_at`      timestamp            NULL DEFAULT NULL,
  `created_at`       timestamp            NULL DEFAULT NULL,
  `updated_at`       timestamp            NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `verification_codes_token_unique` (`token`),
  KEY `verification_codes_email_index` (`email`),
  KEY `verification_codes_email_type_consumed_at_index` (`email`, `type`, `consumed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se já correu uma versão anterior deste script (em que `code_hash` era NOT
-- NULL), a instrução seguinte corrige a coluna. Se a tabela acabou de ser criada
-- acima, não altera nada. É seguro nos dois casos.

ALTER TABLE `verification_codes`
  MODIFY `code_hash` varchar(255) NULL DEFAULT NULL;

-- -----------------------------------------------------------------------------
-- 3. Registar as migrations como executadas
-- -----------------------------------------------------------------------------
-- Impede que um futuro `php artisan migrate` tente aplicá-las outra vez.

SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_23_000001_add_email_verification_to_users_table' AS m, @batch AS b) AS novo
 WHERE NOT EXISTS (
   SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_23_000001_add_email_verification_to_users_table'
 );

INSERT INTO `migrations` (`migration`, `batch`)
SELECT * FROM (SELECT '2026_09_23_000002_create_verification_codes_table' AS m, @batch AS b) AS novo
 WHERE NOT EXISTS (
   SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_09_23_000002_create_verification_codes_table'
 );

COMMIT;
