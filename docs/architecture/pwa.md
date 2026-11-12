# App instalavel (PWA) da equipe

O Cardapim pode ser instalado no celular (e no computador) como aplicativo a partir do **login** e do **cadastro**.
O app abre em tela cheia no `/login?origem=app`, que leva cada cargo direto para a sua area.

## Onde esta ligado

- `partials/head.php` com `$pwa = true`: manifesto, icones do celular (Android e iPhone) e `pwa.js`.
- Ligado no login, verificacao e cadastro (`AuthController` passa `'pwa' => true`) e nos layouts da equipe
  (`painel`, `app`, `entregador`). NAO e ligado na landing nem no cardapio do cliente final.

## Arquivos

| Arquivo | Funcao |
|---|---|
| `GET /pwa/manifesto` (`PwaController::manifesto`) | Manifesto: nome, cores (vinho), `display: standalone`, `scope: /`, icones 192/512/maskable |
| `GET /pwa/sw` (`PwaController::serviceWorker`) | Service worker (`public/assets/js/sw.js`) com `Cache-Control: no-cache` e `Service-Worker-Allowed: /` |
| `public/offline.html` | Tela "Voce esta sem internet" (estatica, sem dados de ninguem) |
| `public/assets/js/pwa.js` | Registra o service worker e mostra o convite para instalar |
| `app/Views/partials/pwa-instalar.php` | Convite no login/cadastro (comeca escondido) |
| `public/assets/img/pwa/*.png` | Icones gerados a partir do `icone.svg` oficial (sem redesenhar a marca) |

As rotas nao tem extensao (`.js`, `.webmanifest`) de proposito: o Nginx manda direto ao PHP, que define o tipo
certo e "sem cache" (o navegador precisa ver o service worker novo logo apos cada deploy). `pwa` e slug reservado.

## Regras do service worker (seguranca)

- Paginas: sempre da internet. Sem conexao, mostra `/offline.html`.
- NUNCA guarda paginas logadas nem respostas da API (dados do restaurante e de clientes ficam so no servidor).
- Guarda apenas os arquivos da pagina offline (`core.css`, `pages.css`, sprite de icones, logo).
- Mudou a pagina offline ou a lista de arquivos: trocar `VERSAO` em `sw.js`.

## Convite para instalar

- Android/Chrome/Edge (e computador): evento `beforeinstallprompt` -> botao "Instalar app".
- iPhone/iPad (Safari nao tem botao): passo a passo Compartilhar > Adicionar a Tela de Inicio.
- Outro navegador de celular sem convite automatico: depois de 2,5 s, caminho pelo menu do navegador.
- Ja aberto como app (`display-mode: standalone`): o convite nao aparece.

## Testes

`tests/http/pwa.sh` (manifesto, icones, service worker, pagina offline, onde o app e oferecido).
Conferido no Chrome: manifesto sem erros, service worker ativo e pagina offline sem internet.

## Pagina inicial fora do app

A landing (`/`) e so para o navegador. Aberta dentro do app instalado (`display-mode: standalone`, `fullscreen`,
`minimal-ui` ou `navigator.standalone` no iPhone), `js/so-no-navegador.js` (carregado sem `defer`, antes de desenhar a
pagina, via `$scriptsCedo` em `partials/head.php`) manda para `/login?origem=app`, que leva cada pessoa logada ao proprio
painel. No navegador comum nada muda. Teste: `tests/http/pwa.sh`.

