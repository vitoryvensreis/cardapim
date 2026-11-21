# Deliveryzim SMS Gateway — protótipo Android 16

Aplicativo nativo Kotlin, dedicado a enviar **apenas códigos OTP transacionais** por SMS a partir de um telefone Android com chip. Projeto **isolado** do PHP de produção. Testado estaticamente no código; envio real e suspensão de tela devem ser validados no Galaxy S22.

## O que já faz
- Tela de configuração de API HTTPS + token armazenado via Android Keystore AES-GCM;
- Botão para solicitar SMS de teste real, sem depender da API;
- Serviço em primeiro plano (FGS specialUse) com notificação persistente e polling a cada 15 segundos;
- Serviço tenta reabrir após reinício quando ativado;
- Seleciona o SIM padrão de SMS configurado no Android;
- Bloqueio de conteúdo remoto fora de OTP Deliveryzim, de telefone fora do Brasil e de códigos vencidos;
- Limites locais de 10 SMS/hora e 50 SMS/dia (incluindo testes);
- ID local de tentativa para não reenviar automaticamente uma operação incerta;
- Status de envio/transporte via BroadcastReceiver;
- Sem números, conteúdo ou tokens em logs.

## O que ainda NÃO está pronto
- **Endpoints REST PHP do gateway** ainda não foram implementados nem publicados. O app aceita SMS de teste manual, mas não recebe solicitações reais até que existam esses endpoints.
- O comportamento em tela bloqueada, Doze, reinício e permissão SEND_SMS depende do Android 16/One UI, devendo ser validado em aparelho real.
- A permissão SEND_SMS é **hard restricted**: a instalação via APK pode precisar de instalador compatível/allowlist e não há garantia de autorização no S22.
- O status "sent" indica aceitação da operadora, **não garante entrega**. "delivered" depende do relatório da operadora.
- Um FGS specialUse pode ser interrompido ou restringido pelo sistema. Não há garantia 24/7.

## API contratada (pendente no PHP)
Autenticação: Authorization: Bearer TOKEN_DO_DISPOSITIVO, somente HTTPS; tokens devem ser aleatórios, vinculados ao dispositivo e armazenados como hash no servidor.

GET /api/v1/sms-gateway/jobs?limit=5
Resposta: {"jobs":[{"id":"a1b2c3","phone":"5585999999999","message":"Deliveryzim: seu codigo e 123456. Valido por 10 minutos.","expires_at":"2026-11-20T16:00:00Z"}]}

POST /api/v1/sms-gateway/jobs/a1b2c3/result
Corpo: {"status":"sent"} ou failed, rejected, delivered, delivery_unknown.

Implementação backend obrigatória: leases/claims idempotentes, expiração do OTP, rate limit por número/IP/dispositivo, verificações de permissão, reconciliação de status, auditoria sem conteúdo, falhas e indisponibilidade. Não retransmitir automaticamente SMS com envio incerto. O gateway é exclusivo da plataforma, não um gateway por restaurante. Sem webhook público do aparelho.

## Instalação e teste
1. Baixar o APK debug produzido pelo workflow Android SMS Gateway.
2. Instalar no S22, permitir a instalação de fontes autorizadas.
3. Autorizar SEND_SMS e notificações. Se SMS estiver bloqueado, não contornar restrições: verificar o método de instalação autorizado.
4. Definir o chip padrão de SMS no Android.
5. Configurar o aplicativo para bateria sem restrições e nunca suspender automaticamente.
6. Enviar SMS de teste para um número próprio com consentimento.
7. Só quando a API PHP estiver pronta: salvar URL HTTPS e token, iniciar serviço, bloquear a tela, testar polling e confirmar logs e entrega.
8. Fazer testes de longa duração; desligar economia de energia no dispositivo dedicado.

## Compilação
Java 17, Gradle 8.11.1, Android SDK 35:
cd android/sms-gateway && gradle assembleDebug
Saída: app/build/outputs/apk/debug/app-debug.apk

O APK debug é assinado com chave de desenvolvimento e **não** é apropriado para distribuição pública.
