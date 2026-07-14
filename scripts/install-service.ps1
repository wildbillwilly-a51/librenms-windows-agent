[CmdletBinding()]
param(
    [string]$SourceDir,
    [string]$InstallDir = "$env:ProgramFiles\LibreNMS\Windows Agent",
    [string]$ConfigPath,
    [string]$DataDir = "$env:ProgramData\LibreNMS\Windows Agent",
    [int]$Port = 6556,
    [switch]$NoFirewallRule,
    [switch]$NoStart
)

$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this installer from an elevated PowerShell session.'
    }
}

function Stop-ServiceIfPresent {
    param([string]$Name)
    $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($service -and $service.Status -ne 'Stopped') {
        Stop-Service -Name $Name -Force
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
}

Assert-Administrator

$serviceName = 'LibreNMSWindowsAgent'
$displayName = 'LibreNMS Windows Agent'
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

if ([string]::IsNullOrWhiteSpace($SourceDir)) {
    $packageRoot = Resolve-Path -LiteralPath (Join-Path $scriptRoot '..')
    if (Test-Path -LiteralPath (Join-Path $packageRoot 'LibreNMS.WindowsAgent.Service.exe')) {
        $SourceDir = $packageRoot
    } else {
        $SourceDir = Join-Path $scriptRoot '..\src\LibreNMS.WindowsAgent.Service\bin\Release\net462'
    }
}

if ([string]::IsNullOrWhiteSpace($ConfigPath)) {
    $ConfigPath = Join-Path $scriptRoot '..\samples\agent.json'
}

$sourceFull = (Resolve-Path -LiteralPath $SourceDir).Path
$exeSource = Join-Path $sourceFull 'LibreNMS.WindowsAgent.Service.exe'
if (-not (Test-Path -LiteralPath $exeSource)) {
    throw "Built service executable not found: $exeSource"
}

New-Item -ItemType Directory -Force -Path $InstallDir, $DataDir | Out-Null
Stop-ServiceIfPresent -Name $serviceName

Copy-Item -Path (Join-Path $sourceFull '*') -Destination $InstallDir -Recurse -Force

$installedConfig = Join-Path $DataDir 'agent.json'
if (-not (Test-Path -LiteralPath $installedConfig)) {
    Copy-Item -LiteralPath $ConfigPath -Destination $installedConfig -Force
}

$exePath = Join-Path $InstallDir 'LibreNMS.WindowsAgent.Service.exe'
$binPath = '"' + $exePath + '" --config "' + $installedConfig + '"'
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue

if ($existing) {
    Set-ItemProperty -LiteralPath "HKLM:\SYSTEM\CurrentControlSet\Services\$serviceName" -Name ImagePath -Value $binPath
    Set-ItemProperty -LiteralPath "HKLM:\SYSTEM\CurrentControlSet\Services\$serviceName" -Name DisplayName -Value $displayName
    Set-Service -Name $serviceName -StartupType Automatic
} else {
    New-Service `
        -Name $serviceName `
        -BinaryPathName $binPath `
        -DisplayName $displayName `
        -StartupType Automatic `
        -Description 'LibreNMS-first Checkmk-compatible Windows monitoring agent.' | Out-Null
}

if (-not $NoFirewallRule) {
    $ruleName = 'LibreNMS Windows Agent TCP 6556'
    $rule = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if (-not $rule) {
        New-NetFirewallRule `
            -DisplayName $ruleName `
            -Direction Inbound `
            -Action Allow `
            -Protocol TCP `
            -LocalPort $Port `
            -Profile Domain,Private `
            -Description 'Allow LibreNMS pollers to reach the LibreNMS Windows Agent.' | Out-Null
    }
}

if (-not $NoStart) {
    Start-Service -Name $serviceName
    $started = Get-Service -Name $serviceName
    $started.WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
}

Write-Output "Installed $displayName"
Write-Output "Service: $serviceName"
Write-Output "Executable: $exePath"
Write-Output "Config: $installedConfig"
