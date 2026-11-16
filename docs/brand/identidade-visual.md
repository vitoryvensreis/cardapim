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

Toda a plataforma utiliza **Google Sans Flex** (família variável, peso 100–900), pelos tokens `--f-head` e `--f-body` no `public/assets/css/core.css`.

- **400 Regular:** textos, formulários, notas e conteúdos operacionais
- **600 Semibold:** destaques e rótulos pontuais quando necessário
- **700 Bold:** títulos, botões e indicadores prioritários
- Pesos extremos 100/900 ficam disponíveis, mas não são o padrão de leitura da interface

Os arquivos CSS antes padronizados em 900 foram ajustados para 700, evitando uma interface excessivamente pesada. O Google Fonts só carrega após aceite opcional de cookies: `app/Views/partials/head.php` e `public/assets/js/consentimento.js`; sem consentimento usa-se a fonte de fallback local.

Os arquivos das logomarcas e ícones oficiais permanecem intactos.

## Hero e demonstração da landing

A home mantém título, descrição, ações, três vantagens e a demonstração na ordem estabelecida. O mockup **não é screenshot nem sistema real**: é uma demonstração local isolada da API e do banco, baseada na organização visual do painel gerencial e do caixa.

- `app/Views/marketing/_demo.php`: estrutura semântica com botões para Visão geral, Pedidos e Caixa
- `public/assets/css/site-demo.css`: estilos próprios, rolagem interna, navegação por teclado e alvos de toque confortáveis
- `public/assets/js/site-demo.js`: roteiro de chegada do pedido, aceite, abertura do caixa e adição de produto ilustrativo; interação manual com os mesmos controles
- Ao colocar o mouse sobre a demo ou navegar com foco por teclado, o roteiro pausa. Após um clique, mantém controle manual até reiniciar. Sem som, vibração, tilt ou requisições à API
- Em `prefers-reduced-motion`, a animação automática não inicia, mas os botões permanecem operantes
- No celular, os três destinos são apresentados como abas superiores e o conteúdo interno pode rolar sem recortar controles. Em tablets e desktops, a navegação lateral se assemelha à interface real

O selo "Tudo conectado" e o efeito 3D ao mover o mouse foram removidos. As demais seções da landing e a identidade vinho, branco e laranja foram mantidas.

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
