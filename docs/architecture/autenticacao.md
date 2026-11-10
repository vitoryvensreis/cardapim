# Autenticacao e acesso por cargo

## Fluxo

1. `GET /login` mostra o formulario (e-mail **ou** nome de usuario + senha) com token CSRF.
2. `POST /login` valida CSRF, limite de tentativas, senha (`password_verify`), conta ativa e status do restaurante.
3. Sucesso: novo ID de sessao (`session_regenerate_id`), `ultimo_acesso` atualizado, registro em `log_user` e redirecionamento **303** para a area do cargo.
4. Se a pessoa tentou abrir uma area antes de entrar, volta para ela, desde que o cargo tenha permissao.
5. `POST /sair` (com CSRF) encerra a sessao e registra o logout.

## Para onde cada cargo vai

| Cargo | Destino apos login |
| --- | --- |
| `adm_master` | `/painel` |
| `gerente` | `/{slug}/gerencia` |
| `caixa` | `/{slug}/caixa` |
| `motoqueiro` | `/{slug}/entregas` |
| `garcom`, `cliente` | `/minha-conta` (areas ainda nao criadas) |

## Quem acessa o que

Fonte unica: `app/Services/Auth/Autorizacao.php` (alterar ali muda login e rotas).

| Rota | Cargos | Regra extra |
| --- | --- | --- |
| `/painel` | `adm_master` | - |
| `/{slug}/gerencia` | `gerente` | mesmo restaurante |
| `/{slug}/caixa` | `caixa`, `gerente` | mesmo restaurante |
| `/{slug}/entregas` | `motoqueiro`, `gerente` | mesmo restaurante |

- O `adm_master` acessa qualquer restaurante (suporte).
- Um gerente do restaurante A recebe **403** em `/b/gerencia`. A resposta e a mesma para "nao existe" e "nao e seu", para nao revelar outros restaurantes.
- Sem login: redireciona para `/login`. Cargo sem permissao: **403** com botao para a propria area.
- Restaurante `suspenso` ou `inativo`: a equipe nao entra (login recusado e sessoes existentes deixam de valer). `ativo` e `pendente` tem acesso.
- O usuario e recarregado do banco a cada requisicao: desativar a conta ou mudar o cargo vale na hora.

## Manter conectado

- Caixa **"Manter conectado neste aparelho"** no login. Marcada, a pessoa continua conectada por **30 dias** naquele navegador, mesmo fechando-o.
- Tabela `user_sessoes_lembradas` (um registro por aparelho, ate 10 por conta): `id_user`, `seletor` (unico), `validador_hash` (SHA-256), `expira_em`, `ultimo_uso_em`, `ip`, `navegador` (resumo, ex.: "Chrome no Windows").
- Cookie `cardapim_lembrar` = `seletor:validador` (HttpOnly, SameSite=Lax, Secure em HTTPS). O banco nunca guarda o validador em texto: um vazamento do banco nao permite entrar.
- Quando a sessao expira, o sistema confere o cookie, recria a sessao (novo ID), troca o validador (rotacao) e renova o prazo.
- **Cookie copiado:** se um seletor existente chega com validador errado (o token antigo foi reutilizado), todos os acessos lembrados da conta sao encerrados e um "Alerta de seguranca" e registrado.
- Conta desativada, cargo sem acesso ou restaurante suspenso: o acesso lembrado e apagado.
- **Sair** apaga o acesso lembrado deste aparelho no servidor e o cookie.
- Pendente para quando houver troca de senha: encerrar todos os acessos lembrados da conta (`LoginLembrado::revogarTodos`).

## Registros em `log_user`

| Titulo | Quando | Exemplo de descricao |
| --- | --- | --- |
| `Login` | senha correta | Acesso realizado como Gerente, por e-mail. Manter conectado: sim (30 dias). Chrome no Windows. |
| `Login automático` | sessao restaurada pelo manter conectado | Acesso restaurado pelo "manter conectado" como Caixa. Safari no iPhone. |
| `Login recusado` | senha errada, conta desativada, restaurante suspenso | Senha incorreta. |
| `Alerta de segurança` | cookie de manter conectado copiado | ... Todos os acessos lembrados desta conta foram encerrados. |
| `Logout` | botao Sair | Saiu da conta neste aparelho. |

Todos com `id_user`, `ip` e `data_hora`. `user.ultimo_acesso` e atualizado no login e no login automatico. Senhas e tokens nunca sao registrados.

## Seguranca

- Sessao em `storage/sessions` (fora de `public/`), cookie `cardapim_sessao` com `HttpOnly`, `SameSite=Lax`, `Secure` em HTTPS, `use_strict_mode`.
- Expira apos **2 horas sem uso** ou **12 horas** desde o login.
- CSRF em todo POST de autenticacao (`hash_equals`).
- Limite de tentativas **que falham**: 5 por conta+IP e 30 por IP a cada 15 minutos (logins corretos nao contam, para nao bloquear equipes no mesmo Wi-Fi).
- Mensagem unica para usuario inexistente ou senha errada, com `password_verify` executado mesmo sem usuario (tempo de resposta igual).
- Hash atualizado automaticamente quando `password_needs_rehash` indicar.
- Paginas logadas com `Cache-Control: no-store, private` e `noindex`.
- `log_user` registra login, recusa (senha incorreta, conta desativada, restaurante suspenso) e logout, sem senhas.

## Testes

- `tests/http/auth.sh`: 40 cenarios HTTP (inclui manter conectado, rotacao, cookie copiado e logout) (redirecionamentos, 403 por cargo e por restaurante, CSRF, conta desativada, restaurante suspenso, retorno a pagina pedida, logout, cabecalho sem cache).
- `tests/http/criar-contas-ci.php` cria as contas `ci_*` e **recusa qualquer banco que nao seja `cardapim_ci`**.
