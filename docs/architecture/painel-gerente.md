# Painel do gerente

Menu lateral fixo no computador e em gaveta no celular/tablet (`layouts/gerencia.php`, `css/painel.css`, `js/painel.js`).
Configuracao unica das secoes: `app/Services/Painel/MenuGerencia.php` (rotulo, icone e rota de cada secao).

| Grupo | Item | Rota | Situacao |
| --- | --- | --- | --- |
| - | Visao geral | `/{slug}/gerencia` | em construcao (graficos depois) |
| Operacao | Caixa | `/{slug}/caixa` | abre o **modo caixa** em tela cheia; o gerente tem "Voltar a gerencia" |
| Operacao | Entregas | `/{slug}/entregas` | gerente: fila de pedidos e escolha do entregador; entregador: as proprias entregas |
| Operacao | Mesas | `/{slug}/gerencia/mesas` | em construcao |
| Gestao | **Estoque** | `/{slug}/gerencia/estoque` | **pronto**: resumo, produtos, insumos, categorias |
| Gestao | **Equipe** | `/{slug}/gerencia/equipe` | **pronto**: caixas, garcons e entregadores |
| Gestao | Financeiro | `/{slug}/gerencia/financeiro` | em construcao |
| Restaurante | **Perfil** | `/{slug}/gerencia/perfil` | **pronto**: nome, logo, telefone, endereco e horario |
| Restaurante | **Integracoes** | `/{slug}/gerencia/integracoes` | **pronto**: Asaas, WhatsApp (WPPConnect), iFood ([detalhes](integracoes.md)) |
| Restaurante | **Assinatura** | `/{slug}/gerencia/assinatura` | **pronto**: ver, assinar, trocar, cancelar (sem cobranca automatica) |
| Conta | **Minha conta** | `/{slug}/gerencia/minha-conta` | **pronto**: dados, senha, 2FA, aparelhos, ultimos acessos |
| Conta | Suporte | `/{slug}/gerencia/suporte` | em construcao (chat e chamados) |

- Todas as rotas de `/{slug}/gerencia/*` exigem o cargo `gerente` do mesmo restaurante (ou `adm_master`), conferido no backend em cada requisicao (`Services/Auth/AcessoRestaurante`). Secao desconhecida: 404.
- As rotas das abas prontas ficam em `routes/gerencia.php`, carregado **antes** de `/{slug}/gerencia/{secao}` (tela "em construcao" das demais).

## Base comum (todas as abas)

- `Controllers/Gerencia/BaseGerencia`: acesso (cargo + restaurante), CSRF em todo POST, padrao Post/Redirect/Get com aviso (flash) e tela com o layout do painel.
- Regras de negocio nos `Services/Gerencia/*` (validacao + gravacao + log); SQL nos `Repositories` (sempre com `id_restaurante`).
- Erro de formulario: a tela volta com **422**, o que a pessoa digitou e a mensagem em cada campo, mais um resumo no topo com links para os campos (`Shared/Html/Campo`).
- Acoes destrutivas pedem confirmacao numa janela propria (`<dialog>`, `js/confirmar.js`), nunca `confirm()` do navegador. Sem JS, o envio acontece direto (o servidor valida tudo).
- Busca instantanea nas listas (`data-filtro`), sem recarregar a pagina e sem diferenciar acentos.
- Listas viram cartoes no celular (rotulo de cada dado em `data-rotulo`) e tabela no computador.
- Status sempre com texto e icone (nunca so cor): "Em falta", "Baixo", "Em dia", "Pausado", "Bloqueado".

## Auditoria (log_user)

Todo cadastro, edicao e exclusao grava em `log_user` (`Services/Auditoria`): titulo, descricao com **o que mudou** ("Preco: 24,90 -> 26,90; Ficha tecnica: ... -> ...") e o nome do restaurante entre colchetes. Nunca entram senhas, codigos, segredos ou CPF completo (CPF vai mascarado).

Titulos usados: `Insumo cadastrado/editado/excluido`, `Estoque reposto`, `Categoria cadastrada/editada/excluida`, `Produto cadastrado/editado/excluido/pausado/liberado para venda`, `Equipe: conta criada/editada/excluida`, `Equipe: acesso bloqueado/liberado`, `Equipe: senha redefinida`, `Perfil do restaurante editado`, `Logo do restaurante alterada/removida`, `Horario de funcionamento alterado`, `Assinatura contratada`, `Plano trocado`, `Assinatura cancelada`, `Cancelamento desfeito`, `Minha conta: dados alterados`, `Minha conta: senha alterada`, `Verificacao em duas etapas ligada/desligada`, `Codigos de recuperacao trocados`, `Aparelho(s) desconectado(s)`, `Verificacao recusada`.

## Estoque

| Rota | O que faz |
| --- | --- |
| `GET /estoque` | **Resumo**: acabaram, estoque baixo, fora de venda, valor em estoque; lista "Precisa comprar" com reposicao rapida; "Fora de venda" (com o motivo) e "Acabando em breve" (da para vender so mais 10 ou menos) |
| `GET /estoque/produtos` (`?filtro=a-venda|indisponiveis`) | lista com preco, situacao e quantas unidades ainda da para vender |
| `GET/POST /estoque/produtos/novo`, `/estoque/produtos/{id}` | formulario com ficha tecnica dinamica, categoria nova na hora e custo/sobra estimados |
| `POST /estoque/produtos/{id}/pausar` | pausar / voltar a vender |
| `POST /estoque/produtos/{id}/excluir` | exclui produto e ficha (transacao) |
| `GET /estoque/insumos` (`?filtro=atencao|ok`) | lista com barra de nivel, minimo e custo |
| `GET/POST /estoque/insumos/novo`, `/estoque/insumos/{id}` | unidade (un, kg, L), quanto tem, minimo, custo, ativo |
| `POST /estoque/insumos/{id}/repor` | soma a quantidade que chegou (com trava da linha) |
| `POST /estoque/insumos/{id}/excluir` | so se nao estiver em nenhuma ficha |
| `GET/POST /estoque/categorias`, `POST /estoque/categorias/{id}`, `.../excluir` | criar, renomear, excluir (produtos ficam sem categoria) |

Regras: insumo por unidade nao aceita fracao; a unidade nao muda se o insumo estiver em alguma ficha; nome unico por restaurante (sem diferenciar maiusculas); produto "baixar dos insumos" exige ao menos um insumo na ficha; insumo de outro restaurante nunca entra na ficha (validado no servico e pela FK composta). Analise em `Modules/Inventory/AnaliseEstoque` (pura, testada).

## Equipe

Rotas: `GET /equipe` (`?funcao=caixa|garcom|motoqueiro`), `GET /equipe/nova`, `POST /equipe`, `GET/POST /equipe/{id}`, `POST /equipe/{id}/senha`, `POST /equipe/{id}/acesso`, `POST /equipe/{id}/excluir`.

- O gerente so gerencia caixa, garcom e entregador (`motoqueiro`) **do proprio restaurante**; nao cria nem promove gerente.
- Usuario, e-mail e CPF sao unicos no sistema todo. E-mail e opcional: sem e-mail, a conta recebe um endereco tecnico `usuario@equipe.cardapim.invalid` (nunca recebe mensagens).
- "Gerar uma senha facil de passar" (ex.: `Praia-milho-4821`) e "Copiar usuario e senha" para mandar no WhatsApp.
- Bloquear acesso: `ativo = 0` vale na hora (a sessao e recarregada a cada requisicao) e desconecta os aparelhos lembrados.
- Excluir: conta sem historico em `log_user` e apagada; com historico e **anonimizada** (`removido_{id}`, dados pessoais apagados, `ativo = 0`, `excluido_em`), preservando a auditoria. O nome `removido_*` nao pode ser escolhido.

## Perfil

Rotas: `GET /perfil`, `POST /perfil` (dados e endereco), `POST /perfil/logo`, `POST /perfil/logo/remover`, `POST /perfil/horarios`.

- Nome, telefone (obrigatorio), e-mail de contato e endereco completo (CEP, UF em lista, cidade, bairro, rua, numero ou S/N, complemento, referencia). Mudou o endereco: latitude/longitude sao limpas.
- CNPJ e endereco da pagina (slug) sao so leitura (mudanca pelo suporte). Trocar o nome nao muda o slug.
- Logo (`Modules/Restaurante/LogoUpload`): PNG, JPG ou WebP ate 2 MB, conferidos por `finfo` + `getimagesize` (SVG recusado), regravada com GD (WebP, ate 512 px, sem metadados), nome aleatorio em `storage/uploads/logos/` e servida por `GET /midia/logos/{32hex}.{ext}` (`MidiaController`, cache longo, `nosniff`). A logo antiga e apagada.
- Horario (`Modules/Restaurante/HorarioFuncionamento`): "Sempre aberto" ou horarios por dia da semana, ate 2 turnos por dia, turno que passa da meia-noite (18:00-02:00), "usar este horario em todos os dias". Selo "Aberto agora / Fechado agora" no topo.

## Assinatura

Rotas: `GET /assinatura`, `POST /assinatura/assinar` (`plano`), `POST /assinatura/cancelar`, `POST /assinatura/reativar`.

- Mostra o plano vigente (ativa, cancelada ate o fim do periodo, ou vencida), os planos publicos e o historico.
- Assinar cria o periodo a partir de hoje (`AssinaturaService::somarMeses`: 31/01 + 1 mes = 28/02). Trocar encerra a atual e cria a nova (uma vigente por vez, com trava do restaurante). Cancelar mantem o acesso ate `vence_em`; da para desfazer ate la. Cancelada e vencida vira `encerrada` automaticamente.
- **Sem cobranca automatica ainda**: a tela avisa que a equipe do Cardapim combina o pagamento. A integracao de pagamento entra depois.

## Minha conta

Rotas: `GET /minha-conta`, `POST /minha-conta/dados`, `POST /minha-conta/senha`, `GET/POST /minha-conta/dois-fatores/app`, `POST /minha-conta/dois-fatores/email`, `GET /minha-conta/dois-fatores/email`, `POST /minha-conta/dois-fatores/email/confirmar`, `POST /minha-conta/dois-fatores/desativar`, `GET/POST /minha-conta/codigos`, `POST /minha-conta/aparelhos/{id}/sair`, `POST /minha-conta/aparelhos/sair-todos`.

- Dados: nome, sobrenome e telefone (usuario e e-mail so pelo suporte).
- Senha: pede a atual, a nova (8+) e a confirmacao; desconecta os outros aparelhos lembrados e renova a sessao.
- Verificacao em duas etapas (detalhes em [autenticacao.md](autenticacao.md#verificacao-em-duas-etapas)): aplicativo autenticador (QR Code gerado no navegador + chave manual) ou codigo por e-mail. So liga depois de digitar um codigo valido. Gera 10 codigos de recuperacao, mostrados uma unica vez (copiar, baixar .txt, imprimir). Desligar e gerar novos codigos pedem a senha.
- Aparelhos com "manter conectado": lista, desconectar um ou todos os outros (o aparelho atual e identificado).
- Ultimos acessos: logins, recusas e alertas da propria conta.

## Testes

- `tests/unit/gerencia.php`: TOTP (vetores da RFC 6238), criptografia, codigos de recuperacao, horarios, numeros, analise de estoque, auditoria e datas da assinatura.
- `tests/integration/gerencia.php` (MySQL no CI): servicos com os CHECKs/FKs reais, isolamento entre restaurantes e logs.
- `tests/http/gerencia.sh` + `tests/http/verificar-gerencia-ci.php`: todas as telas e acoes por HTTP (CSRF, 403 entre restaurantes, upload, 2FA por app/e-mail/recuperacao) e conferencia dos logs.
