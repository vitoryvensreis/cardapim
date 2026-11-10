# Painel do gerente

Menu lateral fixo no computador e em gaveta no celular/tablet (`layouts/gerencia.php`, `css/painel.css`, `js/painel.js`).
Configuracao unica das secoes: `app/Services/Painel/MenuGerencia.php` (rotulo, icone, rota e o que cada secao vai ter).

| Grupo | Item | Rota | Situacao |
| --- | --- | --- | --- |
| - | Visao geral | `/{slug}/gerencia` | em construcao (graficos depois) |
| Operacao | Caixa | `/{slug}/caixa` | abre o **modo caixa** em tela cheia; o gerente tem "Voltar a gerencia" |
| Operacao | Entregas | `/{slug}/entregas` | gerente: fila de pedidos e escolha do entregador; entregador: as proprias entregas |
| Operacao | Mesas | `/{slug}/gerencia/mesas` | em construcao |
| Gestao | Estoque | `/{slug}/gerencia/estoque` | em construcao (produtos, insumos, ficha tecnica) |
| Gestao | Equipe | `/{slug}/gerencia/equipe` | em construcao (garcons, entregadores, caixas) |
| Gestao | Financeiro | `/{slug}/gerencia/financeiro` | em construcao |
| Restaurante | Perfil | `/{slug}/gerencia/perfil` | em construcao (nome, logo, endereco) |
| Restaurante | Integracoes | `/{slug}/gerencia/integracoes` | em construcao (iFood, Asaas) |
| Restaurante | Assinatura | `/{slug}/gerencia/assinatura` | em construcao |
| Conta | Minha conta | `/{slug}/gerencia/minha-conta` | em construcao (dados, senha, aparelhos) |
| Conta | Suporte | `/{slug}/gerencia/suporte` | em construcao (chat e chamados) |

- Todas as secoes de `/{slug}/gerencia/*` exigem o cargo `gerente` do mesmo restaurante (ou `adm_master`). Secao desconhecida: 404.
- Para construir uma secao: criar a view/controller dela e trocar a tela "em construcao" daquela chave; o menu e as rotas ja existem.
- Testes: `tests/http/auth.sh` abre todas as secoes como gerente e master e confere os bloqueios de caixa e entregador.
