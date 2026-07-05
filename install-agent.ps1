[CmdletBinding()]
param(
    [string]$Version = '0.6.1',
    [string]$RepoOwner = 'wildbillwilly-a51',
    [string]$RepoName = 'librenms-windows-agent-installer',
    [string]$RepoBranch = 'main',
    [string]$WorkDir = "$env:TEMP\librenms-windows-agent-installer",
    [string]$ListenAddress = '0.0.0.0',
    [int]$ListenPort = 6556,
    [int]$AddFirewallRule = 1,
    [int]$StartService = 1,
    [int]$PreserveConfig = 1,
    [string]$ConfigPath = '',
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this installer from an elevated PowerShell session.'
    }
}

Assert-Administrator

$baseUrl = "https://raw.githubusercontent.com/$RepoOwner/$RepoName/$RepoBranch"
$msiName = "librenms-windows-agent-$Version.msi"
$artifactPath = "artifacts/$msiName"
$msiUrl = "$baseUrl/$artifactPath"
$shaUrl = "$baseUrl/SHA256SUMS"

New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
$msiPath = Join-Path $WorkDir $msiName
$shaPath = Join-Path $WorkDir 'SHA256SUMS'

Invoke-WebRequest -UseBasicParsing -Uri $msiUrl -OutFile $msiPath
Invoke-WebRequest -UseBasicParsing -Uri $shaUrl -OutFile $shaPath

$expected = Get-Content -LiteralPath $shaPath |
    Where-Object { $_ -match "\s+$([regex]::Escape($artifactPath))$" } |
    ForEach-Object { ($_ -split '\s+')[0].ToLowerInvariant() } |
    Select-Object -First 1

if (-not $expected) {
    throw "No checksum entry found for $artifactPath."
}

$actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $msiPath).Hash.ToLowerInvariant()
if ($actual -ne $expected) {
    throw "Checksum mismatch for $msiName. Expected $expected but got $actual."
}

$arguments = @(
    '/i',
    "`"$msiPath`"",
    "LISTEN_ADDRESS=$ListenAddress",
    "LISTEN_PORT=$ListenPort",
    "ADD_FIREWALL_RULE=$AddFirewallRule",
    "START_SERVICE=$StartService",
    "PRESERVE_CONFIG=$PreserveConfig"
)

if ($ConfigPath) {
    $arguments += "CONFIG_PATH=$ConfigPath"
}

if ($Silent) {
    $arguments += '/qn'
}

$process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru
if ($process.ExitCode -ne 0) {
    throw "msiexec failed with exit code $($process.ExitCode)."
}

Write-Output "Installed LibreNMS Windows Agent $Version"
