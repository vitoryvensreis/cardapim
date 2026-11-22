; Instalador do Deliveryzim Agent para Windows (Inno Setup 6).
; Instala só para o usuário atual (sem pedir administrador), cria atalhos, liga "abrir com o Windows"
; e abre o Agent no fim. Gerado pelo GitHub Actions: iscc /DMyAppVersion=X.Y.Z deliveryzim-agent.iss

#ifndef MyAppVersion
  #define MyAppVersion "0.0.0"
#endif
#define MyAppName "Deliveryzim Agent"
#define MyAppExe "Deliveryzim-Agent.exe"

[Setup]
AppId={{6F7C2E4B-4D1A-4E7B-9C3A-DE11E5A9A0F1}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} {#MyAppVersion}
AppPublisher=Escotilha Produtora LTDA
AppPublisherURL=https://deliveryzim.com.br
DefaultDirName={localappdata}\Programs\Deliveryzim Agent
DefaultGroupName=Deliveryzim
DisableProgramGroupPage=yes
DisableDirPage=yes
PrivilegesRequired=lowest
OutputDir=..\..\dist
OutputBaseFilename=Deliveryzim-Agent-Setup
SetupIconFile=..\agent\assets\deliveryzim.ico
UninstallDisplayIcon={app}\{#MyAppExe}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
AppMutex=Local\DeliveryzimAgent
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "ptbr"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"

[Tasks]
Name: "iniciar"; Description: "Abrir o Deliveryzim Agent quando o Windows ligar (recomendado)"
Name: "atalho"; Description: "Criar atalho na área de trabalho"

[Files]
Source: "..\..\dist\{#MyAppExe}"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{userprograms}\Deliveryzim Agent"; Filename: "{app}\{#MyAppExe}"
Name: "{userdesktop}\Deliveryzim Agent"; Filename: "{app}\{#MyAppExe}"; Tasks: atalho

[Registry]
; Mesmo nome de valor que o Agent usa na opção "Abrir sozinho quando o Windows ligar".
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "DeliveryzimAgent"; ValueData: """{app}\{#MyAppExe}"""; Flags: uninsdeletevalue; Tasks: iniciar

[Run]
Filename: "{app}\{#MyAppExe}"; Description: "Abrir o Deliveryzim Agent agora"; Flags: nowait postinstall skipifsilent
; Atualizacao automatica (Agent 0.9.0+ roda este instalador em silencio): abre o Agent de novo no fim.
Filename: "{app}\{#MyAppExe}"; Flags: nowait; Check: WizardSilent
