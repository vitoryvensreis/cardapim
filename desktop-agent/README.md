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

## Teste de balança com APK Android (Wi-Fi, sem equipamento)

O projeto virtual-scale/android gera o APK **Deliveryzim Balança Virtual**.
Digite, por exemplo, 0,350 kg no celular e marque a leitura como estável;
o app envia tramas de balança na porta TCP 9101 em rede Wi-Fi privada.

1. No APK, pressione **Iniciar servidor** e anote o IP mostrado.
2. No Deliveryzim Agent Windows, clique **Configurar balança**, escolha
   **Wi-Fi (celular)** e digite o IP do Android.
3. Para **ASCII** ou **Urano PROT3**, selecione **Fluxo contínuo**.
   Para **Toledo Prix III Prt5**, selecione **Solicitar com ENQ**.
4. Clique **Conectar e salvar**. O Agent exige três amostras consistentes,
   mostra **SIMULAÇÃO** e publica o peso no restaurante de teste vinculado.
5. Experimente **Instável**, **Sobrecarga** e **Parar**; a última leitura
   deve ser invalidada. O simulador não é medição nem balança comercial.

O IP precisa ser IPv4 privado (faixas 10.x, 172.16–31.x e 192.168.x).
O Agent bloqueia destinos públicos e não abre porta TCP de escuta no Windows.
Portas COM físicas continuam funcionando como antes. O APK não transforma
o celular em periférico USB, RS-232 ou Bluetooth. O teste físico com
balança comercial permanece obrigatório para homologação.

Detalhes e instruções: virtual-scale/README.md

## Balança para açaí (leitura local + integração via API)

O Agent também pode capturar peso de uma **balança serial ASCII** ligada ao
computador via RS-232 ou adaptador USB/serial que crie uma porta `COM`.
A leitura física é local. Com a API do PR #104 implantada, o Agent envia somente
uma leitura recente e estável via HTTPS para o restaurante vinculado; o caixa
pode escolher a balança e usar o peso na comanda. **O operador precisa confirmar
o lançamento**; a leitura isolada não cria pedidos, não movimenta estoque e
não calcula preço.

1. Depois de entrar no Agent, abra **Configurar balança**.
2. Instale o driver do fabricante quando necessário e conecte a balança.
3. Clique **Atualizar portas** e selecione a porta COM identificada.
4. Escolha velocidade (padrão 9600, ajustar conforme manual), unidade
   recebida (kg ou g) e comunicação:
   - **Fluxo contínuo (ASCII)**: peso enviado espontaneamente pela balança;
   - **Solicitar com ENQ**: transmite apenas ENQ (0x05) a cada 500 ms.
5. Clique **Conectar e salvar**. O visor mostra peso em kg com 3 casas e
   informa se a leitura está estável. O Agent reconecta após desconexão.
6. **Desconectar** interrompe a leitura e o reconectar automático.

A leitura aceita mensagens ASCII com número decimal e unidade `kg`/`g`,
indicadores opcionais `ST`/`US` e final de linha LF (ou timeout
serial curto). Uma leitura só é exibida como estável após **três amostras
consecutivas** com variação de no máximo 5 g, desde que a balança não informe
instabilidade. Quadro inválido, peso negativo, sobrecarga, falha ou leitura
antiga não podem ser usados para uma venda. A tara e o zero continuam sendo
operações no equipamento físico, sem enviar comandos não homologados.

**Compatibilidade:** não suporta automaticamente todos os equipamentos
Toledo, Filizola, Urano, Balmak ou outros. Cada modelo pode usar protocolo,
paridade, comandos de solicitação e formato próprios. USB-HID, USB sem COM,
balanças de rede e protocolos binários exigem adaptadores futuros e
homologação específica. Para uso comercial, respeitar os requisitos
metrológicos e de verificação aplicáveis à balança.

**Integração do PDV (publicada no backend):** o Agent publica em
\`POST /api/v1/desktop/pesagem\` com seu token de dispositivo, sem aceitar
identificador de restaurante no payload. O caixa e o gerente consultam
\`GET /api/v1/desktop/balancas\` em sessão autenticada. Pesos com mais de 3 s
ou não estáveis são ignorados. No modal de produto por quilo, o funcionário
clica em **Usar peso da balança** e depois em **Lançar**. O PHP aplica
\`QuantidadeVenda\` e revalida os valores. Produtos por litro permanecem
com digitação manual. Não existe servidor HTTP local exposto no Windows.

## Protecoes de fila

SQLite registra cada evento antes do cursor ser atualizado, evitando reprocessar a mesma notificacao na reconexao. Estados: pendente, imprimindo, enviado_ao_windows, recebido, erro. Se o aplicativo morrer com estado "imprimindo", uma revisao manual e necessaria antes de repetir a impressao. **Nao ha garantia de "exatamente uma impressao fisica" via driver Windows**.

## Limitacoes da v0.1

- Impressão: apenas Windows, ESC/POS RAW e porta impressora registrada no Windows.
- Balanças: somente serial ASCII via porta COM e protocolos básicos acima; testar o modelo físico.
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
- POST \`/api/v1/desktop/pesagem\` — Bearer do agente, somente peso estável em kg ou limpeza de leitura
- GET \`/api/v1/desktop/balancas\` — sessão de gerente/caixa, lista exclusivamente dispositivos da própria loja
- Migrations \`20261119010000_create_desktop_agent_devices.php\` e \`20261119020000_create_desktop_agent_pesagens.php\`

**Backend**: a API de pesagem do PR #104 foi publicada na VPS com backup e migrations em 19/11/2026. O modo de simulação Android/Windows é exclusivo da branch de teste e não foi publicado na main. Homologação física e teste comercial continuam pendentes.
