# Migrations SQL (MySQL / produção)

Equivalente em SQL puro das migrations Laravel desta funcionalidade, para
ambientes onde se aplica o esquema directamente na base de dados em vez de
correr `php artisan migrate`.

| Ficheiro | O que faz |
|---|---|
| `2026_09_23_000001_email_verification_codes_up.sql` | Marca as contas existentes como confirmadas, cria `verification_codes` e regista as duas migrations |
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
`NOT EXISTS`): executá-lo duas vezes não duplica nada.

> Nota sobre a transacção: o `START TRANSACTION` protege o `UPDATE` aos
> utilizadores e as inserções na tabela `migrations`, mas o MySQL faz *commit*
> implícito em instruções DDL — o `CREATE TABLE` não é revertido por um
> `ROLLBACK`. Se o script falhar a meio, verifique o estado com as consultas
> abaixo antes de o correr outra vez.

## Confirmar que ficou bem

```sql
-- A tabela existe com os 4 índices esperados
SHOW CREATE TABLE `verification_codes`;

-- Deve devolver 0: nenhuma conta ficou por confirmar
SELECT COUNT(*) AS por_confirmar FROM `users` WHERE `email_verified_at` IS NULL;

-- Devem aparecer as duas linhas
SELECT `migration`, `batch` FROM `migrations`
 WHERE `migration` LIKE '2026_09_23%';
```

Depois de a aplicação estar a correr, um teste de ponta a ponta:

```sql
-- Deve aparecer uma linha por cada código pedido, com o hash (nunca o código)
SELECT `id`, `email`, `type`, `attempts`, `expires_at`, `consumed_at`
  FROM `verification_codes`
 ORDER BY `id` DESC LIMIT 5;
```

## Variáveis de ambiente

Acrescente ao `.env` de produção (os valores abaixo são as predefinições, pode
omitir se servirem):

```
AUTH_VERIFICATION_CODE_EXPIRE_MINUTES=30
AUTH_PASSWORD_CODE_EXPIRE_MINUTES=15
AUTH_PASSWORD_TOKEN_EXPIRE_MINUTES=15
```

E confirme que o SMTP está configurado (`MAIL_*`) — sem envio de email ninguém
recebe o código e ninguém consegue entrar em contas novas.

## Reverter

```bash
mysql -u UTILIZADOR -p NOME_DA_BD < 2026_09_23_000001_email_verification_codes_down.sql
```

Apaga os códigos pendentes. O `users.email_verified_at` não é revertido, de
propósito: depois de aplicado já não é possível distinguir as contas que estavam
confirmadas das que o script marcou, e limpá-lo trancaria toda a gente fora.

## Manutenção (opcional)

Os códigos usados e expirados ficam na tabela como registo. Para os limpar
periodicamente:

```sql
DELETE FROM `verification_codes`
 WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
```
