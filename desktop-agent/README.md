# Deliveryzim Agent para Windows — v0.1 (teste controlado)

Agente nativo Python para Windows. **Sem WebView.** Fica na bandeja, toca som nativo, mostra aviso e envia cupons ESC/POS ao spooler do Windows.

## Escolha do restaurante

- **Imprimir automaticamente:** cada pedido novo gera aviso sonoro + envio imediato ao Windows, sem janelas de confirmacao.
- **Perguntar antes:** cada novo pedido gera aviso sonoro + janela com os botoes **Receber e imprimir** ou **Somente receber**.
- Em ambos, receber significa reconhecer o aviso **localmente**. Nao executa a acao comercial **Aceitar** do caixa.
- Alerta toca sempre que o agente recebe um evento novo (respeita volume e saida de audio do Windows). No modo manual, repete em intervalos de 20s enquanto houver janela pendente.
- A opcao de imprimir automaticamente fica salva neste computador, nao na conta do restaurante.
- Impressora: selecione uma impressora **RAW ESC/POS** cadastrada no Windows. O Android Virtual Printer pode ser usado no lugar do equipamento fisico.
- A impressao usa cp850, sem imagens. O sucesso do spooler nao garante a saida fisica do papel.

## Autenticacao

1. Execute Deliveryzim-Agent.exe (ou python main.py).
2. Insira usuario/email e senha de uma conta **gerente ou caixa** do restaurante.
3. Quando houver 2FA, informe o codigo; o aplicativo nao persiste a senha nem o codigo.
4. O backend emite um token de dispositivo com validade de 90 dias, vinculado a **um restaurante**; o app criptografa o token com DPAPI do Windows.
5. Se a conta for desativada, o restaurante for suspenso ou o dispositivo for revogado/expirar, o acesso para.
6. O agente consulta a API de eventos a cada 2,5 s; em falhas de rede, utiliza reconexao com recuo e cursor persistente. A API usa a mesma outbox do WebSocket, com retencao de 7 dias.

O **PHP precisa ter a migration + rotas deste PR publicadas** para o vinculo funcionar. O .exe compila isoladamente e pode ser testado localmente no modo de simulacao antes do deploy PHP.

## Desenvolvimento

    cd desktop-agent
    py -3.12 -m venv .venv
    .venv\Scripts\activate
    pip install -r requirements.txt
    python main.py

## Build no GitHub

O workflow \`.github/workflows/desktop-agent-windows.yml\` usa runner Windows, Python 3.12 e PyInstaller para gerar \`Deliveryzim-Agent.exe\` em **Artifacts**.

## Teste sem pedido real

1. Inicie o APK Deliveryzim Virtual Printer no Android, conectado ao Wi-Fi do Windows.
2. Confira que a porta TCP 9100 responde e que a impressora aparece nas configuracoes do Windows.
3. No agente, selecione \`Deliveryzim Virtual Printer\`, papel 58 mm ou 80 mm.
4. Clique **Testar pedido e alerta**.
5. No modo automatico, o cupom aparece no Android e o som toca.
6. No modo de confirmacao, surge janela com **Receber e imprimir** e **Somente receber**. O som toca nos dois casos, mas a segunda opcao nao imprime.
7. Feche a janela principal e confira que o agente continua na bandeja.

## Protecoes de fila

SQLite registra cada evento antes do cursor ser atualizado, evitando reprocessar a mesma notificacao na reconexao. Estados: pendente, imprimindo, enviado_ao_windows, recebido, erro. Se o aplicativo morrer com estado "imprimindo", uma revisao manual e necessaria antes de repetir a impressao. **Nao ha garantia de "exatamente uma impressao fisica" via driver Windows**.

## Limitacoes da v0.1

- Apenas Windows, ESC/POS RAW e porta impressora registrada no Windows.
- Consulta HTTP da outbox (2,5 s), **nao WS direto no agente** nesta versao. O PHP segue usando WS para o navegador.
- Evento \`pedido.novo\` precisa existir; pedidos anteriores ao cadastro nao geram alertas retroativos.
- Nao suporta impressao de PDF/documento grafico, atalhos de acessibilidade avancados, reimpressao controlada por permissao ou gerenciamento centralizado de varios dispositivos.
- O Windows pode silenciar audio ou estar suspenso; nao e possivel prometer som com o PC desligado.
- Em caso de indisponibilidade superior a retencao de 7 dias da outbox, eventos antigos deixam de estar disponiveis; o caixa web continua sendo a fonte de verdade.
- Evitar uso comercial antes de validar em ambiente real e completar auditoria e testes ponta-a-ponta.
- O token e pessoal do dispositivo. Desvincule ao mudar de restaurante ou transferir o PC.
- Para a versao final, implementar controle de dispositivos na gerencia e revogacao sem acesso fisico, assinatura digital do EXE e politica de atualizacoes.

## API nova

- GET \`/api/v1/desktop/csrf\` — sessão PHP logada (gerente/caixa)
- POST \`/api/v1/desktop/vincular\` — sessão PHP + \`X-CSRF-Token\` + JSON \`{"nome":"CAIXA-1"}\`
- GET \`/api/v1/desktop/eventos?desde={id}\` — Bearer token (dispositivo, escopo do restaurante), retorna cupom e cursor
- POST \`/api/v1/desktop/desconectar\` — revoga o token em uso
- Migration \`20261119010000_create_desktop_agent_devices.php\`

**Sem deploy automatico de PHP neste PR.** Validar testes, merge e deploy posteriormente.
