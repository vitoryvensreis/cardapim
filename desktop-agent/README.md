# Deliveryzim Agent para Windows — v0.8 (teste controlado)

**Desde 0.8.0 — várias impressoras:** no cartão **Impressoras**, cadastre quantas quiser, cada uma com apelido
(Balcão, Cozinha, Bar...), impressora do Windows e papel; **Testar** imprime um cupom curto nela. A primeira é a
principal. No painel, em **Gerência > Integrações > Impressoras**, o gerente escolhe onde cada parte do sistema
imprime (com uma impressora imprime direto; com várias, quem imprime escolhe) e em quais o pedido novo do delivery sai.
Quem atualiza da 0.7 ganha a impressora antiga como "Principal".


**Desde 0.7.0 — botões Imprimir do sistema:** o Agent imprime os cupons pedidos no caixa (conta, pedido do delivery,
resumo do caixa) e no garçom (comanda inteira ou só os itens novos), na impressora escolhida aqui, na hora e
independente de "Automática"/"Ao receber". O resultado volta para a tela de quem pediu (Impresso ou a falha).
Versões antigas não recebem esses cupons: o sistema avisa para atualizar. Detalhes em
[`docs/architecture/impressao-agent.md`](../docs/architecture/impressao-agent.md).

**Instalar (desde 0.6.0):** em Gerência > Integrações > **Baixar o Agent para Windows** (ou na Release `agent-vX.Y.Z`),
abra o `Deliveryzim-Agent-Setup.exe`. Ele instala só para o usuário (sem pedir administrador) em
`%LOCALAPPDATA%\Programs\Deliveryzim Agent`, cria o atalho, liga **abrir com o Windows** e abre o Agent. Atualizar = rodar o
instalador novo (ele fecha o Agent antes). Só um Agent abre por usuário.
**Ao iniciar**, o Agent abre o painel no navegador padrão (ou no app instalado, se o Chrome abrir links nele) pelo link
do Agent: o gerente cai na gerência, o caixa no caixa, e o navegador fica associado à balança deste computador. Sem login,
o sistema pede para entrar e conclui depois. Dá para desligar em "Abrir o painel do Deliveryzim no navegador ao iniciar".
**Balança no caixa:** com uma só balança ligada no restaurante, o caixa usa ela mesmo sem associação; com duas ou mais,
cada navegador usa a do seu computador (associada pelo link do Agent). A janela do Agent tem tamanho fixo.

Interface em **CustomTkinter** (desde 0.5.0), com as cores oficiais (vinho, laranja, branco) e cantos discretos.
A ordem da tela de configuração é: Novos pedidos, Impressora, Balança e o botão principal **Concluir e deixar em
segundo plano**. O teste `tests/test_gui_smoke.py` abre as telas de verdade no runner Windows do GitHub Actions.

A versão fica em `agent/__init__.py` (`__version__`) e vai no nome do EXE gerado no GitHub Actions. A cada push na
`main` que mexe no Agent, o EXE também é publicado em **Releases** com a tag `agent-vX.Y.Z` (link fixo para baixar).

Agente nativo Python para Windows. **Sem WebView.** Fica na bandeja, toca som nativo, mostra aviso e envia cupons ESC/POS ao spooler do Windows.

## Aviso de pedido novo (desde 0.3.0)

- Cada pedido novo abre um **cartão no canto inferior direito** (acima do relógio), por cima das outras janelas e sem
  roubar o foco. Vários pedidos ficam empilhados (até 4 visíveis).
- **Receber** aceita o pedido no sistema, igual ao botão **Aceitar** do caixa: *Novo -> Em preparo*, linha do tempo,
  aviso ao cliente no WhatsApp (se ligado), tempo real nas telas e confirmação ao iFood quando o pedido é de lá.
  Fica registrado em log ("Pedido aceito pelo Agent") com o nome do computador e a conta que vinculou o Agent.
- **Ver no sistema** abre `/{slug}/caixa/delivery/{id}` no navegador padrão (ou no app instalado, se o Chrome abrir
  links nele).
- **O som repete a cada 4 s até o pedido ser aceito** — no Agent, no caixa ou em outro computador (o Agent confere a
  situação a cada 5 s) — ou cancelado. **Silenciar este pedido** para o som só dele; o cartão fica até alguém receber.
- **Desligar o som:** menu do ícone da bandeja (**Som dos pedidos**), a caixa na configuração ou o Silenciar do cartão.
  Sem som, o cartão continua aparecendo.
- **Impressão:** *Automática* imprime quando o pedido chega; *Ao receber* imprime no clique em Receber.
- Pedido que já chega aceito (ex.: iFood com aceite automático) mostra o cartão só com **Ver no sistema** e fecha em 20 s.
- Ao reabrir o Agent, pedidos das últimas 3 horas que ninguém aceitou voltam a avisar.
- **Navegador em silêncio:** com um Agent conectado no restaurante (consultou o sistema nos últimos 30 s), as telas da
  gerência e do caixa em **todos os aparelhos** não tocam o som; o botão do topo mostra **Som no Agent**. A luz e a
  faixa continuam. Se o Agent fechar ou perder a internet, o navegador volta a tocar em até 30 s.

- A opcao de imprimir automaticamente fica salva neste computador, nao na conta do restaurante.
- Impressora: selecione uma impressora **RAW ESC/POS** cadastrada no Windows. O Android Virtual Printer pode ser usado no lugar do equipamento fisico.
- A impressao usa cp850. Desde a 0.4.0 o topo do cupom leva a **logo oficial do Deliveryzim** (arquivo
  `agent/assets/logo-branca.png`, convertido em imagem ESC/POS `GS v 0`, centralizada e com até 72% da largura do papel).
  Se a impressora mostrar símbolos estranhos no topo, desmarque **Imprimir a logo do Deliveryzim no cupom**: sai o nome em texto.
- O sucesso do spooler nao garante a saida fisica do papel.

## Experiência de configuração (Windows)

- A tela após o login reúne **Impressora**, **Balança** e **Novos pedidos**, em seções visuais na mesma página, com rolagem quando necessário. Não é preciso abrir outra janela para escolher a porta COM.
- Selecione a impressora e o papel (58 ou 80 mm). Configure a balança somente se ela estiver conectada ao computador; a leitura continua pelo Agent, não pelo navegador.
- Escolha **Impressão automática** ou **Com confirmação**. O alerta sonoro original está ativado por padrão e toca em ambos os modos sempre que chegar um pedido novo. Use **Ouvir som de teste** para conferir a saída de áudio do Windows.
- Clique **Concluir e deixar em segundo plano**. A janela desaparece, mas o Agent continua monitorando pedidos, alertas e balança pelo ícone oficial do Deliveryzim na bandeja do Windows (ao lado do relógio).
- O duplo clique no ícone da bandeja reabre a configuração. Para fechar o programa de verdade, escolha **Encerrar agente** no menu do ícone. Na próxima abertura, um Agent previamente configurado pode iniciar diretamente na bandeja.
- A opção **Iniciar automaticamente com o Windows** só registra o executável compilado no registro do próprio usuário. Não registra o Python de desenvolvimento.
- A impressora não é escolhida arbitrariamente quando várias estão instaladas; o atendente confirma a correta. Sem impressora selecionada no modo automático, a conclusão da configuração mostra aviso.
- Os ícones da bandeja, da janela e da barra de tarefas utilizam o ícone oficial fornecido para esta versão. O EXE recebe o recurso de ícone do Windows gerado durante o build.
- O alerta sonoro foi substituído por um WAV curto original, gerado localmente; não depende do som `SystemExclamation` configurado no tema do Windows. Não é possível garantir áudio se o Windows estiver no mudo, o dispositivo de áudio não existir ou o PC estiver desligado.
- **Testes:** `desktop-agent/tests/test_sound.py` valida o WAV, o fallback e a chamada de alerta a cada pedido em ambos os modos. O workflow Windows roda todos os testes, valida os assets com Pillow, gera `.ico` e compila o EXE.

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
6. No modo **Ao receber**, o cartão no canto da tela mostra **Receber** e **Ver no sistema**; o som repete até clicar em Receber, e só então o cupom sai.
7. Feche a janela principal e confira que o agente continua na bandeja.

## Balança pelo Wi-Fi e APK Android de teste

Desde a versão 0.2.0 o Agent da `main` aceita, além da porta COM, uma balança na **rede local**
(`tcp://IP:porta`). Antes disso a leitura Wi-Fi só existia numa branch de teste, e o Agent da `main`
recusava a configuração salva com a mensagem "Selecione uma porta COM válida do Windows".

O projeto virtual-scale/android gera o APK **Deliveryzim Balança Virtual**.
Digite, por exemplo, 0,350 kg no celular e marque a leitura como estável;
o app envia tramas de balança na porta TCP 9101 em rede Wi-Fi privada.

1. No APK, pressione **Iniciar servidor** e anote o IP mostrado.
2. No Deliveryzim Agent Windows, na seção **Balança**, escolha
   **Wi-Fi (rede local)** e digite o IP mostrado no Android (porta 9101).
   Também aceita colar `192.168.0.4:9101` direto no campo do IP.
3. Para **ASCII** ou **Urano PROT3**, selecione **Fluxo contínuo**.
   Para **Toledo Prix III Prt5**, selecione **Solicitar com ENQ**.
4. Clique **Conectar e salvar**. O Agent exige três amostras consistentes,
   mostra **Peso estável (Wi-Fi)** e publica o peso no restaurante vinculado.
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
usa a balança do computador sem seleção no modal. **O operador precisa confirmar
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
6. Entre no Deliveryzim pelo navegador padrão do mesmo computador com sua conta
   de gerente/caixa. No Agent, clique **Abrir Deliveryzim**: ele associa esse
   navegador à balança deste Windows. Faça isso uma vez por navegador (ou após
   limpar os cookies); a configuração de COM/protocolo continua apenas no Agent.
7. No caixa, ao abrir um produto por kg, **Usar balança** já aparece marcado,
   o peso estável preenche o campo e o total muda automaticamente.
   Confira o visor e clique **Lançar**. Para digitar manualmente, desmarque
   **Usar balança**. Outro caixa com outra balança não interfere nessa leitura.
8. **Desconectar** interrompe a leitura e o reconectar automático.

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
confere o peso preenchido automaticamente e clica somente em **Lançar**. O PHP aplica
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
- GET \`/api/v1/desktop/pedidos/situacao?ids=1,2\` — Bearer do agente, situação de até 50 pedidos do próprio restaurante
- POST \`/api/v1/desktop/pedidos/{id}/receber\` — Bearer do agente, aceita o pedido (200), 409 se já mudou, 404 de outra loja
- GET \`/api/v1/desktop/agente-ativo\` — sessão de gerente/caixa, \`{"ativo": true}\` quando há Agent conectado na loja
- POST \`/api/v1/desktop/pesagem\` — Bearer do agente, somente peso estável em kg ou limpeza de leitura
- GET \`/api/v1/desktop/balancas\` — sessão de gerente/caixa, lista apenas dispositivos da loja e \`selecionada\` vinculado ao navegador
- POST \`/api/v1/desktop/pareamento\` — token Bearer do Agent, gera link assinado de 2 minutos
- GET \`/api/v1/desktop/parear?ticket=...\` — sessão de gerente/caixa, associa dispositivo por cookie assinado
- Migrations \`20261119010000_create_desktop_agent_devices.php\` e \`20261119020000_create_desktop_agent_pesagens.php\`

**Operação:** atualizar o EXE após a publicação. Nenhuma migration nova para a UX ou o pareamento; verificar a disponibilidade das tabelas de agentes e pesagens no ambiente. A segurança e a leitura física ainda precisam ser homologadas no equipamento utilizado.
