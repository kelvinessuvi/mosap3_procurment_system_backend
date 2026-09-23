-- =============================================================================
-- MOSAP3 Procurement - Reversão da activação de conta / recuperação de senha
-- =============================================================================
-- Uso: mysql -u UTILIZADOR -p NOME_DA_BD < 2026_09_23_000001_email_verification_codes_down.sql
--
-- ATENÇÃO: apaga a tabela `verification_codes`, com todos os links de activação
-- e códigos de recuperação pendentes. Os pedidos em curso passam a ser inválidos.
--
-- O preenchimento de `users.email_verified_at` NÃO é revertido: depois de
-- aplicado, não há como distinguir as contas que já estavam confirmadas das que
-- foram marcadas pelo script de instalação. Reverter às cegas poria contas
-- legítimas fora do sistema.
-- =============================================================================

START TRANSACTION;

DROP TABLE IF EXISTS `verification_codes`;

DELETE FROM `migrations`
 WHERE `migration` IN (
   '2026_09_23_000001_add_email_verification_to_users_table',
   '2026_09_23_000002_create_verification_codes_table'
 );

COMMIT;
