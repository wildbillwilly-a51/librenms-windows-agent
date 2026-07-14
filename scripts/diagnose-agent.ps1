[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'

$exe = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe'
$config = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.json'
$log = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.log'

Write-Output 'service:'
Get-Service -Name LibreNMSWindowsAgent -ErrorAction SilentlyContinue |
    Select-Object Name,Status,StartType |
    Format-List

Write-Output 'validate:'
if (Test-Path -LiteralPath $exe) {
    & $exe --validate-config --config $config
} else {
    Write-Output "missing_exe=$exe"
}

Write-Output 'once_sections:'
if (Test-Path -LiteralPath $exe) {
    & $exe --once --config $config |
        Select-String -Pattern '^<<<|Windows Agent Service|LibreNMS Pending|Windows Agent Windows Update'
}

Write-Output 'agent_log:'
Get-Content $log -Tail 40 -ErrorAction SilentlyContinue

Write-Output 'netstat_6556:'
netstat -ano | findstr ':6556'

Write-Output 'service_events:'
Get-WinEvent -FilterHashtable @{
    LogName = 'System'
    ProviderName = 'Service Control Manager'
    StartTime = (Get-Date).AddMinutes(-60)
} -MaxEvents 12 -ErrorAction SilentlyContinue |
    Select-Object TimeCreated,Id,Message |
    Format-List

Write-Output 'dotnet_events:'
Get-WinEvent -FilterHashtable @{
    LogName = 'Application'
    StartTime = (Get-Date).AddMinutes(-60)
} -MaxEvents 20 -ErrorAction SilentlyContinue |
    Where-Object { $_.ProviderName -match 'Application Error|\.NET Runtime' } |
    Select-Object TimeCreated,ProviderName,Id,Message |
    Format-List
