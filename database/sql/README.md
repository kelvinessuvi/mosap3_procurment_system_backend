# Migrations SQL (MySQL / produção)

Equivalente em SQL puro das migrations Laravel desta funcionalidade — activação
de conta por link e recuperação de senha por código de 6 dígitos — para
ambientes onde se aplica o esquema directamente na base de dados em vez de
correr `php artisan migrate`.

A tabela `verification_codes` serve os dois mecanismos, distinguidos pela coluna
`type`: os registos de activação (`email_verification`) usam só o `token` do
link e têm `code_hash` a NULL; os de recuperação (`password_reset`) guardam o
hash do código de 6 dígitos.

| Ficheiro | O que faz |
|---|---|
| `2026_09_23_000001_email_verification_codes_up.sql` | Marca as contas existentes como activadas, cria `verification_codes` e regista as duas migrations |
| `2026_09_23_000001_email_verification_codes_down.sql` | Apaga `verification_codes` e remove os registos das migrations |

Cobrem as migrations `2026_09_23_000001_add_email_verification_to_users_table` e
`2026_09_23_000002_create_verification_codes_table`. Aplique **uma** das duas
vias, não as duas: ou o script SQL, ou `php artisan migrate`. O script regista as
migrations como executadas, por isso correr `migrate` a seguir não repete nada.

Alvo: MySQL 5.7+ / MariaDB 10.3+, InnoDB, `utf8mb4_unicode_ci`.

## Aplicar

```bash
# 1. Backup primeiro — sempre
mysqldump -u UTILIZADOR -p NOME_DA_BD > backup_antes_codigos_$(date +%F).sql

# 2. Aplicar
mysql -u UTILIZADOR -p NOME_DA_BD < 2026_09_23_000001_email_verification_codes_up.sql

# 3. Limpar a cache de configuração da aplicação
php artisan config:clear && php artisan route:clear
```

O script é idempotente (`CREATE TABLE IF NOT EXISTS`, inserções guardadas por
`NOT EXISTS`, `ALTER ... MODIFY` que converge sempre para o mesmo estado):
executá-lo duas vezes não duplica nada. Se correu uma versão anterior em que
`code_hash` era `NOT NULL`, voltar a correr o script corrige a coluna.

> Nota sobre a transacção: o `START TRANSACTION` protege o `UPDATE` aos
> utilizadores e as inserções na tabela `migrations`, mas o MySQL faz *commit*
> implícito em instruções DDL — o `CREATE TABLE` não é revertido por um
> `ROLLBACK`. Se o script falhar a meio, verifique o estado com as consultas
> abaixo antes de o correr outra vez.

## Confirmar que ficou bem

```sql
-- A tabela existe com os 4 índices esperados
SHOW CREATE TABLE `verification_codes`;

-- Deve devolver 0: nenhuma conta antiga ficou por activar
SELECT COUNT(*) AS por_activar FROM `users` WHERE `email_verified_at` IS NULL;

-- `code_hash` tem de aceitar NULL (activações por link)
SELECT `IS_NULLABLE` FROM `information_schema`.`COLUMNS`
 WHERE `TABLE_SCHEMA` = DATABASE()
   AND `TABLE_NAME` = 'verification_codes'
   AND `COLUMN_NAME` = 'code_hash';  -- esperado: YES

-- Devem aparecer as duas linhas
SELECT `migration`, `batch` FROM `migrations`
 WHERE `migration` LIKE '2026_09_23%';
```

Depois de a aplicação estar a correr, um teste de ponta a ponta:

```sql
-- Uma linha por activação ou código pedido. Em 'email_verification' o
-- code_hash é NULL; em 'password_reset' traz o hash (nunca o código em claro).
SELECT `id`, `email`, `type`, `code_hash` IS NULL AS `sem_codigo`,
       `attempts`, `expires_at`, `consumed_at`
  FROM `verification_codes`
 ORDER BY `id` DESC LIMIT 5;
```

## Variáveis de ambiente

Acrescente ao `.env` de produção (os valores abaixo são as predefinições, pode
omitir se servirem):

```
AUTH_VERIFICATION_LINK_EXPIRE_HOURS=48
AUTH_PASSWORD_CODE_EXPIRE_MINUTES=15
AUTH_PASSWORD_TOKEN_EXPIRE_MINUTES=15
```

Confirme também que o `APP_URL` aponta para o domínio público do backend — é a
partir dele que se constrói o link de activação enviado por email. E que o SMTP
está configurado (`MAIL_*`): sem envio de email ninguém recebe o link nem o
código, e ninguém consegue entrar em contas novas.

## Reverter

```bash
mysql -u UTILIZADOR -p NOME_DA_BD < 2026_09_23_000001_email_verification_codes_down.sql
```

Apaga os links e códigos pendentes. O `users.email_verified_at` não é revertido,
de propósito: depois de aplicado já não é possível distinguir as contas que
estavam activadas das que o script marcou, e limpá-lo trancaria toda a gente
fora.

## Manutenção (opcional)

Os códigos usados e expirados ficam na tabela como registo. Para os limpar
periodicamente:

```sql
DELETE FROM `verification_codes`
 WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
```
