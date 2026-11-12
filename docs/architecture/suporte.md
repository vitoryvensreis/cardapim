# Suporte (chamados)

O gerente pede ajuda abrindo um chamado; a equipe da plataforma (`adm_master`) responde no próprio painel.
Os dois lados recebem aviso por e-mail ([configurar o e-mail](../deploy/email.md)).

## Telas e rotas

| Quem | Rota | O que faz |
| --- | --- | --- |
| Gerente | `GET /{slug}/gerencia/suporte` (`?filtro=abertos|resolvidos|todos`) | Chamados do restaurante; os com resposta nova ficam destacados |
| Gerente | `GET /{slug}/gerencia/suporte/novo`, `POST /{slug}/gerencia/suporte` | Abrir chamado: assunto, sobre o que é, mensagem |
| Gerente | `GET /{slug}/gerencia/suporte/{id}` | Conversa (marca a resposta como vista) |
| Gerente | `POST .../{id}/responder`, `POST .../{id}/resolver` | Responder (reabre se estava resolvido) e marcar como resolvido |
| Plataforma | `GET /painel/suporte` (`?filtro=aguardando|abertos|resolvidos|todos&busca=&pagina=`) | Todos os chamados; os que esperam resposta primeiro |
| Plataforma | `GET /painel/suporte/{id}` | Conversa, dados do restaurante e de quem abriu |
| Plataforma | `POST /painel/suporte/{id}/responder` (`mensagem`, `resolver=1`) | Responder e, se quiser, resolver junto |
| Plataforma | `POST /painel/suporte/{id}/status` (`status=aberto|em_atendimento|resolvido`) | Mudar a situação |

Selos no menu: "Resposta nova" para o gerente (resposta do suporte ainda não vista) e "N novos" para a plataforma
(chamados abertos cuja última mensagem é do restaurante).

## Permissões e segurança

- Gerente: `AcessoRestaurante::verificar($slug, 'gerencia')`; todas as consultas filtram por `id_restaurante` do restaurante
  do login (chamado de outro restaurante responde "Chamado não encontrado"). Caixa, garçom e entregador recebem 403.
- Plataforma: só `adm_master` (`BasePlataforma`). Todo POST exige CSRF.
- Texto guardado puro e mostrado com `htmlspecialchars` + `nl2br`; caracteres de controle removidos; assunto 3–120, mensagem 10–4000.
- Limite: 20 chamados novos por restaurante por dia.
- Auditoria (`log_user`): abrir, responder, resolver e mudar situação.
- E-mails enviados depois da resposta da tela (`Mailer::agendar`), sem travar quem clicou.

## Tabelas (migration `20261112160000_create_suporte_chamados`)

- `suporte_chamados`: `id_restaurante`, `id_user` (quem abriu), `assunto`, `categoria`
  (`duvida|problema|pedidos|financeiro|integracoes|sugestao|outro`), `status` (`aberto|em_atendimento|resolvido`, CHECK com
  `resolvido_em`), `ultima_de` (`restaurante|suporte`), `ultima_mensagem_em`, `visto_restaurante_em`, `visto_suporte_em`.
- `suporte_mensagens`: conversa (`origem` restaurante/suporte, `texto`), FK composta `(id_chamado, id_restaurante)` para
  nunca misturar restaurantes.

Código: `Repositories/SuporteRepository`, `Services/Suporte/SuporteService`, `Controllers/Gerencia/SuporteController`,
`Controllers/Plataforma/SuporteController`, views `gerencia/suporte/*`, `plataforma/suporte/*`, parciais `suporte/_selo.php`
e `suporte/_conversa.php`, CSS `suporte.css`. Teste: `tests/http/suporte.sh`.
