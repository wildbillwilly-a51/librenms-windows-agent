[CmdletBinding()]
param(
    [string]$InstallDir = "$env:ProgramFiles\LibreNMS\Windows Agent",
    [string]$DataDir = "$env:ProgramData\LibreNMS\Windows Agent",
    [switch]$KeepFirewallRule,
    [switch]$PurgeData
)

$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this uninstaller from an elevated PowerShell session.'
    }
}

Assert-Administrator

$serviceName = 'LibreNMSWindowsAgent'
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($service) {
    if ($service.Status -ne 'Stopped') {
        Stop-Service -Name $serviceName -Force
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }

    & sc.exe delete $serviceName | Out-Null
}

if (-not $KeepFirewallRule) {
    Get-NetFirewallRule -DisplayName 'LibreNMS Windows Agent TCP 6556' -ErrorAction SilentlyContinue |
        Remove-NetFirewallRule
}

if (Test-Path -LiteralPath $InstallDir) {
    Remove-Item -LiteralPath $InstallDir -Recurse -Force
}

if ($PurgeData -and (Test-Path -LiteralPath $DataDir)) {
    Remove-Item -LiteralPath $DataDir -Recurse -Force
}

Write-Output "Uninstalled $serviceName"
if (-not $PurgeData) {
    Write-Output "Data retained: $DataDir"
}
