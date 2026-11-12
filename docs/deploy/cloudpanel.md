# Cardapim — CI/CD GitHub → VPS CloudPanel

**Estado:** pipeline criado, mas **deploy desligado por padrão**. A automação só estará ativa após a configuração do servidor, SSH e GitHub Actions. Não houve acesso remoto à VPS nesta entrega.

## Ambiente identificado pelos prints

- Domínio: deliveryzim.com.br (site do CloudPanel ainda chamado cardapim.escotilhaprodutora.com; ver troca-de-dominio.md)
- Usuário do site: escotilhaprodutora-cardapim
- Banco de dados: cardapim
- Usuário MySQL: cardapim-user
- Host/porta MySQL: 127.0.0.1:3306
- Nginx/CloudPanel e PHP-FPM; confirmar PHP CLI 8.4

Não colocar senha, chave SSH, token, arquivo .env ou arquivo mysql-backup.cnf no GitHub ou no chat.

## 1. Conferir o servidor ANTES de fazer alterações

No terminal SSH root do CloudPanel, executar comandos de leitura:

    id escotilhaprodutora-cardapim
    getent passwd escotilhaprodutora-cardapim
    command -v php8.4; php8.4 -v
    command -v composer
    command -v mysqldump
    nginx -t
    ls -ld /home/escotilhaprodutora-cardapim
    ls -la /home/escotilhaprodutora-cardapim/htdocs

Não remover nada do diretório atual da hospedagem. Verificar no painel CloudPanel qual arquivo Vhost pertence ao domínio.

## 2. Preparar diretórios do projeto

Confirmar que o usuário de site realmente é escotilhaprodutora-cardapim e pode executar SSH. Se o CloudPanel bloquear shell desse usuário, utilizar uma conta de deploy dedicada, não-root, com permissão controlada sobre a pasta da aplicação.

Exemplo para preparar a estrutura usando o terminal root, depois de conferir o home:

    SITE_USER=escotilhaprodutora-cardapim
    SITE_HOME=$(getent passwd "$SITE_USER" | cut -d: -f6)
    test -n "$SITE_HOME" && test -d "$SITE_HOME" || exit 1
    install -d -o "$SITE_USER" -g "$SITE_USER" -m 750 "$SITE_HOME/apps/cardapim"
    install -d -o "$SITE_USER" -g "$SITE_USER" -m 700 "$SITE_HOME/apps/cardapim/shared"
    install -d -o "$SITE_USER" -g "$SITE_USER" -m 700 "$SITE_HOME/apps/cardapim/shared/backups"
    install -d -o "$SITE_USER" -g "$SITE_USER" -m 750 "$SITE_HOME/apps/cardapim/releases"

Criar o arquivo "$SITE_HOME/apps/cardapim/shared/.env" com permissão 600, pertencente ao usuário do site; inserir a senha diretamente no editor do servidor (não no chat):

    APP_NAME=Cardapim
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://deliveryzim.com.br
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=cardapim
    DB_USERNAME=cardapim-user
    DB_PASSWORD="PREENCHER_SOMENTE_NA_VPS"

Em produção, proteger a pasta shared e o arquivo .env contra leitura de outros usuários do sistema. O script de deploy compartilha o .env e os diretórios storage/ entre releases; não sobrescreve segredos.

## 3. Criar uma chave SSH dedicada ao GitHub Actions

Criar um par de chaves Ed25519 **no seu computador** (fora da VPS), sem compartilhar a chave privada pelo chat.

- No Windows, usar PowerShell com ssh-keygen; guardar a chave privada local em local seguro até inseri-la em um GitHub Actions Secret.
- Copiar somente a chave PUBLICA para ~/.ssh/authorized_keys do usuario de deploy no servidor.
- O usuário SSH não deve ser root e deve ter escrita apenas nas pastas necessárias do projeto.
- Testar conexão SSH com essa chave antes de ativar o deploy.
- Registrar a chave de host SSH do servidor, verificando sua fingerprint diretamente no servidor; guardar o resultado conhecido no Secret CARDAPIM_VPS_KNOWN_HOSTS. Não desabilitar StrictHostKeyChecking.

## 4. Ajustar o Vhost Nginx no CloudPanel

Como o Nginx NÃO interpreta .htaccess, editar o Vhost do site no CloudPanel com cuidado.

- Fazer backup do Vhost atual antes de alterar.
- A diretiva root deve apontar para /home/escotilhaprodutora-cardapim/apps/cardapim/current/public (ou caminho equivalente validado).
- Usar try_files $uri $uri/ /index.php?$query_string; no location / do vhost.
- Manter os blocos de PHP-FPM e SSL existentes, sem duplicar location.
- Bloquear acesso a arquivos ocultos, .env, .git e scripts internos.
- Confirmar o usuario que executa PHP-FPM tem leitura dos arquivos e diretorios do projeto.
- Executar nginx -t antes de recarregar o Nginx.
- Não modificar sites ou certificados de outros projetos.

O destino current/public pode ainda não existir no primeiro ajuste do Vhost; a primeira release criará o symlink current. O healthcheck do deploy exige o domínio HTTPS apontando corretamente ao site. Se o Vhost antigo servir outra pagina, o deploy falha e não declara sucesso.

## 5. Configuração do GitHub Actions

Repositório → Settings → Secrets and variables → Actions.

**Secrets** (valores protegidos):

- CARDAPIM_VPS_HOST: IP do servidor (o print mostrou 2.25.194.141; confirmar que permanece correto).
- CARDAPIM_VPS_USER: usuário de deploy NÃO root.
- CARDAPIM_VPS_SSH_PRIVATE_KEY: chave privada Ed25519 exclusiva de deploy.
- CARDAPIM_VPS_KNOWN_HOSTS: entrada SSH do host, cuja fingerprint foi verificada.

**Variables**:

- CARDAPIM_DEPLOY_ROOT = /home/escotilhaprodutora-cardapim/apps/cardapim
- CARDAPIM_DEPLOY_URL = https://deliveryzim.com.br
- CARDAPIM_VPS_SSH_PORT = 22 (ou porta SSH real)
- CARDAPIM_DEPLOY_ENABLED = false inicialmente; mudar para true SOMENTE após confirmar SSH e Vhost.
- CARDAPIM_RUN_MIGRATIONS = false inicialmente; ativar após configurar backup e testar a migração.

O workflow testa as rotas, envia release por rsync, executa o script deploy/scripts/activate-release.sh via SSH, ativa o symlink current, testa o HTTP e desfaz a troca do symlink se o healthcheck falhar. Usar Actions → "Cardapim - CI e deploy" → Run workflow para a primeira publicação após habilitar.

## 6. Atualizações de banco de dados

O runner CLI é database/migrate.php:

    php8.4 database/migrate.php --status
    php8.4 database/migrate.php --apply

Migrations são arquivos YYYYMMDDHHMMSS_nome.php sob database/migrations/ que retornam uma função recebendo PDO. Exemplo:

    <?php
    return static function (PDO $db): void {
        $db->exec('CREATE TABLE exemplo (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    };

O runner cria a tabela schema_migrations, protege contra execução concorrente com GET_LOCK, registra hash SHA-256 de cada arquivo e recusa edições de migrations já aplicadas. As mudanças são exclusivamente **forward-only**: não editar nem apagar migrations antigas.

**Antes de habilitar migrations automáticas**, criar em shared/mysql-backup.cnf um arquivo privado, chmod 600, pertencente ao usuário de deploy, com credenciais MySQL que permitam executar mysqldump do banco cardapim. Não versionar esse arquivo. O script de deploy executa mysqldump --single-transaction, comprime o backup em shared/backups/ e só então aplica migrations. Configurar retenção e cópia EXTERNA dos backups, pois backups só na mesma VPS não protegem contra perda do servidor.

DDL no MySQL provoca commits implícitos: um rollback dos arquivos NÃO desfaz migrations. Revisar cada migration antes de mesclar na main; aplicar alterações destrutivas somente com aprovação e estratégia de migração de dados. O banco já foi criado no CloudPanel, mas a aplicação ainda não criou as tabelas do negócio.

## 7. Rotina depois da ativação

1. Solicitar alteração no chat.
2. O assistente analisa os arquivos reais e desenvolve no GitHub.
3. Commit / merge em main inicia testes automaticamente.
4. Com DEPLOY_ENABLED=true, o GitHub Actions publica na VPS.
5. Com RUN_MIGRATIONS=true e backup configurado, aplica novas migrations versionadas.
6. Validar logs/healthcheck e reportar commit, execução dos testes, deploy e migrations.

**Nunca presumir deploy realizado apenas pelo commit.** É obrigatório conferir o status do workflow e a resposta do ambiente de produção. Nunca compartilhar segredos pelo chat.

## Rotinas agendadas (Cron Jobs do CloudPanel, usuário do site)

| Quando | Comando | Para que |
| --- | --- | --- |
| A cada minuto (`* * * * *`) | `php8.4 /home/<usuario>/apps/cardapim/current/bin/ifood.php >> /home/<usuario>/apps/cardapim/shared/storage/logs/ifood.log 2>&1` | Pedidos e avisos do iFood a cada 30 s, ações e disponibilidade ([ifood.md](../architecture/ifood.md)). Sai sem fazer nada enquanto `IFOOD_CLIENT_ID/SECRET` não estiverem no `shared/.env`. |
| Uma vez por dia | `php8.4 /home/<usuario>/apps/cardapim/current/bin/cobranca-diaria.php` | Bloqueio de restaurantes com fatura atrasada além da tolerância. |

## Segurança

- Deploy com usuário limitado, sem acesso root ou escrita em outros sites.
- Secrets e MySQL fora do repositório; chaves SSH dedicadas.
- Não executar SQL arbitrário vindo de mensagens de chat: alterações passam por migrations revisadas e backup.
- O pipeline só publica se os testes passarem.
- Não expor /storage, /app, /.env ou /database na web.
- O primeiro deploy depende de configuração manual segura do servidor, SSH e Vhost.

**Próxima ação:** coletar a saída dos comandos de leitura da seção 1 para validar o layout real do CloudPanel; depois completar a ativação SSH e Vhost.

## Arquivos persistentes do painel do gerente

- `shared/storage/uploads/logos/`: logos enviadas pelos restaurantes (servidas pela rota `/midia/logos/{nome}-{ext}`, sem extensao no endereco porque o bloco de arquivos estaticos do CloudPanel responde 404 a `.png`/`.webp`/`.svg` inexistentes em `public/`; o Nginx nao precisa expor a pasta nem mudar o vhost).
- `shared/storage/keys/app.key`: chave AES-256 do segredo do aplicativo autenticador, criada automaticamente na primeira ativacao (permissao 0600). Copiar para o backup externo junto com o dump do banco. Alternativa: definir `APP_KEY` (base64 de 32 bytes) no `shared/.env`.
- E-mail do codigo da verificacao em duas etapas usa o `mail()` do PHP. Antes de recomendar o metodo por e-mail aos clientes, confirmar que o servidor entrega mensagens (SPF/DKIM do dominio). Se o e-mail nao chegar, a ativacao simplesmente nao conclui (nada fica trancado).
