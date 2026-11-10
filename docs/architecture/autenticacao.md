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

- `tests/http/auth.sh`: 28 cenarios HTTP (redirecionamentos, 403 por cargo e por restaurante, CSRF, conta desativada, restaurante suspenso, retorno a pagina pedida, logout, cabecalho sem cache).
- `tests/http/criar-contas-ci.php` cria as contas `ci_*` e **recusa qualquer banco que nao seja `cardapim_ci`**.
