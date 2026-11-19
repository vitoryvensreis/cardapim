# Deliveryzim Balança Virtual — Android (somente teste)

O APK **não pesa alimentos**: um operador digita um peso fictício em kg (até três casas) e escolhe o estado **Estável**, **Instável** ou **Sobrecarga**. Ele emite tramas de balanças comerciais por Wi-Fi para testar o Agent Windows e o caixa. **Nunca usar peso simulado para cobrar clientes ou movimentar estoque real.**

## Comunicação

O celular abre um servidor TCP na porta **9101** (apenas rede privada, até 4 clientes). O Agent Windows atua como cliente e interpreta o mesmo formato que chega de balanças seriais. Não é criada porta COM física no Android; o transporte de teste é via TCP.

| Perfil | Solicitação do Agent | Resposta | Exemplo |
| --- | --- | --- | --- |
| ASCII contínuo | Nenhuma, a cada ~350 ms | ST ou US + peso kg + CRLF | ST,GS,+0.350kg |
| Toledo Prix III Prt5 | ENQ = 0x05 | STX + 5 dígitos (gramas) + ETX | 02 30 30 33 35 30 03 |
| Urano US POP-S PROT3 | Nenhuma, a cada ~350 ms | STX + 5 dígitos (gramas) + ETX | 02 30 30 33 35 30 03 |

Nos perfis binários, instabilidade = IIIII, sobrecarga = SSSSS. O Agent não deve publicar pesos instáveis.

Fontes dos protocolos:
- Toledo Prix III, protocolo Prt5: https://www.irmor.com.br/site2010/manuais_usuarios/Prix%203%20-%20Prix%203%20Bateria.pdf
- Urano US POP-S 4V, protocolo PROT3: https://www.urano.com.br/media/wysiwyg/manuais/Manual_Bal_US_POPS_%204V_11503020366_1.3.pdf

## Teste ponta a ponta

1. No workflow Android da branch, baixe o artifact Deliveryzim-Balanca-Virtual-Android-debug, extraia o APK e instale no celular.
2. Conecte o celular e o PC à **mesma rede Wi-Fi**. Verifique se o roteador não está isolando os clientes.
3. No APK, informe **0,350**, selecione **Estável** e **ASCII contínuo**; toque em **Iniciar servidor** e anote o IP e a porta 9101 exibidos.
4. Instale o Deliveryzim-Agent.exe (versão 0.2.0 ou mais nova, gerado na `main`) e vincule-o a um **restaurante de teste**.
5. Abra **Configurar balança**, escolha **Wi-Fi (celular)**, informe somente o IP, selecione **Fluxo contínuo (ASCII)** e **Conectar e salvar**.
6. Após três amostras com diferença de no máximo 5 g, o Agent mostra o peso e o status **Peso estável (Wi-Fi)**.
7. Mude no APK para **Instável**, **Sobrecarga** e depois **Parar**. O Agent deve invalidar a leitura; leituras antigas expiram em até 3 s.
8. Para Toledo, selecione **Toledo Prt5** no APK e **Solicitar com ENQ** no Agent. A resposta só ocorre depois do byte 0x05.
9. Para Urano, use **Urano PROT3** no APK e **Fluxo contínuo** no Agent.

O app não emula USB Gadget, RS-232 físico, paridade/baud rate nem Bluetooth SPP. Não valida calibração, driver, precisão real ou conformidade metrológica. O modo Toledo implementa **consulta de peso**, não envio de preço/kg. Use apenas em ambiente de teste.

## Build

Projeto Java/Android API 26+, alvo 35, JDK 17, AGP 8.8.2, Gradle 8.10.2. Workflow em .github/workflows/android-virtual-scale.yml. O APK gerado é debug, não produção. Teste Java puro do codificador em virtual-scale/android/tests/ScaleProtocolTest.java.
