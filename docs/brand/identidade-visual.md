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

## Regras de implementacao

- Nao recriar o logotipo com texto ou fonte.
- Usar `img` com dimensoes para evitar saltos de layout.
- Manter logo colorida em fundo escuro, branca em superfícies escuras, preta em claras, conforme contraste.
- Respeitar `prefers-reduced-motion`, foco visivel e responsividade.
- Nunca inserir credenciais ou dados operacionais reais em elementos conceituais da landing.
