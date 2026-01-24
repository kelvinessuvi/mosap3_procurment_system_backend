# Guia de Atualização - cPanel

Este guia descreve os passos para atualizar o backend da aplicação no cPanel.

## Opção 1: Atualização via ZIP (Mais simples para hospedagem partilhada)

Se não tiver acesso SSH ou Git no servidor:

1.  **Prepare o pacote localmente:**
    Para facilitar, criei um script que faz o trabalho sujo. No terminal:
    ```bash
    ./create_deploy_package.sh
    ```
    Isto vai gerar o ficheiro `deploy_update.zip` sem os ficheiros desnecessários (git, tests, node_modules, etc).

    *Nota: Se não conseguir rodar o script, pode comprimir manualmente a pasta, mas exclua `node_modules`, `.git` e a pasta `storage` para não substituir os logs/ficheiros do servidor.*

2.  **Upload no cPanel:**
    *   Aceda ao **Gerenciador de Arquivos** no cPanel.
    *   Navegue para a pasta da aplicação (ex: `public_html/api` ou onde instalou).
    *   Faça upload do `update.zip`.
    *   Extraia o arquivo e substitua os existentes.

3.  **Atualizar Dependências e Base de Dados:**
    Se tiver acesso ao terminal do cPanel, corra:
    ```bash
    cd /caminho/para/pasta
    composer install --optimize-autoloader --no-dev
    php artisan migrate --force
    php artisan optimize
    ```
    
    *Se NÃO tiver terminal:*
    *   Certifique-se que fez upload da pasta `vendor` atualizada no passo 1.
    *   Para rodar migrações, pode criar uma rota temporária em `routes/web.php` (apague depois!):
        ```php
        Route::get('/migrate', function() {
            \Artisan::call('migrate --force');
            return 'Migrated!';
        });
        ```

## Opção 2: Atualização via Git (Recomendado)

Se configurou o Git no cPanel:

1.  **Aceda ao Terminal (SSH) do cPanel.**
2.  **Navegue para a pasta:**
    ```bash
    cd /caminho/do/projeto
    ```
3.  **Puxe as alterações:**
    ```bash
    git pull origin main
    ```
4.  **Execute os comandos de manutenção:**
    ```bash
    composer install --optimize-autoloader --no-dev
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    ```

## Permissões

Garanta que as pastas `storage` e `bootstrap/cache` têm permissões de escrita (775 ou 755).

## Verificação

1.  Aceda à API e verifique se responde.
2.  Verifique o log em `storage/logs/laravel.log` se houver erros 500.
