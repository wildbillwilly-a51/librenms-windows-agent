[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [Alias('i')]
    [string]$InstallDir,

    [Parameter(Mandatory = $true)]
    [Alias('d')]
    [string]$DataDir,

    [Alias('a')]
    [string]$ListenAddress = '0.0.0.0',
    [Alias('p')]
    [int]$ListenPort = 6556,
    [Alias('f')]
    [int]$AddFirewallRule = 1,
    [Alias('s')]
    [int]$StartService = 1,
    [Alias('c')]
    [string]$ConfigPath = '',
    [Alias('k')]
    [int]$PreserveConfig = 1
)

$ErrorActionPreference = 'Stop'

$serviceName = 'LibreNMSWindowsAgent'
$ruleName = 'LibreNMS Windows Agent TCP 6556'
$configTarget = Join-Path $DataDir 'agent.json'
$templatePath = Join-Path $InstallDir 't.json'
$exePath = Join-Path $InstallDir 'LibreNMS.WindowsAgent.Service.exe'
$logPath = Join-Path $DataDir 'install.log'

if ($ConfigPath -eq '__DEFAULT__') {
    $ConfigPath = ''
}

function Write-InstallLog {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    $line = (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK') + ' ' + $Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
}

function Write-JsonConfig {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [object]$Config
    )

    $json = $Config | ConvertTo-Json -Depth 16
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($Path, $json + [Environment]::NewLine, $utf8NoBom)
}

New-Item -ItemType Directory -Force -Path $DataDir | Out-Null
Write-InstallLog "Configuring $serviceName from installDir=$InstallDir dataDir=$DataDir listen=${ListenAddress}:$ListenPort firewall=$AddFirewallRule start=$StartService preserveConfig=$PreserveConfig configPath=$ConfigPath"

$shouldWriteConfig = (-not (Test-Path -LiteralPath $configTarget)) -or ($PreserveConfig -eq 0)
if ($shouldWriteConfig) {
    if ($ConfigPath -and (Test-Path -LiteralPath $ConfigPath)) {
        Copy-Item -LiteralPath $ConfigPath -Destination $configTarget -Force
    } else {
        if (-not (Test-Path -LiteralPath $templatePath)) {
            throw "Config template not found: $templatePath"
        }

        $config = Get-Content -LiteralPath $templatePath -Raw | ConvertFrom-Json
        $config.listener.address = $ListenAddress
        $config.listener.port = $ListenPort
        $config.logging.path = (Join-Path $DataDir 'agent.log')
        Write-JsonConfig -Path $configTarget -Config $config
    }
}

if (Test-Path -LiteralPath $configTarget) {
    $config = Get-Content -LiteralPath $configTarget -Raw | ConvertFrom-Json
    $config.listener.address = $ListenAddress
    $config.listener.port = $ListenPort
    $config.listener.allowedClients = @()
    $config.logging.path = (Join-Path $DataDir 'agent.log')
    Write-JsonConfig -Path $configTarget -Config $config
    Write-InstallLog "Listener config normalized: address=${ListenAddress} port=$ListenPort allowedClients=any"
}

if (-not (Test-Path -LiteralPath $exePath)) {
    throw "Agent executable not found after MSI file install: $exePath"
}

if ($AddFirewallRule -eq 1) {
    try {
        $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
        if ($existing) {
            $existing | Remove-NetFirewallRule
        }

        New-NetFirewallRule `
            -DisplayName $ruleName `
            -Direction Inbound `
            -Action Allow `
            -Protocol TCP `
            -LocalPort $ListenPort `
            -Profile Domain,Private `
            -Description 'Allow LibreNMS pollers to reach the LibreNMS Windows Agent.' | Out-Null
        Write-InstallLog "Firewall rule created: $ruleName"
    } catch {
        Write-InstallLog "WARNING: Firewall rule setup failed: $($_.Exception.Message)"
    }
}

& $exePath --validate-config --config $configTarget
Write-InstallLog "Config validation passed: $configTarget"

if ($StartService -eq 1) {
    try {
        $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($service -and $service.Status -eq 'Running') {
            Restart-Service -Name $serviceName -Force -ErrorAction Stop
            Write-InstallLog "Service restarted: $serviceName"
        } else {
            Start-Service -Name $serviceName -ErrorAction Stop
            Write-InstallLog "Service started: $serviceName"
        }

        $service = Get-Service -Name $serviceName -ErrorAction Stop
        $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(30))
    } catch {
        Write-InstallLog "WARNING: Service start failed: $($_.Exception.Message)"
    }
}

Write-InstallLog "Configuration completed."
