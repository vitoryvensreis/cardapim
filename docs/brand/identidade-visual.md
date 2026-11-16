# Identidade visual oficial — Cardapim

Arquivos fornecidos pelo projeto e versionados no repositorio:

- `public/assets/img/logo-colorida.svg`: cabecalho da landing sobre fundo vinho.
- `public/assets/img/logo-branca.svg`: rodape e superficies escuras.
- `public/assets/img/logo-preta.svg`: alternativa para fundos claros.
- `public/assets/img/icone.svg`: icone do app e favicon.
- `public/assets/img/favicon.svg`: copia de compatibilidade com o icone oficial.

## Paleta obrigatoria

| Cor | Hex | Uso |
| --- | --- | --- |
| Vinho | `#651424` | Identidade, blocos e painel |
| Branco | `#FFFFFF` | Contraste e espaco negativo |
| Laranja | `#FF5825` | Acoes primarias e destaque |

Todos os tokens base da landing foram atualizados em `public/assets/css/site.css`.
Para componentes adicionais, usar variacoes de opacidade e tons auxiliares apenas quando necessario para legibilidade.

## Observacao sobre os arquivos

Os SVGs originais usam internamente `#651423` e `#FE5826` em alguns caminhos,
enquanto os hexadecimais oficiais informados sao `#651424` e `#FF5825`.
Os assets originais foram preservados; os tokens e estilos CSS utilizam os valores oficiais exatos.

## Tipografia oficial

A interface utiliza **Londrina Solid**, de Marcelo Magalhães (licença SIL Open Font License), em todos os módulos: landing, catálogo, cardápio, gerência, caixa, cozinha e demais áreas. A definição central fica em `public/assets/css/core.css`, nos tokens `--f-head` e `--f-body`.

| Peso disponível | Utilização recomendada |
| --- | --- |
| 100 (Thin) | Composições decorativas pontuais |
| 300 (Light) | Textos auxiliares grandes, quando legíveis |
| 400 (Regular) | Corpo, formulários, descrições e rótulos secundários |
| 900 (Black) | Títulos, botões e dados em destaque |

A família **não possui 500, 600, 700 ou 800**. Declarações antigas foram mapeadas para os pesos reais 400/900; `font-synthesis: none` impede negrito ou itálico artificiais. Campos de código podem manter fonte monoespaçada por precisão.

Como no projeto original, as fontes do Google são carregadas apenas quando o visitante aceita os cookies opcionais. O carregamento inicial é em `app/Views/partials/head.php` e o aceite sem recarregar usa `public/assets/js/consentimento.js`. Quem não consente usa a fonte de fallback instalada no dispositivo. Não inserir imports externos que ignorem essa escolha.

Os arquivos das logomarcas oficiais não foram alterados.
## Hero da landing

- Menu continua no topo com os links e a marca oficiais.
- Conteúdo da hero em coluna centralizada: título **Seu restaurante cresce. Deliveryzim acompanha.**, descrição, botões de ação, três vantagens e painel ilustrativo original.
- Título em Londrina Solid Black 900, com espaçamento positivo de letras (`.035em` em telas amplas; `.025em` nos celulares) para impedir a sobreposição de caracteres.
- Painel ilustrativo reduzido para 70% da largura da área da hero em desktop, com recorte vertical intencional na base. No tablet e celular ganha largura adaptativa, sem rolagem horizontal.
- Componentes internos do mockup, a paleta, o gradiente, os SVGs oficiais e os links foram mantidos; CSS permanece em `public/assets/css/site.css`.
- Breakpoints em `40rem` (celular/tablet), `64rem` (desktop) e `120rem` (ultrawide), com animações respeitando `prefers-reduced-motion`.
## Regras de implementacao

- Nao recriar o logotipo com texto ou fonte.
- Usar `img` com dimensoes para evitar saltos de layout.
- Manter logo colorida em fundo escuro, branca em superfícies escuras, preta em claras, conforme contraste.
- Respeitar `prefers-reduced-motion`, foco visivel e responsividade.
- Nunca inserir credenciais ou dados operacionais reais em elementos conceituais da landing.


## Icones

- Biblioteca unica: **Lucide** (ISC), via sprite local `public/assets/icons/lucide.svg` e helper `Cardapim\Core\Icon::svg()`.
- Traco 2px, cantos arredondados, cor herdada do texto (`currentColor`). Tamanhos padrao: 1rem, 1.25em e 1.6rem.
- Icone decorativo recebe `aria-hidden`; quando o icone e o unico conteudo de um botao, usar `aria-label` no botao.
- Emojis sao proibidos nas interfaces.
