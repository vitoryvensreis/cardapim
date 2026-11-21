# Deliveryzim Virtual Printer — Android

**Protótipo experimental**: o Android simula uma impressora térmica ESC/POS e recebe trabalhos na porta TCP 9100.

## Entregue na v0.1

- APK Android sem WebView, com servidor local TCP/9100 e notificação de primeiro plano.
- Cupom de teste, histórico de até 50 trabalhos, texto de prévia e bytes hexadecimais.
- Comandos ESC/POS básicos: ESC @, ESC E, alinhamento, GS V (corte), DLE EOT n (status).
- Estados de teste: pronta, sem papel, tampa aberta, offline (status simulado).
- Script PowerShell para cadastrar impressora TCP/IP **Generic / Text Only** no Windows.
- Rede via Wi-Fi ou compartilhamento USB (quando o Android suportar).
- Workflow de build Android separado do CI/deploy PHP.

**Limitações:** sem IPP, sem descoberta automática, sem rasterização fiel de imagens, sem USB Printer Class nativo. Cadastro no Windows necessita script ou assistente manual. Simulação não substitui homologação em impressora real. O driver de texto simples não reproduz gráficos complexos. A porta RAW não exige senha: use somente em rede confiável!

## Compilar e obter o APK

No GitHub Actions, abra o workflow **Deliveryzim Virtual Printer APK** na branch feature/android-virtual-printer, execute ou aguarde o build e baixe o artefato **deliveryzim-virtual-printer-debug** (contém app-debug.apk).

No Android Studio, abra virtual-printer/android e selecione **Build > Build APK(s)**. Ou use Gradle 8.9 e Android SDK 35:

    cd virtual-printer/android
    gradle assembleDebug

A saída fica em app/build/outputs/apk/debug/app-debug.apk.

## Teste por Wi-Fi

1. Conecte celular e PC à mesma rede sem isolamento entre dispositivos.
2. Instale o APK e toque **Iniciar impressora**. A notificação indica a execução.
3. Localize o IPv4 do celular na tela do app.
4. Confirme a conexão pelo PC (em terminal Python):
   python virtual-printer/tests/test_tcp.py --host 192.168.1.120
   Substitua o endereço pelo IP real exibido.
5. No PowerShell do Windows **como administrador**:
   powershell -ExecutionPolicy Bypass -File .\virtual-printer\windows\Instalar-Impressora.ps1 -Ip 192.168.1.120
6. Confira em **Configurações > Impressoras e scanners**. Imprima texto simples no Bloco de Notas.

Se faltar o driver Generic / Text Only, instale pelo assistente do Windows e repita. Os nomes podem variar por idioma/edição.

## Teste por cabo USB

1. Ligue Android e Windows por cabo de dados e ative **Compartilhamento de Internet USB** no Android.
2. Verifique se o Windows recebeu uma interface de rede USB.
3. Use o endereço IPv4 da interface USB exibido no Android.
4. Repita os comandos TCP e cadastro de impressora apontando para esse endereço.
5. Se o Android não oferecer roteamento USB, o modo não funcionará neste modelo.

O cabo USB funciona como **rede TCP/IP**, não como emulação de um periférico USB de impressão.

## Testes e segurança

Os testes unitários ESC/POS podem rodar com javac sem SDK Android. Não exponha a porta 9100 à internet. A emulação é apenas para validação funcional do Deliveryzim Agent. Requer testes físicos antes de divulgar compatibilidade com uma impressora comercial.
