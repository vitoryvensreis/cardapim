# Painel do garçom

Tela feita para o celular do garçom (`/{slug}/garcom`). Ele entra com usuário e senha e cai direto nas mesas.
Acesso: cargo `garcom` e `gerente` do restaurante (ou `adm_master`), conferido no servidor a cada requisição
(`AcessoRestaurante::verificar($slug, 'garcom')`). Todo POST tem CSRF. O caixa não entra aqui, e o garçom não entra no modo caixa nem na gerência.

## Telas

| Tela | Rota | O que faz |
| --- | --- | --- |
| Mesas | `GET /{slug}/garcom` | Mesas desenhadas (livre/ocupada com total e tempo), filtro Todas/Livres/Ocupadas. Atualiza sozinha (tempo real, canal `mesas`, e a cada 30 s). |
| Abrir mesa | `POST /{slug}/garcom/mesas/{id}/abrir` | Abre a primeira comanda da mesa ou leva para a mais antiga aberta. Mesa com mais de uma comanda mostra "2 comandas" e os nomes. |
| Nova comanda | `POST /{slug}/garcom/mesas/{id}/comandas` | Quem chegou depois: comanda separada na mesma mesa, com o nome da pessoa (opcional). |
| Dar nome | `POST /{slug}/garcom/comandas/{id}/nome` | Nome da pessoa na comanda (vazio tira o nome). As abas da mesa no topo da comanda mostram nome, total e itens de cada uma. |
| Comanda | `GET /{slug}/garcom/comandas/{id}` | Total, itens (com observação, hora e quem lançou), remover item com motivo em um toque. Barra fixa: **Adicionar** e **Fechar conta**. |
| Adicionar | `#adicionar` na comanda, `POST .../comandas/{id}/itens` | Folha do cardápio: busca, categorias, botões − / + por produto, observação por produto e "Lançar 3 itens · R$ X". Vários produtos em um envio (`qtd[id]`, `obs[id]`). Funciona sem JavaScript (`:target`). |
| Remover item | `POST .../comandas/{id}/itens/{item}/cancelar` | Motivo por chip (Lancei errado, Cliente desistiu, Demorou). Estoque devolvido, log de auditoria. |
| Cancelar comanda | `POST .../comandas/{id}/cancelar` | Botão discreto "Cancelar comanda" na conta: motivo por chip (Abri por engano, Cliente foi embora, Cliente desistiu) e confirmação. Devolve o estoque de todos os itens, libera a mesa e registra no log (`Comanda cancelada`, com o nome do garçom). Conta com pagamento não pode ser cancelada pelo garçom: só o caixa remove pagamentos. Conta cancelada aberta de novo volta às mesas com aviso. |
| Fechar conta | `GET .../comandas/{id}/conta` | Itens, chave **10% serviço** (`POST .../servico`), total, dividir por pessoas (só na tela), formas de pagamento em blocos grandes, valor, "Cliente deu" com notas rápidas e troco ao vivo. Conta paga: tela "Conta paga, mesa livre" com o troco. |
| Receber | `POST .../comandas/{id}/pagamentos` | Dinheiro, débito, crédito ou Pix (chave/maquininha). Mesmas regras do caixa (`ComandaService::pagar`): precisa de caixa aberto. |
| QR Code Pix | `POST .../comandas/{id}/pix`, `GET /{slug}/garcom/pix/{id}` | Só aparece com o Asaas do restaurante conectado. QR Code grande, copia e cola, "Esperando o pagamento". A tela consulta a cada 3 s (JSON com `Accept: application/json`) e fecha sozinha quando pago. |
| Trocar forma | `POST /{slug}/garcom/pix/{id}/cancelar` | Cancela o Pix (remove a cobrança no Asaas). |
| Cardápio | `GET /{slug}/garcom/cardapio` | Consulta: preço, "Tem / Restam N / Acabou / Pausado", busca por nome ou preço e filtro Tem/Em falta. |

## Pix na mesa (Asaas do restaurante)

- `Services/Garcom/ComandaPix`: cobrança Pix na conta Asaas **do restaurante** para o que falta pagar (mínimo R$ 1,00), `externalReference = comanda:{id_pedido}`, válida 30 min.
- O Asaas exige um pagador: na mesa não pedimos CPF ao cliente; usamos o cadastro "Clientes do salão" com o **CNPJ do restaurante**
  (tabela `asaas_clientes_salao`, criado uma vez por restaurante e ambiente). Restaurante sem CNPJ: o garçom vê a mensagem e recebe de outra forma.
- Gerar de novo com o mesmo valor mostra o mesmo QR; se o valor mudou (item novo, 10% tirado), o QR antigo é cancelado.
- Confirmação pelo aviso do Asaas (`WebhookController`, `PAYMENT_RECEIVED/CONFIRMED`) ou pela consulta da tela (no máximo a cada 5 s).
  Idempotente: `UNIQUE cobranca_id` + `UPDATE ... WHERE status <> 'pago'`.
- Confirmado = pagamento `pix_online` ("Pix pelo app") no caixa aberto; pagou tudo = conta fechada e mesa livre (`ComandaService::fecharPaga`).
- Pago depois de trocar a forma: entra se ainda faltar; o que sobrar fica na linha do tempo do pedido para devolver pelo Asaas.

## Tabelas (migration `20261111160000_create_comanda_pix.php`)

- `comanda_pix`: id, id_restaurante, id_pedido (FK composta), id_caixa (FK composta), id_user, cobranca_id (UNIQUE), ambiente, valor DECIMAL,
  copia_cola, status (pendente/pago/cancelado), criado_em, expira_em, pago_em.
- `asaas_clientes_salao`: (id_restaurante, ambiente) PK, id_externo.
- Implantação: commit com `[cardapim-garcom]` roda `deploy/scripts/apply-garcom.sh` (backup do MySQL antes + `database/verify-garcom.php`).

## Código

`Controllers/GarcomController`, `routes/garcom.php`, `Services/Garcom/ComandaPix`, `Views/layouts/garcom.php`, `Views/garcom/*`,
`public/assets/css/garcom.css`, `public/assets/js/garcom.js` (reaproveita o desenho das mesas e o troco de `caixa.css`).

## Testes

`tests/http/garcom.sh`: acesso por cargo, abrir mesa, lançar vários itens com observação, remover item, 10%, dinheiro com troco,
Pix pelo aviso e pela consulta (sem entrar duas vezes), QR trocado quando o valor muda, trocar a forma, débito e consulta do cardápio.
