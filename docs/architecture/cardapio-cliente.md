# Cardapio online e cliente final

Pagina publica `/{slug}` onde o cliente escolhe produtos, monta a sacola e entra com o WhatsApp.
A finalizacao (endereco, entrega, pagamento) esta em [pedidos.md](pedidos.md).

## Experiencia (UX)

Referencias usadas: padrao dos apps de delivery (iFood, Rappi), Baymard Institute (sacola persistente e
visivel), web.dev (formulario de codigo por SMS/WhatsApp) e boas praticas de OTP da Twilio.

- **Topo fino e fixo**: voltar para o catalogo, nome do restaurante aparece ao rolar, botao "Entrar"/conta.
- **Capa**: logo, nome, status com texto e icone (nao so cor), bairro/cidade e "Ver endereco e horarios".
  Restaurante fechado mostra aviso, mas o cliente pode montar a sacola.
- **Busca + abas de categoria** fixas, com scroll-spy (a aba ativa acompanha a rolagem); busca ignora acentos.
- **Produto em lista**: nome, descricao curta, preco e botao laranja "+"; selo "2x" quando ja esta na sacola;
  indisponivel aparece no fim da categoria, desabilitado e com texto.
- **Folha do produto** (`<dialog>` que sobe de baixo): observacao, quantidade e "Adicionar R$ X".
  Fecha arrastando para baixo, pelo fundo ou Esc.
- **Sacola**: barra fixa "Ver sacola · N itens · R$ X" no celular/tablet; painel lateral fixo no computador
  (>= 64rem). Editar, mudar quantidade, remover e "Continuar".
- **Login em 3 passos curtos**: numero -> codigo (envio automatico ao completar 6 digitos,
  `autocomplete="one-time-code"`, reenviar apos 60 s) -> nome (so para conta nova).
- Mobile: areas de toque >= 44px, `safe-area-inset`, inputs de 16px (sem zoom no iOS), sem rolagem horizontal,
  `prefers-reduced-motion` respeitado. Testado em 360, 390, 820 e 1440 px.

## Arquivos

| Camada | Arquivo |
| --- | --- |
| Rota da pagina | `RestauranteController::show` (layout `layouts/cardapio`) |
| Views | `app/Views/restaurante/cardapio.php`, `_dialogos.php`, `app/Views/layouts/cardapio.php` |
| Dados do cardapio | `app/Services/Cardapio/CardapioPublico.php` |
| Cliente | `app/Repositories/ClienteRepository.php`, `app/Services/Cliente/*` |
| API | `app/Controllers/Api/ClienteController.php`, `RestauranteController::sacola` |
| Front | `public/assets/css/cardapio.css`, `public/assets/js/cliente.js`, `public/assets/js/cardapio.js` |
| Testes | `tests/unit/cliente.php`, `tests/http/cardapio.sh` |
| Banco | `database/migrations/20261110110000_create_clientes.php`, `database/verify-clientes.php` |

## Rotas

| Metodo | Rota | Uso |
| --- | --- | --- |
| GET | `/{slug}` | Cardapio publico |
| POST | `/api/v1/restaurantes/{slug}/sacola` | Confere itens da sacola: preco atual, indisponiveis e removidos |
| GET | `/api/v1/cliente` | Sessao: `csrf`, `cliente` (ou null) e `login_disponivel` |
| POST | `/api/v1/cliente/codigo` | `{telefone}` envia o codigo pelo WhatsApp da plataforma |
| POST | `/api/v1/cliente/entrar` | `{codigo}` -> `etapa: pronto` (conta existente) ou `etapa: nome` |
| POST | `/api/v1/cliente/cadastro` | `{nome}` conclui conta nova (ate 15 min depois do codigo) |
| POST | `/api/v1/cliente/sair` | Encerra a sessao e apaga o aparelho lembrado |

POSTs do cliente exigem o cabecalho `X-CSRF-Token` (419 sem ele) e corpo JSON de ate 4 KB.

## Sacola

- `localStorage` em `cardapim:sacola:v1:{slug}` (uma por restaurante), expira em 2 dias.
- Cada linha e `produto + observacao`; o mesmo produto com observacoes diferentes vira linhas separadas.
- O navegador so guarda IDs, quantidades e observacoes para exibir. **O servidor recalcula tudo**;
  preco vindo do navegador nunca e usado. Valores em centavos inteiros a partir do `DECIMAL`.

## Login do cliente

- Sem senha: WhatsApp (10-11 digitos, sem 55) + codigo de 6 digitos + nome na primeira vez.
- O nome e pedido **depois** do codigo: a API nao revela quais numeros ja tem conta.
- Codigo guardado so como HMAC (`Cripto::derivarChave('cliente-codigo-v1')`) na sessao PHP; 10 min; 5 tentativas; uso unico.
- Limites: reenviar apos 60 s; 5 envios/hora por numero; 20/hora por IP; 30 verificacoes/15 min por IP.
- Enviado pela integracao WhatsApp da **plataforma** (`id_restaurante` NULL). Desconectada -> login indisponivel (503) e o botao mostra o aviso.
- Sessao do cliente (`cliente_id`) separada da equipe; `session_regenerate_id` ao entrar.
- "Lembrar aparelho": cookie `cardapim_cliente` (60 dias, HttpOnly, Secure, SameSite=Lax), seletor + validador com hash SHA-256 em `cliente_sessoes`,
  rotacionado a cada uso; reuso de cookie antigo revoga todas as sessoes do cliente.
- Auditoria em `log_user` (sem `id_user`): "Cliente: login", "Cliente: cadastro", "Cliente: saiu", "Cliente: alerta de seguranca".
- O navegador recebe so nome, primeiro nome e WhatsApp formatado; **nunca** CPF ou endereco.

## Tabelas

`clientes`: `nome`, `whatsapp` (unico), `cpf` (unico, opcional), `cep`, `logradouro`, `numero`, `complemento`,
`bairro`, `cidade`, `estado`, `ponto_referencia`, `latitude`, `longitude`, `ativo`, `whatsapp_verificado_em`,
`ultimo_acesso`, datas. CHECKs para nome, WhatsApp, CPF, CEP, UF e coordenadas.
O cliente e da plataforma (o mesmo numero pede em qualquer restaurante); os pedidos e que terao `id_restaurante`.

`cliente_sessoes`: aparelhos lembrados (`seletor` unico, `validador_hash`, `expira_em`, IP, navegador), FK com `ON DELETE CASCADE`.

## Finalizacao do pedido

Feita: entrega ou retirada, endereco, pagamento e acompanhamento. Ver [pedidos.md](pedidos.md).

## Seus pedidos (historico e voltar ao acompanhamento)

- Folha da conta (botao com o nome no topo): "Seus pedidos" com o filtro **Neste restaurante | Todos**, 10 por vez
  ("Ver pedidos mais antigos"). Cada cartao mostra numero, quando ("Hoje, 19:40"), total, resumo dos itens e o status
  com icone + texto; toque abre `/{slug}/pedido/{id}`. A lista e recarregada sempre que a conta abre.
- Cardapio: aviso **"Acompanhar"** para cada pedido em andamento neste restaurante (ultimas 24 h, ate 3), atualizado a
  cada 30 s enquanto houver pedido andando e quando a aba volta a ficar visivel. Substitui o atalho antigo que dependia
  do aparelho (localStorage): agora vale em qualquer aparelho em que o cliente entrar.
- API `GET /api/v1/cliente/pedidos` (so o cliente conectado; 60 consultas/min). Nunca lista comandas de mesa/balcao.
- Codigo: `Services/Pedidos/HistoricoCliente`, `PedidoRepository::{historicoDoCliente, emAndamentoDoCliente, itensResumo}`,
  `public/assets/js/meus-pedidos.js`, `public/assets/css/meus-pedidos.css`. Testes em `tests/http/entregas.sh` e `tests/unit/entrega.php`.
