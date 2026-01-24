# Configuração do Git no cPanel

Este guia explica como configurar o **Git Version Control** no cPanel para fazer deployments automáticos.

## 1. Preparar o Projeto

1.  **Arquivo `.cpanel.yml`:**
    Já criei este arquivo na raiz do projeto. Ele diz ao cPanel quais arquivos copiar e para onde.
    *   **Atenção:** Abra o ficheiro `.cpanel.yml` e edite a linha `DEPLOYPATH`.
    *   Substitua `/home/USER/public_html/api/` pelo caminho real no seu servidor (ex: `/home/oseuutilizador/public_html/api/`).

2.  **Commit e Push:**
    Envie o arquivo `.cpanel.yml` para o seu repositório remoto (GitHub/GitLab):
    ```bash
    git add .cpanel.yml
    git commit -m "Add cpanel deploy config"
    git push origin main
    ```

## 2. Configurar no cPanel

1.  Aceda ao cPanel e procure por **Git™ Version Control**.
2.  Clique em **Create** (ou Criar).
3.  Preencha os dados:
    *   **Clone URL:** URL do seu repositório (ex: `git@github.com:user/repo.git` ou HTTPS).
    *   **Repository Path:** Onde o git ficará guardado (ex: `repositories/mosap3-backend`). *Nota: Isto NÃO é a pasta pública.*
    *   **Branch Name:** `main` (ou a branch que quer usar).
4.  Clique em **Create**.

## 3. Configurar Chaves SSH (Se usar URL Privado/SSH)

Se o repositório for privado, precisa autorizar o cPanel:

1.  No cPanel, vá a **SSH Access** -> **Manage SSH Keys**.
2.  Gere uma nova chave (ou use uma existente) no cPanel.
3.  Copie a **Public Key**.
4.  Vá ao GitHub/GitLab -> Settings -> Deploy Keys -> Add Deploy Key.
5.  Cole a chave.

## 4. Deploy Manual vs Automático

### Manual (Botão)
1.  Vá a **Git™ Version Control**.
2.  Clique em **Manage** no repositório.
3.  Vá à aba **Pull or Deploy**.
4.  Clique em **Update from Remote** (para baixar as alterações).
5.  Clique em **Deploy HEAD Commit** (para copiar os arquivos para a pasta pública conforme o `.cpanel.yml`).

### Automático (Avançado)
Para ser automático, precisa de configurar um Webhook. Mas o método acima (Pull + Deploy) é o mais seguro e comum.

## 5. Pós-Deploy (Finalização)

Sempre que fizer deploy, lembre-se de rodar este comando no Terminal do cPanel (dentro da pasta `public_html/api`):

```bash
# Instalar dependências se houver novas
composer install --no-dev

# Migrar base de dados
php artisan migrate --force

# Limpar e recriar caches
php artisan optimize
```
