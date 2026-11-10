# Painel da plataforma (administração do Cardapim)

Área do cargo `adm_master` em `/painel`, com menu lateral igual ao do gerente (mesma estrutura visual:
`app/Views/layouts/painel.php`, usada por `layouts/gerencia.php` e `layouts/plataforma.php`).
Acesso conferido no servidor a cada requisição (`Controllers/Plataforma/BasePlataforma`): sem login vai para o
login; qualquer outro cargo recebe 403. Todo POST exige CSRF e toda ação gera log com `[Plataforma]`.

| Seção | Rota | Situação |
| --- | --- | --- |
| Dashboard | `/painel` | Em construção (com atalhos: integrações, status do sistema, catálogo) |
| Restaurantes | `/painel/restaurantes` | Em construção |
| Planos | `/painel/planos` | Em construção |
| Faturas | `/painel/faturas` | Em construção (cobranças das assinaturas pelo Asaas da plataforma) |
| Usuários | `/painel/usuarios` | Em construção |
| Integrações | `/painel/integracoes` | **Pronta**: Asaas da plataforma e WhatsApp da plataforma |
| Logs de auditoria | `/painel/logs` | Em construção |
| Configurações | `/painel/configuracoes` | Em construção (dados, logos, textos, mensagens automáticas) |
| Suporte | `/painel/suporte` | Em construção (chamados dos restaurantes) |
| Minha conta | `/painel/minha-conta` | Em construção (senha e verificação em duas etapas) |

Fonte única do menu e do que cada seção vai ter: `app/Services/Painel/MenuPlataforma.php`.
Rotas: `routes/plataforma.php` (registradas antes das rotas `/{slug}`).

## Integrações da plataforma

Rotas (todas só adm_master):

```text
GET  /painel/integracoes
GET  /painel/integracoes/asaas          POST /painel/integracoes/asaas (conectar)
POST /painel/integracoes/asaas/webhook  POST /painel/integracoes/asaas/testar  POST /painel/integracoes/asaas/desconectar
GET  /painel/integracoes/whatsapp       POST /painel/integracoes/whatsapp/conectar
GET  /painel/integracoes/whatsapp/estado (JSON)  POST /painel/integracoes/whatsapp/teste  POST /painel/integracoes/whatsapp/desconectar
```

- **Asaas**: conta da plataforma para receber as assinaturas (base das futuras Faturas). Chave cifrada, webhook automático.
- **WhatsApp**: número da plataforma, separado dos restaurantes, no mesmo WPPConnect (`127.0.0.1:21467`).
  Próximo uso: código de verificação do cliente final, que entra só com o número de telefone.
- Detalhes técnicos: [integracoes.md](integracoes.md). Teste: `tests/http/plataforma.sh` (CI).
