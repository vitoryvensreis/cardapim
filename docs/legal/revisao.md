# Cardapim — documentos legais e consentimento de cookies

Atualizado em 12/11/2026. Os textos descrevem o que o sistema **realmente faz** hoje (dados, cookies, serviços de
terceiros, prazos). **Recomenda-se revisão por advogado** antes de considerar os textos definitivos.

## Páginas

| Rota | View | Conteúdo |
| --- | --- | --- |
| `/privacidade` | `legal/privacidade.php` | Controlador, dados por perfil, finalidades e bases legais, compartilhamentos, retenção, segurança, direitos |
| `/termos-de-uso` | `legal/termos.php` | Serviço, contas, planos e cobrança (Asaas, tolerância), cancelamento pelo painel, responsabilidades, uso proibido |
| `/cookies` | `legal/cookies.php` | Lista real de cookies e armazenamento local, item opcional (Google Fonts), botão "Alterar minhas preferências" |
| `/exclusao-de-dados` | `legal/exclusao.php` | Como pedir, o que acontece com cada tipo de conta, o que a lei manda guardar, prazos |
| `/tratamento-de-dados` | `legal/tratamento.php` | Controlador x operador, compromissos de cada parte, suboperadores, segurança, incidentes |

- Topo comum em `legal/_header.php` (título, resumo, data, menu entre as políticas) e caixa "Em poucas palavras" em
  `legal/_resumo.php`. Sem selo "em revisão" e sem rótulo acima do título.
- Canal do titular: `PRIVACIDADE_EMAIL` no `.env`; vazio usa `privacidade@<domínio do APP_URL>`.
  **Criar essa caixa ou alias no mailcow** (ex.: `privacidade@deliveryzim.com.br` encaminhando para quem atende).
- Abertas pelo cardápio (`?r=slug`), os links entre as políticas continuam presos ao cardápio (LegalController).

## Aviso de cookies (LGPD)

- `partials/consentimento.php` (no fim do `body` dos layouts app, painel, garçom, entregador, cardápio, público e
  marketing), `public/assets/js/consentimento.js` e `public/assets/css/consentimento.css`.
- Botões "Só os necessários" e "Aceitar todos". A escolha fica no cookie `cardapim_consentimento`
  (`todos.AAAAMMDD` ou `necessarios.AAAAMMDD`, 12 meses, `SameSite=Lax`, `Secure` em HTTPS).
- **Google Fonts só carrega com "Aceitar todos"** (`Cardapim\Shared\Consentimento::aceitouTodos()` em
  `partials/head.php`; sem aceite o site usa as fontes do aparelho). Ao aceitar, o JS adiciona as fontes na hora.
- Reabrir: qualquer elemento com `data-consentimento-abrir` (rodapé do site "Preferências de cookies", rodapé do
  cardápio "Cookies" e o botão da página `/cookies`).
- Cookies necessários (sessão, lembrar, cliente, consentimento) e armazenamento local (sacola, último endereço, som dos
  alertas) não dependem de consentimento. Não há analytics nem marketing. Se algum dia entrar, precisa de nova categoria
  no aviso antes de carregar.

## Pendências fora do código

1. Revisão jurídica dos cinco textos.
2. Criar o e-mail `privacidade@` no mailcow e definir quem responde (prazo informado: 15 dias).
3. Rotina de backup diário do banco (hoje há cópia antes de cada mudança de schema no deploy). Os Termos não prometem
   backup diário.
4. Quando a 99Food for integrada, incluí-la na lista de compartilhamentos da Privacidade e de suboperadores do
   Tratamento de Dados.
