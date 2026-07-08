[CmdletBinding()]
param(
    [string]$Version = '0.6.7',
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

function Get-AgentInstallRecords {
    $roots = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
    )

    foreach ($root in $roots) {
        if (-not (Test-Path -LiteralPath $root)) {
            continue
        }

        Get-ChildItem -LiteralPath $root | ForEach-Object {
            $item = Get-ItemProperty -LiteralPath $_.PSPath
            if ($item.DisplayName -eq 'LibreNMS Windows Agent') {
                [pscustomobject]@{
                    DisplayName = $item.DisplayName
                    DisplayVersion = $item.DisplayVersion
                    ProductCode = $_.PSChildName
                    UninstallString = $item.UninstallString
                }
            }
        }
    }
}

function Uninstall-ExistingAgentPackages {
    foreach ($record in @(Get-AgentInstallRecords)) {
        if ($record.ProductCode -notmatch '^\{[0-9A-Fa-f-]{36}\}$') {
            continue
        }

        $arguments = @('/x', $record.ProductCode, '/qn', 'REBOOT=ReallySuppress')
        $process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru
        if ($process.ExitCode -ne 0 -and $process.ExitCode -ne 3010 -and $process.ExitCode -ne 1605) {
            throw "Failed to remove existing LibreNMS Windows Agent package $($record.ProductCode). msiexec exit code $($process.ExitCode)."
        }
    }
}

function Get-ServiceExecutablePath {
    $service = Get-CimInstance Win32_Service -Filter "Name='LibreNMSWindowsAgent'" -ErrorAction SilentlyContinue
    if (-not $service -or -not $service.PathName) {
        return ''
    }

    if ($service.PathName -match '^"([^"]+)"') {
        return $matches[1]
    }

    if ($service.PathName -match '^(.+?\.exe)\b') {
        return $matches[1]
    }

    return ''
}

function Assert-AgentInstalled {
    param([Parameter(Mandatory = $true)][string]$ExpectedVersion)

    $expectedExe = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe'
    $serviceExe = Get-ServiceExecutablePath
    $candidateExe = if ($serviceExe) { $serviceExe } else { $expectedExe }

    if (-not (Test-Path -LiteralPath $candidateExe)) {
        throw "LibreNMS Windows Agent service executable was not found after installation. Expected: $expectedExe. Service path: $serviceExe"
    }

    $actualVersion = (Get-Item -LiteralPath $candidateExe).VersionInfo.FileVersion
    if ($actualVersion -ne "$ExpectedVersion.0") {
        throw "LibreNMS Windows Agent executable version mismatch. Expected $ExpectedVersion.0 but found $actualVersion at $candidateExe."
    }

    $configPath = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.json'
    if (-not (Test-Path -LiteralPath $configPath)) {
        throw "LibreNMS Windows Agent config was not found after installation: $configPath"
    }

    $service = Get-Service -Name LibreNMSWindowsAgent -ErrorAction SilentlyContinue
    if (-not $service) {
        throw 'LibreNMSWindowsAgent service was not found after installation.'
    }

    [pscustomobject]@{
        ExePath = $candidateExe
        FileVersion = $actualVersion
        ConfigPath = $configPath
        ServiceStatus = $service.Status
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

Uninstall-ExistingAgentPackages

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
if ($process.ExitCode -ne 0 -and $process.ExitCode -ne 3010) {
    throw "msiexec failed with exit code $($process.ExitCode)."
}

$installed = Assert-AgentInstalled -ExpectedVersion $Version
Write-Output "Installed LibreNMS Windows Agent $Version"
Write-Output "Executable: $($installed.ExePath)"
Write-Output "Config: $($installed.ConfigPath)"
Write-Output "Service: LibreNMSWindowsAgent ($($installed.ServiceStatus))"
