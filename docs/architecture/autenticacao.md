# Autenticacao e acesso por cargo

## Fluxo

1. `GET /login` mostra o formulario (e-mail **ou** nome de usuario + senha) com token CSRF.
2. `POST /login` valida CSRF, limite de tentativas, senha (`password_verify`), conta ativa e status do restaurante.
3. Sucesso: novo ID de sessao (`session_regenerate_id`), `ultimo_acesso` atualizado, registro em `log_user` e redirecionamento **303** para a area do cargo.
   Se a conta usa verificacao em duas etapas, a senha correta leva para `GET /login/verificacao` e a sessao so e criada depois do codigo (veja abaixo).
4. Se a pessoa tentou abrir uma area antes de entrar, volta para ela, desde que o cargo tenha permissao.
5. `POST /sair` (com CSRF) encerra a sessao e registra o logout.

## Para onde cada cargo vai

| Cargo | Destino apos login |
| --- | --- |
| `adm_master` | `/painel` |
| `gerente` | `/{slug}/gerencia` |
| `caixa` | `/{slug}/caixa` |
| `motoqueiro` | `/{slug}/entregas` |
| `garcom` | `/{slug}/garcom` (painel do garcom) |
| `cliente` | `/minha-conta` (area ainda nao criada) |

## Quem acessa o que

Fonte unica: `app/Services/Auth/Autorizacao.php` (alterar ali muda login e rotas).

| Rota | Cargos | Regra extra |
| --- | --- | --- |
| `/painel` | `adm_master` | - |
| `/{slug}/gerencia` | `gerente` | mesmo restaurante |
| `/{slug}/caixa` | `caixa`, `gerente` | mesmo restaurante |
| `/{slug}/garcom` | `garcom`, `gerente` | mesmo restaurante |
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
- Troca de senha (Minha conta) desconecta os outros aparelhos lembrados; senha redefinida pelo gerente e bloqueio de acesso desconectam todos os aparelhos da pessoa.
- O login automatico pelo "manter conectado" nao pede de novo o codigo da verificacao em duas etapas: o aparelho ja foi confirmado.

## Verificacao em duas etapas

- Ligada em **Minha conta** (gerente). Dois metodos: **aplicativo autenticador** (TOTP RFC 6238, 6 digitos, 30 s, tolerancia de 1 passo; Google/Microsoft Authenticator) ou **codigo por e-mail** (6 digitos, vale 10 minutos, uso unico, guardado com `password_hash` em `user.ultimo_codigo_hash`).
- So liga depois que a pessoa digita um codigo valido (QR/chave conferidos ou e-mail recebido): ninguem fica trancado por configuracao errada.
- Segredo do autenticador cifrado com **AES-256-GCM** (`Shared/Seguranca/Cripto`) em `user.dois_fatores_segredo`. Chave fora do banco e do Git: `APP_KEY` (base64 de 32 bytes) ou `storage/keys/app.key` (criada sozinha, permissao 0600, na pasta `shared/storage` da VPS). **Faca backup desse arquivo junto com o banco**: sem ele os segredos nao podem ser lidos (as pessoas entram com o codigo de recuperacao e configuram de novo).
- 10 **codigos de recuperacao** (`xxxx-xxxx`, uso unico) em `user_codigos_recuperacao`, guardados so como SHA-256; mostrados uma vez. Desligar e gerar novos codigos pedem a senha (limite de 5 erros em 15 min).
- Login: `POST /login` com senha certa grava `login_pendente` na sessao (10 minutos, 5 tentativas); `POST /login/verificacao` confere app, e-mail ou codigo de recuperacao; `POST /login/verificacao/reenviar` manda outro e-mail (limite de 5 envios em 15 min). Errou 5 vezes: volta para a senha.
- E-mail: `Services/Email/Mailer` usa o `mail()` do servidor (`MAIL_FROM` opcional, senao `no-reply@` dominio do `APP_URL`). `MAIL_DRIVER=log` grava em `storage/logs/mail.log` (so CI/desenvolvimento).
- Logs: `Login` registra como foi verificado ("Verificado pelo aplicativo autenticador", "por codigo no e-mail" ou "com codigo de recuperacao (restam N)"); codigo errado gera `Verificacao recusada`.

## Registros em `log_user`

| Titulo | Quando | Exemplo de descricao |
| --- | --- | --- |
| `Login` | senha correta | Acesso realizado como Gerente, por e-mail. Manter conectado: sim (30 dias). Chrome no Windows. |
| `Login automático` | sessao restaurada pelo manter conectado | Acesso restaurado pelo "manter conectado" como Caixa. Safari no iPhone. |
| `Login recusado` | senha errada, conta desativada, restaurante suspenso | Senha incorreta. |
| `Alerta de segurança` | cookie de manter conectado copiado | ... Todos os acessos lembrados desta conta foram encerrados. |
| `Logout` | botao Sair | Saiu da conta neste aparelho. |
| `Verificação recusada` | codigo da verificacao em duas etapas errado | Codigo da verificacao em duas etapas incorreto (tentativa 1). |

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
- `tests/http/gerencia.sh`: liga a verificacao por app e por e-mail, entra com codigo, com codigo de recuperacao e confere reuso e desligamento.
- `tests/http/criar-contas-ci.php` cria as contas `ci_*` e **recusa qualquer banco que nao seja `cardapim_ci`**.

A tabela `user_sessoes_lembradas` foi criada em producao pela execucao GitHub Actions **#60** (commit `0618fdf`),
com backup `shared/backups/cardapim-before-manter-conectado-20261110T103237Z.sql.gz`. Gatilho temporario removido no commit seguinte.
