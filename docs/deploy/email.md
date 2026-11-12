# E-mail do sistema (SMTP pelo mailcow da VPS)

O sistema manda e-mails de aviso de chamado, recuperação de senha e código da verificação em duas etapas.
Em produção use `MAIL_DRIVER=smtp` apontando para o **mailcow que já roda na VPS**: não é preciso outro servidor de
e-mail, basta adicionar o domínio da plataforma nele. O mailcow assina com DKIM e os e-mails não caem no spam.

## 1. No mailcow (painel de administração)

1. **E-mail > Configuração > Domínios > Adicionar domínio**: o domínio da plataforma (ex.: `deliveryzim.com.br`).
   Pode deixar cotas pequenas; o domínio só envia.
2. **Caixas de correio > Adicionar**: `no-reply@` do domínio, com senha forte gerada (guarde só no gerenciador de senhas).
3. **Configuração > ARC/DKIM keys**: gerar a chave do domínio novo (seletor `dkim`, 2048 bits) e copiar o valor TXT.

## 2. No DNS do domínio

| Tipo | Nome | Valor |
| --- | --- | --- |
| TXT | `@` | `v=spf1 mx a:<host do mailcow> ~all` |
| TXT | `dkim._domainkey` | valor copiado do mailcow (`v=DKIM1; k=rsa; p=...`) |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:<um e-mail seu>` (depois de alguns dias sem problemas, `p=quarantine`) |
| MX | `@` | `<host do mailcow>` prioridade 10 (só se for receber e-mail no domínio; para só enviar é opcional) |

Se já existir um registro SPF no domínio, junte num só (não pode haver dois `v=spf1`).
O DNS pode levar de minutos a algumas horas. O próprio mailcow mostra se está certo em **Domínios > DNS** do domínio.

## 3. No `.env` da VPS (`shared/.env`, nunca no GitHub)

```dotenv
MAIL_DRIVER=smtp
MAIL_HOST=<host do mailcow>
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=no-reply@deliveryzim.com.br
MAIL_PASSWORD=<senha da caixa no-reply>
MAIL_FROM=no-reply@deliveryzim.com.br
```

Faça backup do `.env` antes e digite a senha com `read -rsp` (não fica no histórico do terminal). Recarregue o PHP-FPM do site.

## 4. Testar

`/painel/configuracoes` > **E-mail do sistema**: confere como está configurado (sem mostrar a senha) e **Enviar e-mail de teste**.
Se falhar, o motivo aparece na tela (ex.: senha recusada, servidor fora do ar). Para ver a nota anti-spam, mande o teste
para um endereço do mail-tester.com.

## Desenvolvimento e CI

`MAIL_DRIVER=log` grava em `storage/logs/mail.log` e não envia nada. `tests/unit/smtp.php` testa o cliente SMTP contra um
servidor falso (`tests/fakes/smtp-fake.php`).
