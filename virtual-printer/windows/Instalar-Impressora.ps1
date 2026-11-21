# Execute em PowerShell como administrador:
# powershell -ExecutionPolicy Bypass -File .\Instalar-Impressora.ps1 -Ip 192.168.1.120
param(
 [Parameter(Mandatory=$true)][string]$Ip,
 [string]$Nome = "Deliveryzim Virtual Printer",
 [int]$Porta = 9100
)
$ErrorActionPreference = "Stop"
$portName = "DELIVERYZIM_$($Ip)_$Porta"
Write-Host "Verificando conexao TCP com $Ip na porta $Porta ..."
$tcp = Test-NetConnection -ComputerName $Ip -Port $Porta -WarningAction SilentlyContinue
if (-not $tcp.TcpTestSucceeded) {
 throw "Sem conexao. Abra o app Android, inicie o servidor e confira Wi-Fi / USB tethering."
}
if (-not (Get-PrinterPort -Name $portName -ErrorAction SilentlyContinue)) {
 Add-PrinterPort -Name $portName -PrinterHostAddress $Ip -PortNumber $Porta
}
$driver = Get-PrinterDriver | Where-Object { $_.Name -eq "Generic / Text Only" } | Select-Object -First 1
if (-not $driver) {
 throw "Driver 'Generic / Text Only' ausente. Instale pelo assistente de drivers do Windows (fabricante Generic, modelo Generic / Text Only) e repita."
}
if (-not (Get-Printer -Name $Nome -ErrorAction SilentlyContinue)) {
 Add-Printer -Name $Nome -DriverName $driver.Name -PortName $portName
} else {
 Set-Printer -Name $Nome -PortName $portName
}
Write-Host ("Impressora cadastrada: " + $Nome + " -> " + $Ip + ":" + $Porta)
Write-Host "Confira em Configuracoes > Bluetooth e dispositivos > Impressoras e scanners."
