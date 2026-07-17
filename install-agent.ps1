[CmdletBinding()]
param(
    [string]$Version = '0.6.13',
    [string]$RepoOwner = 'wildbillwilly-a51',
    [string]$RepoName = 'librenms-windows-agent',
    [string]$RepoBranch = 'main',
    [string]$WorkDir = "$env:TEMP\librenms-windows-agent",
    [string]$ListenAddress = '0.0.0.0',
    [int]$ListenPort = 6556,
    [int]$AddFirewallRule = 1,
    [int]$StartService = 1,
    [int]$PreserveConfig = 1,
    [ValidateSet(0, 1)]
    [int]$EnableFactoryTalkNativeCounters = 1,
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
    param(
        [Parameter(Mandatory = $true)][string]$ExpectedVersion,
        [Parameter(Mandatory = $true)][string]$ExpectedFactoryTalkNativeCountersMode,
        [Parameter(Mandatory = $true)][bool]$ExpectedServiceRunning
    )

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
    $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
    $actualNativeCountersMode = [string]$config.collectors.factoryTalk.nativeCountersMode
    if ($actualNativeCountersMode -ne $ExpectedFactoryTalkNativeCountersMode) {
        throw "FactoryTalk native counter mode mismatch. Expected $ExpectedFactoryTalkNativeCountersMode but found $actualNativeCountersMode in $configPath."
    }

    $service = Get-Service -Name LibreNMSWindowsAgent -ErrorAction SilentlyContinue
    if (-not $service) {
        throw 'LibreNMSWindowsAgent service was not found after installation.'
    }
    $expectedStatus = if ($ExpectedServiceRunning) { 'Running' } else { 'Stopped' }
    $service.WaitForStatus($expectedStatus, [TimeSpan]::FromSeconds(30))
    $service.Refresh()
    if ($ExpectedServiceRunning -and $service.Status -ne 'Running') {
        throw "LibreNMSWindowsAgent service is $($service.Status), expected Running."
    }
    if (-not $ExpectedServiceRunning -and $service.Status -ne 'Stopped') {
        throw "LibreNMSWindowsAgent service is $($service.Status), expected Stopped."
    }

    [pscustomobject]@{
        ExePath = $candidateExe
        FileVersion = $actualVersion
        ConfigPath = $configPath
        ServiceStatus = $service.Status
    }
}

function Set-AgentConfiguration {
    $target = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.json'
    $template = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\t.json'

    if ($ConfigPath) {
        if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
            throw "ConfigPath was not found: $ConfigPath"
        }
        Copy-Item -LiteralPath $ConfigPath -Destination $target -Force
    } elseif ($PreserveConfig -eq 0) {
        Copy-Item -LiteralPath $template -Destination $target -Force
    }

    $config = Get-Content -LiteralPath $target -Raw | ConvertFrom-Json
    $config.listener.address = $ListenAddress
    $config.listener.port = $ListenPort
    $config.listener.allowedClients = @()
    $config.logging.path = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.log'
    if (-not $config.collectors.factoryTalk) {
        $config.collectors | Add-Member -NotePropertyName factoryTalk -NotePropertyValue ([pscustomobject]@{}) -Force
    }
    $nativeMode = if ($EnableFactoryTalkNativeCounters -eq 1) { 'local' } else { 'disabled' }
    $factoryTalkValues = [ordered]@{
        mode = 'auto'
        includeProducts = $true
        includeServices = $true
        includeProcesses = $true
        includeRuntimeMetrics = $true
        includePorts = $true
        nativeCountersMode = $nativeMode
        nativeCounterIntervalSeconds = 900
        nativeCounterTimeoutSeconds = 30
    }
    foreach ($entry in $factoryTalkValues.GetEnumerator()) {
        $config.collectors.factoryTalk | Add-Member -NotePropertyName $entry.Key -NotePropertyValue $entry.Value -Force
    }
    if ($null -eq $config.collectors.factoryTalk.nativeCounterExecutablePath) {
        $config.collectors.factoryTalk | Add-Member -NotePropertyName nativeCounterExecutablePath -NotePropertyValue '' -Force
    }
    $json = $config | ConvertTo-Json -Depth 30
    $encoding = New-Object -TypeName System.Text.UTF8Encoding -ArgumentList $false
    [IO.File]::WriteAllText($target, $json + [Environment]::NewLine, $encoding)
}

function Set-AgentFirewall {
    param([Parameter(Mandatory = $true)][string]$AgentExe)

    $rules = @(
        @{ Name = 'LibreNMS Windows Agent TCP 6556 (Domain)'; Profile = 'domain' },
        @{ Name = 'LibreNMS Windows Agent TCP 6556 (Private)'; Profile = 'private' }
    )
    foreach ($rule in $rules) {
        & netsh.exe advfirewall firewall delete rule "name=$($rule.Name)" | Out-Null
        if ($LASTEXITCODE -notin @(0, 1)) { throw "Failed to remove firewall rule $($rule.Name)." }
    }
    if ($AddFirewallRule -eq 1) {
        foreach ($rule in $rules) {
            & netsh.exe advfirewall firewall add rule "name=$($rule.Name)" dir=in action=allow protocol=TCP "localport=$ListenPort" "profile=$($rule.Profile)" "program=$AgentExe" enable=yes | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "Failed to create firewall rule $($rule.Name)." }
        }
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
    "`"$msiPath`""
)

if ($Silent) {
    $arguments += '/qn'
}

$process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru
if ($process.ExitCode -ne 0 -and $process.ExitCode -ne 3010) {
    throw "msiexec failed with exit code $($process.ExitCode)."
}

$expectedExe = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe'
Set-AgentConfiguration
if ($AddFirewallRule -eq 0 -or $ListenPort -ne 6556) {
    Set-AgentFirewall -AgentExe $expectedExe
}
if ($StartService -eq 1) {
    Restart-Service -Name LibreNMSWindowsAgent -Force -ErrorAction Stop
}
if ($StartService -eq 0) {
    Stop-Service -Name LibreNMSWindowsAgent -Force -ErrorAction Stop
}

$expectedNativeCountersMode = if ($EnableFactoryTalkNativeCounters -eq 1) { 'local' } else { 'disabled' }
$installed = Assert-AgentInstalled -ExpectedVersion $Version -ExpectedFactoryTalkNativeCountersMode $expectedNativeCountersMode -ExpectedServiceRunning ($StartService -eq 1)
Write-Output "Installed LibreNMS Windows Agent $Version"
Write-Output "Executable: $($installed.ExePath)"
Write-Output "Config: $($installed.ConfigPath)"
Write-Output "FactoryTalk native counters: $expectedNativeCountersMode"
Write-Output "Service: LibreNMSWindowsAgent ($($installed.ServiceStatus))"
