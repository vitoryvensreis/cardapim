# Impressão pelo Deliveryzim Agent

Os botões **Imprimir** do caixa e do garçom mandam o cupom para o **Deliveryzim Agent** instalado no computador
da impressora (Windows). O navegador nunca abre a janela de impressão.

## Cupom desenhado no servidor (Agent 0.9.0+)

O servidor desenha cada cupom como **imagem preto e branco do tamanho do papel** (`app/Services/Impressao/CupomImagem.php`:
58 mm = 384 pontos, 80 mm = 576 pontos; fonte Poppins sem suavização; logo oficial `resources/img/logo-cupom.png`;
espaço antes do corte no fim da própria imagem). O Agent manda de cada impressora o **papel**, a **logo** e o **espaço
antes do corte** (`POST /api/v1/desktop/impressoras`, colunas `impressoras.papel`, `logo`, `margem_corte`), pede
`/eventos?imagem=1&principal=<chave da principal>` e recebe em cada cupom o campo `imagem` (PNG em base64) e `papel`.
Ele só converte em ESC/POS (GS v 0 em faixas de 128 linhas) e corta.

- A rota dos itens (qual impressora recebe o quê) não mudou: continua no servidor (`RotaProducao`).
- Cupom sem impressora escolhida usa o papel da **principal** do Agent (`principal`).
- Sem GD no servidor, ou se o desenho falhar, o cupom segue só com os `blocos` e o Agent monta como antes.
- Agent antigo (sem `imagem=1`) continua recebendo só os blocos. Agent antigo que reenvia a lista sem papel não apaga o
  papel já gravado.
- Gerência > Impressoras > Testar impressão mostra a mesma imagem que vai para a impressora.
- O Agent 0.9.0+ se atualiza sozinho (Releases do GitHub, SHA-256 conferido, instala com o Agent parado). Desde a 0.9.1
  o instalador usa a pasta do Agent (PyInstaller onedir), sem descompactar no Temp.
- **Aparência do cupom por loja** (Gerência > Impressoras > "Aparência do cupom"): fonte (Roboto Mono, padrão, ou Poppins),
  peso do texto, peso dos destaques (itens, títulos e total) e tamanho da letra (Pequena, Normal, Grande), com prévia em
  tempo real (`POST .../integracoes/impressoras/aparencia/previa`, mesmo desenho da impressão) e "Voltar ao padrão".
  Regras em `AparenciaCupom` (fonte única); tabela `restaurante_cupom_aparencia` (sem linha = padrão do sistema).
- Cada impressora mostra o papel informado pelo Agent (`impressoras.papel_informado`); o "Testar impressão" desenha no
  papel de cada impressora e respeita o aviso de cancelamento desligado e os produtos "só no local" no delivery.

## Regra (uma só para 1, 2 ou várias impressoras)

**1. Impressão por produto (sai sozinha ou pelo botão "Novos itens").** Cada item sai **só na impressora marcada
no produto** (Estoque > produto > *Sai na impressora*, ou o atalho por categoria). Produto sem impressora marcada sai
na **impressora padrão**. Um cupom por impressora, só com os itens dela (mesa ou pedido em letra grande, hora, quem
lançou, complementos e observações, sem o total). Acontece em:

| Momento | Quando |
|---------|--------|
| Delivery | quando o pedido é **aceito** (caixa, Receber do Agent, aceite no iFood ou aceite automático), uma vez por pedido |
| Garçom | quando toca em **Lançar** (os itens já saem impressos) |
| Caixa | quando toca em **Novos itens (N)** na comanda (balcão ou mesa; lançar pelo modal não imprime) |

Quando o pedido do delivery **chega**, nada imprime: o Agent 0.8+ recebe `impressoras: []` no evento e só avisa.

**2. Cupom completo (a pessoa pede).** Sai com **todos os itens**, sem cortar nada, na impressora escolhida:

| Tela | Botão | Cupom |
|------|-------|-------|
| Caixa > comanda (mesa ou balcão) | Conta | `conta`: itens, serviço, desconto, total, já pago, observação do balcão |
| Garçom > comanda | Imprimir > Comanda inteira | `pre_conta`: mesa e nome da comanda no topo, itens e total até agora |
| Caixa > delivery > pedido | Imprimir | `pedido`: cliente, telefone, endereço, itens, observação, pagamento e troco |
| Caixa > fechamentos > caixa | Imprimir | `fechamento`: troco inicial, formas, entradas/saídas, esperado e contado |

## Gerência > Integrações > Impressoras

1. **Impressoras cadastradas:** apelido, computador, ligada/desligada e quantos produtos estão marcados em cada uma.
2. **Impressão por produto:** escolhe **uma** impressora padrão (ponto `producao`) ou **Desligada** (nada imprime
   sozinho e o caixa não mostra "Novos itens"). Link para marcar a impressora dos produtos por categoria.
3. **Cupom completo:** uma lista com "Padrão", gravada nos pontos `caixa_conta`, `garcom` e `caixa_delivery`.
4. **Resumo do caixa:** ponto `caixa_fechamento`.

Cupom completo: ponto sem impressora = impressora principal de qualquer Agent; uma marcada = imprime direto; duas ou
mais = quem imprime escolhe (a padrão já vem marcada). Impressora fora do ponto ou desligada é recusada com o motivo.

Os pontos antigos `delivery_novo` e `garcom_lancar` saíram da tela e ficam vazios ao salvar.

**Exemplos:** 1 impressora = padrão e cupom completo nela. BALCÃO + COZINHA = bebida marcada no BALCÃO, comida na
COZINHA (padrão), cupom completo no BALCÃO. Churrascaria = espeto na CHURRASQUEIRA, baião na COZINHA, bebida no BALCÃO,
cupom completo no CAIXA.

## Regra da via de produção (`RotaProducao`)

Uma função só decide para onde vai cada item, usada pela impressão real, pelo aviso de cancelamento e pelo
**Testar impressão** (por isso o teste é fiel a qualquer mudança no cadastro):
- produto marcado **Não imprime** (ficha do produto ou atalho da categoria; `produtos.imprime_producao = 0`) não sai em
  nenhuma impressora;
- produto com impressoras marcadas sai nelas;
- o resto sai na impressora padrão da produção.

## Cancelamento

Item cancelado (caixa ou garçom), comanda cancelada e pedido do delivery cancelado imprimem **CANCELAMENTO** na
impressora de cada item (mesma regra acima), com a mesa/pedido, os itens ("Não preparar") e o motivo. Só sai o que já
tinha saído na produção: na comanda, itens até o último impresso; no delivery, quando as vias do aceite já foram.
Nunca impede o cancelamento (falha vai para o log). Tipo `cancelamento` em `impressoes`. Liga/desliga em Integrações >
Impressoras > "Aviso de cancelamento" (`restaurante_impressao_config.cancelamento`, padrão ligado).

## Testar impressão (Gerência > Integrações > Impressoras)

No fim da tela, o bloco **Testar impressão** monta um pedido com produtos cadastrados (quantidade e observação) e
mostra, para a situação escolhida (Delivery aceito, Garçom lança, Caixa: Novos itens ou Conta), o cupom de cada
impressora desenhado como no papel e o motivo de cada item estar ali. Impressora sem item aparece com "Não sai nada".

- Usa a mesma divisão por produto e o mesmo `CupomImpressao` da impressão real (`App\Services\Impressao\TesteImpressao`),
  sem gravar pedido. Rota: `POST /{slug}/gerencia/integracoes/impressoras/teste` (JSON, CSRF, só gerência).
- A tela quebra as linhas igual ao Agent (`printing.py`), na largura escolhida: 48, 42 ou 32 letras por linha.
  Letras fora do alfabeto da impressora (emoji) aparecem como "?" com aviso.
- Avisos: produtos sem impressora marcada (vão para a padrão) e impressora desligada que receberia itens.
- **Complementos e combos**: ao escolher um produto com grupos de complemento, a tela mostra as opções como no caixa
  (chips; no combo, "−/+" por opção, com os inclusos e o limite total) e marca cada grupo como obrigatório ou opcional.
  As escolhas vão como `complementos[]` = `id:qtd,id:qtd` por item e o servidor confere com `Complementos::conferir`
  e `Combo::conferir/montar` (o combo vira os itens separados, cada um na impressora dele). O teste ignora estoque.
- O papel desenhado traz a logo oficial (`public/assets/img/logo-preta.svg`) no topo, como o Agent imprime quando
  "Imprimir a logo do Deliveryzim no cupom" está marcado nele.
- **Imprimir teste de verdade** grava os mesmos cupons na fila com **TESTE** no topo (sem pedido ligado).

## Detalhes

- **Impressora desligada:** no Lançar do garçom, o lançamento nunca é desfeito; o garçom vê "Não imprimiu" e os itens
  ficam em "Novos itens" no caixa. No caixa, o envio inteiro é recusado com o nome da impressora (nada sai pela metade).
- "Novos itens" usa `impressoes.ate_item` (maior item já impresso, de cupom entregue a um Agent e sem erro).
- **Item cancelado** não sai na impressão por produto.
- A tela mostra uma situação única do envio dividido ("Impresso em Bar e Cozinha").
- **Agent 0.7** (sem lista de impressoras) só recebe cupons sem impressora escolhida. Agent anterior à 0.8 com
  impressão automática ligada no próprio Agent ainda imprime o pedido novo inteiro: atualize o Agent ou desligue a opção.

## Banco

- `20261120230000_create_impressoras.php` (`impressoras`, `impressao_pontos`, `impressoes.id_impressora`),
  `database/verify-impressoras.php`, `deploy/scripts/apply-impressoras.sh` (gatilho `[cardapim-impressoras]`).
- `20261121000000_impressao_por_produto.php` (`produto_impressoras`, ponto `producao`, `impressoes.lote`, tipo
  `producao`), `database/verify-impressao-produto.php`, `deploy/scripts/apply-impressao-produto.sh`.
- `20261121300000_impressao_lancamento.php` (só amplia o CHECK dos pontos; o ponto não é mais usado).
- `20261122400000_impressora_papel.php` (`impressoras.papel`, `logo`, `margem_corte`), `database/verify-impressora-papel.php`,
  `deploy/scripts/apply-impressora-papel.sh` (gatilho `[cardapim-impressora-papel]`; `Esquema::tem('impressora_papel')`).
- `20261122500000_aparencia_cupom.php` (`impressoras.papel_informado`, `restaurante_cupom_aparencia`),
  `database/verify-aparencia-cupom.php`, `deploy/scripts/apply-aparencia-cupom.sh` (gatilho `[cardapim-aparencia-cupom]`;
  `Esquema::tem('cupom_aparencia')`).

Testes: `tests/unit/impressao.php`, `tests/unit/cupom-imagem.php`, `tests/unit/aparencia-cupom.php`, `tests/http/impressao.sh`, `tests/http/impressoras.sh`,
`tests/http/impressao-produto.sh`, `desktop-agent/tests/test_printer_list.py`.

## Fluxo

1. O navegador envia `POST` (com CSRF). O servidor monta o cupom com os dados do banco (`CupomImpressao`)
   e grava em `impressoes`. Sem Agent que saiba imprimir ligado (consulta nos últimos 30 s), recusa na hora.
2. O Agent (0.7.0+) consulta `GET /api/v1/desktop/eventos?desde=N&impressoes=1` a cada 2,5 s. O servidor marca
   `desktop_agent_devices.impressao_em` e **reserva** os cupons pendentes para aquele computador (UPDATE condicionado:
   dois computadores nunca imprimem o mesmo cupom). Agent antigo (sem `impressoes=1`) nunca pega cupom.
3. O Agent imprime em ESC/POS (58 ou 80 mm, logo opcional) e informa `POST /api/v1/desktop/impressoes/{id}/resultado`
   com `{"ok": true}` ou `{"ok": false, "erro": "..."}`.
4. O navegador pergunta a situação (`aguardando`, `imprimindo`, `impresso`, `erro`, `expirada`) e mostra no aviso.
   Cupom não reservado em 2 minutos expira (nunca imprime atrasado de surpresa).

O cupom vai em **blocos** (`titulo`, `centro`, `destaque`, `texto`, `linha`, `valor`, `item`); o Agent só ajusta à
largura do papel, então um modelo novo de cupom não exige atualizar o Agent.

## Rotas

| Método | Rota | Quem |
|--------|------|------|
| POST | `/{slug}/caixa/imprimir/comanda/{id}` | caixa, gerente |
| POST | `/{slug}/caixa/imprimir/pedido/{id}` | caixa, gerente |
| POST | `/{slug}/caixa/imprimir/caixa/{id}` | caixa, gerente |
| GET | `/{slug}/caixa/imprimir/{id}` | situação do cupom |
| POST | `/{slug}/garcom/comandas/{id}/imprimir` (`modo=toda` ou `modo=novos`) | garçom, gerente |
| GET | `/{slug}/garcom/impressoes/{id}` | situação do cupom |
| POST | `/api/v1/desktop/impressoes/{id}/resultado` | Agent (token do dispositivo) |
| POST | `/api/v1/desktop/impressoras` | Agent 0.8+: lista de impressoras (chave + apelido) |
| GET/POST | `/{slug}/gerencia/integracoes/impressoras` | gerente: pontos de impressão |

Os botões aceitam o campo `impressora` (id), conferido no servidor contra o ponto.

Com `Accept: application/json` as rotas respondem JSON (202 enviado / 409 recusado com o motivo); sem JavaScript
o formulário volta para a página com o aviso.

## Observação do pedido de balcão

Na comanda do balcão (caixa): **Adicionar observação ao pedido** (até 300 caracteres, `pedidos.observacao`).
Aparece na conta e sai no cupom. Rota `POST /{slug}/caixa/comandas/{id}/observacao`.

## Banco

Migration `20261120200000_create_impressoes.php` (tabela `impressoes` + `desktop_agent_devices.impressao_em`),
`database/verify-impressoes.php` e `deploy/scripts/apply-impressoes.sh` (backup antes; gatilho
`[cardapim-impressoes]` no CI). Antes da migration os botões respondem que a impressão ainda está sendo ativada.

## Testes

`tests/unit/impressao.php` (cupom), `tests/http/impressao.sh` (fluxo completo, isolamento entre restaurantes,
Agent antigo, falha da impressora) e `desktop-agent/tests/test_print_jobs.py`.


## Agrupamento da comanda no cupom completo

Os cupons `conta` (caixa) e `pre_conta` (garcom) usam `AgrupadorItensComanda`: produtos identicos podem aparecer como `3x Cerveja Skol` em vez de tres linhas; combos continuam exibidos por lote com a composicao detalhada. Isso modifica somente os blocos de apresentacao no servidor, sem exigir atualizacao do Deliveryzim Agent.

As vias `itens_novos`, `producao` e `cancelamento` **continuam usando os lancamentos originais** e seus IDs. O agrupamento visual nao altera os marcadores de ultima impressao nem provoca reimpressao de lancamentos antigos.

Regressoes: `php tests/unit/agrupador-itens-comanda.php`.
