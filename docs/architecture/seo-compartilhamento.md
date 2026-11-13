# Google e redes sociais (SEO e link compartilhado)

Como o site aparece no Google e no cartao do link quando alguem manda o endereco no WhatsApp, Instagram, Facebook,
X ou Telegram. Tudo que e da plataforma e editado pelo adm_master em **Configuracoes > Google e redes sociais**
(`/painel/configuracoes#seo`); o que e de cada restaurante sai sozinho dos dados do restaurante.

## O que cada pagina publica tem (`app/Views/partials/head.php`)

| Item | Onde vem |
| --- | --- |
| `<title>` e `description` | pagina inicial: `seo_titulo` e `seo_descricao` (vazio = texto padrao de `Services\Seo\Seo`); outras paginas: o proprio controlador |
| `link rel="canonical"` e `og:url` | `APP_URL` + caminho, sem parametros (`?mesa=1`, `?busca=`). Paginas com `noIndex` nao tem canonical |
| `og:type`, `og:locale` (pt_BR), `og:site_name` | `website`; cardapio usa `restaurant.restaurant` |
| `og:image` (+ `secure_url`, tipo, 1200 x 630, `alt`) e `twitter:card` `summary_large_image` | imagem da plataforma ou do restaurante (abaixo), sempre com endereco completo |
| `google-site-verification` | `google_verificacao` (so o codigo; o painel aceita a etiqueta inteira colada) |
| icones | favicon com o tipo certo (SVG ou PNG enviado), PNG 192 e `apple-touch-icon` em todas as paginas |
| JSON-LD | pagina inicial: `Organization` e `WebSite`; cardapio: `Restaurant` (nome, endereco, telefone, coordenadas, `hasMenu`) |

Uma pagina pode mandar `seo` para a view: `imagem` (array de `Seo::imagemRestaurante`), `titulo` (titulo do link sem a
marca), `tipo` e `jsonLd` (lista de arrays). O JSON-LD sai com `JSON_HEX_TAG`, entao nenhum texto fecha o `<script>`.

## Imagem do link

- **Plataforma** (`GET /midia/cartao?v=...`): JPG 1200 x 630 desenhado com GD (`Services\Seo\CartaoCompartilhamento`):
  fundo vinho, icone do app, nome da plataforma, `frase_compartilhamento` e o dominio. Muda sozinho quando o nome, a
  frase ou o icone do app mudam. O icone so entra se foi ENVIADO no painel (icone do app, ou favicon em PNG/JPG/WebP);
  os arquivos originais do repositorio nunca entram (sao da marca antiga) e, sem icone enviado, o cartao sai so com o
  nome e a frase. Mesma regra para o restaurante: so a logo enviada por ele, senao as iniciais (o `?v=` e um resumo desses dados, entao WhatsApp e Facebook pedem a imagem nova).
- **Arte enviada pelo adm** (`imagem_compartilhamento`): PNG/JPG/WebP de 600 x 315 ou mais, recortada pelo centro e
  regravada em JPG 1200 x 630 (`MarcaUpload::salvarCompartilhamento`), guardada em `storage/uploads/marca` e servida
  em `/midia/marca/{nome}-jpg`. "Voltar a imagem automatica" limpa a chave.
- **Restaurante** (`GET /midia/cartao/{slug}?v=...`): faixa vinho com a logo do restaurante (ou as iniciais), nome,
  "Cardapio online · Peca pelo celular", bairro e cidade e o endereco do cardapio. Usada no cardapio, no finalizar e no
  acompanhamento do pedido (link que vai pelo WhatsApp). So restaurantes ativos; logo so do proprio servidor (nunca
  baixa imagem de outro site).
- Desenho em dobro e reduzido (bordas lisas), fonte Poppins (licenca OFL, `resources/fonts`), JPG qualidade 86
  (cerca de 40 a 60 KB). Cache em `storage/cache/cartoes/{resumo}.jpg`: so desenha de novo quando algo muda. Sem GD com
  FreeType, o endereco redireciona para o icone do app.

## Buscadores

- `GET /robots.txt`: libera o site e bloqueia `/painel`, `/api/`, `/minha-conta`, verificacao de login, recuperar senha,
  `/pwa/` e as areas da equipe (`/*/gerencia`, `/*/caixa`, `/*/garcom`, `/*/entregas`); aponta o sitemap.
- `GET /sitemap.xml`: paginas publicas fixas (inicio, catalogo, cadastro e paginas legais) e o cardapio de cada
  restaurante ativo, com a data da ultima alteracao.
- Finalizar, acompanhar pedido, login e areas internas continuam com `noindex`.

## Configuracoes (tabela `configuracoes`, sem migration)

`seo_titulo` (ate 70), `seo_descricao` (ate 160), `frase_compartilhamento` (ate 90), `imagem_compartilhamento`
(caminho da arte) e `google_verificacao`. Rotas: `POST /painel/configuracoes/seo`, `/painel/configuracoes/compartilhamento`
e `/painel/configuracoes/compartilhamento/padrao` (somente adm_master, com CSRF e registro na auditoria).

## Depois de publicar

1. Cadastrar o site no Google Search Console (opcao "Tag HTML"), colar o codigo no painel e enviar `/sitemap.xml`.
2. Conferir o cartao em https://developers.facebook.com/tools/debug/ ("Buscar novamente" limpa a previa antiga).
3. O WhatsApp guarda a previa de cada link por alguns dias; links enviados antes podem demorar a mudar.

O deploy informa no log `SEO /robots.txt`, `SEO /sitemap.xml` e `SEO /midia/cartao` com o codigo e o tipo (sem reverter).
