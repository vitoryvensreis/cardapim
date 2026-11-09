# Fase 0A — Estrutura MVC, URLs amigaveis e landing page de vendas

## O que foi entregue
- Fundacao real em PHP 8.4 com bootstrap, autoload PSR-4 (Composer instalado ou fallback nativo) e MVC simples.
- Classes app/Core/Router.php, Request.php, Response.php, View.php; controllers de marketing e acesso.
- Rotas amigaveis /, /login e /cadastro. Suporte a parametros em rotas futuras, por exemplo /loja/{slug}.
- Landing page com hero animado em CSS, previa conceitual do painel, recursos, funcionamento, publicos e chamada comercial.
- Botao Entrar no canto superior direito, menu mobile, navegacao por ancoras, animacoes com respeito a preferencias de movimento reduzido.
- /login e /cadastro sao telas de preparacao: autenticacao, cadastro, checkout e operacao do restaurante ainda NAO estao implementados.
- .htaccess na raiz (fallback Apache), public/.htaccess (Apache com document root correto) e snippet de Nginx.
- Arquivos composer.json, .env.example, .gitignore, favicon SVG; modulos futuros preparados com .gitkeep.

## Executar localmente

Requisitos: PHP 8.4 ou superior. A landing funciona sem banco, Node nem dependencias externas.

    git clone https://github.com/vitoryvensreis/cardapim.git
    cd cardapim
    php -S localhost:8000 -t public dev/router.php

Abrir http://localhost:8000; testar /login e /cadastro.

Composer e opcional por enquanto:

    composer install
    composer dump-autoload

## Implantar no CloudPanel / Nginx

1. Apontar o DocumentRoot do dominio para a pasta /public da aplicacao.
2. Manter app/, routes/, .env, storage/ e quaisquer credenciais FORA do webroot.
3. Em Nginx, aplicar try_files $uri $uri/ /index.php?$query_string; ao location / ja existente. Ver deploy/nginx/locations.conf.example. Nginx NAO le .htaccess.
4. Verificar PHP-FPM 8.4 e HTTPS ja configurado.
5. Testar HTTP 200: /, /login, /cadastro, /assets/css/site.css, /assets/js/site.js, /assets/img/favicon.svg. Validar que /app e /.env nao sao publicos.
6. Nao incluir .env em Git. Verificar logs do vhost caso haja 404.

## Apache e .htaccess

Preferir DocumentRoot /caminho/cardapim/public, AllowOverride All e mod_rewrite. O arquivo public/.htaccess encaminha rotas nao fisicas ao front controller. O .htaccess da raiz e apenas compatibilidade; nao substitui a configuracao segura do DocumentRoot.

## Proximas etapas (aguardando orientacao)

- Login real, sessoes, autorizacao RBAC e isolamento de empresas (tenants).
- Migracoes MySQL e onboarding de restaurantes/unidades.
- Dashboards e modulos operacionais.
- WebSocket, Asaas, WPPConnect e iFood conforme o roadmap do README.

**Importante:** dados da previa do painel sao ficticios e ilustrativos. Esta entrega e o inicio do codigo, nao a finalizacao do SaaS.
