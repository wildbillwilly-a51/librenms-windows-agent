[CmdletBinding()]
param(
    [string]$Version = '0.6.15',
    [string]$RepoOwner = 'wildbillwilly-a51',
    [string]$RepoName = 'librenms-windows-agent',
    [string]$RepoBranch = 'main',
    [string]$BaseUrl = '',
    [string]$WorkDir = "$env:TEMP\librenms-windows-agent",
    [string]$ListenAddress = '0.0.0.0',
    [int]$ListenPort = 6556,
    [ValidateSet(0, 1)]
    [int]$AddFirewallRule = 1,
    [ValidateSet(0, 1)]
    [int]$StartService = 1,
    [ValidateSet(0, 1)]
    [int]$PreserveConfig = 1,
    [ValidateSet(0, 1)]
    [int]$EnableFactoryTalkNativeCounters = 1,
    [string]$ConfigPath = '',
    [string]$LogPath = '',
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'
$serviceName = 'LibreNMSWindowsAgent'
$minimumDotNetRelease = 394802

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
                    DisplayVersion = [string]$item.DisplayVersion
                    ProductCode = $_.PSChildName
                    UninstallString = [string]$item.UninstallString
                }
            }
        }
    }
}

function Get-ServiceExecutablePath {
    param([object]$ServiceRecord)

    if (-not $ServiceRecord -or -not $ServiceRecord.PathName) {
        return ''
    }

    if ($ServiceRecord.PathName -match '^"([^"]+)"') {
        return $matches[1]
    }

    if ($ServiceRecord.PathName -match '^(.+?\.exe)\b') {
        return $matches[1]
    }

    return ''
}

function Get-DotNetFrameworkRelease {
    $path = 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full'
    if (-not (Test-Path -LiteralPath $path)) {
        return 0
    }

    return [int](Get-ItemPropertyValue -LiteralPath $path -Name Release -ErrorAction SilentlyContinue)
}

function Get-ListeningOwners {
    param([int]$Port)

    $connections = @()
    $command = Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue
    if ($command) {
        $connections = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    }

    if ($connections.Count -eq 0) {
        $pattern = '^\s*TCP\s+\S+:' + [regex]::Escape([string]$Port) + '\s+\S+\s+LISTENING\s+(\d+)\s*$'
        foreach ($line in @(& netstat.exe -ano -p tcp 2>$null)) {
            if ($line -match $pattern) {
                $connections += [pscustomobject]@{
                    LocalAddress = ($line -split '\s+')[2]
                    OwningProcess = [int]$matches[1]
                }
            }
        }
    }

    foreach ($connection in $connections) {
        $ownerPid = [int]$connection.OwningProcess
        $processName = ''
        if ($ownerPid -gt 0) {
            $processName = [string](Get-Process -Id $ownerPid -ErrorAction SilentlyContinue).ProcessName
        }
        [pscustomobject]@{
            LocalAddress = [string]$connection.LocalAddress
            OwningProcess = $ownerPid
            ProcessName = $processName
        }
    }
}

function Assert-InstallationPreflight {
    param(
        [string]$Address,
        [int]$Port,
        [object]$ExistingService,
        [object[]]$InstallRecords
    )

    if (-not [Environment]::Is64BitOperatingSystem) {
        throw 'LibreNMS Windows Agent requires 64-bit Windows.'
    }
    if ($Port -lt 1 -or $Port -gt 65535) {
        throw "ListenPort must be between 1 and 65535. Received: $Port"
    }
    $parsedAddress = $null
    if ($Address -ne '*' -and -not [Net.IPAddress]::TryParse($Address, [ref]$parsedAddress)) {
        throw "ListenAddress must be an IP address or '*'. Received: $Address"
    }

    $dotNetRelease = Get-DotNetFrameworkRelease
    if ($dotNetRelease -lt $minimumDotNetRelease) {
        throw "Microsoft .NET Framework 4.6.2 or later is required. Detected release value: $dotNetRelease."
    }

    $agentProcessId = 0
    if ($ExistingService) {
        $agentProcessId = [int]$ExistingService.ProcessId
    }
    $foreignOwners = @(Get-ListeningOwners -Port $Port | Where-Object {
        $agentProcessId -le 0 -or $_.OwningProcess -ne $agentProcessId
    })
    if ($foreignOwners.Count -gt 0) {
        $owners = ($foreignOwners | ForEach-Object {
            $name = if ($_.ProcessName) { $_.ProcessName } else { 'unknown' }
            "$name (PID $($_.OwningProcess), $($_.LocalAddress))"
        }) -join ', '
        throw "TCP port $Port is already in use by $owners. No installation changes were made. Choose another ListenPort or remove the conflict."
    }
}

function Get-VerifiedArtifact {
    param(
        [string]$BaseUrl,
        [string]$ArtifactPath,
        [string]$Destination,
        [string]$ChecksumPath
    )

    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/$ArtifactPath" -OutFile $Destination
    $expected = Get-Content -LiteralPath $ChecksumPath |
        Where-Object { $_ -match "\s+$([regex]::Escape($ArtifactPath))$" } |
        ForEach-Object { ($_ -split '\s+')[0].ToLowerInvariant() } |
        Select-Object -First 1
    if (-not $expected) {
        throw "No checksum entry found for $ArtifactPath."
    }

    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Destination).Hash.ToLowerInvariant()
    if ($actual -ne $expected) {
        throw "Checksum mismatch for $ArtifactPath. Expected $expected but got $actual."
    }
}

function Set-AgentConfiguration {
    param(
        [string]$TemplatePath,
        [string]$TargetPath
    )

    $sourcePath = $TemplatePath
    if ($ConfigPath) {
        if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
            throw "ConfigPath was not found: $ConfigPath"
        }
        $sourcePath = (Resolve-Path -LiteralPath $ConfigPath).Path
    } elseif ($PreserveConfig -eq 1 -and (Test-Path -LiteralPath $TargetPath -PathType Leaf)) {
        $sourcePath = $TargetPath
    }

    try {
        $config = Get-Content -LiteralPath $sourcePath -Raw | ConvertFrom-Json
    } catch {
        throw "Agent configuration is not valid JSON: $sourcePath. $($_.Exception.Message)"
    }

    if (-not $config.listener) {
        throw "Agent configuration is missing listener settings: $sourcePath"
    }
    if (-not $config.collectors) {
        throw "Agent configuration is missing collector settings: $sourcePath"
    }
    if (-not $config.logging) {
        $config | Add-Member -NotePropertyName logging -NotePropertyValue ([pscustomobject]@{}) -Force
    }

    $config.listener.address = $ListenAddress
    $config.listener.port = $ListenPort
    $config.listener.allowedClients = @()
    $config.logging | Add-Member -NotePropertyName path -NotePropertyValue (Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.log') -Force

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

    $targetDirectory = Split-Path -Parent $TargetPath
    New-Item -ItemType Directory -Force -Path $targetDirectory | Out-Null
    $json = $config | ConvertTo-Json -Depth 30
    $encoding = New-Object -TypeName System.Text.UTF8Encoding -ArgumentList $false
    [IO.File]::WriteAllText($TargetPath, $json + [Environment]::NewLine, $encoding)

    try {
        $null = Get-Content -LiteralPath $TargetPath -Raw | ConvertFrom-Json
    } catch {
        throw "Prepared agent configuration could not be read back: $TargetPath. $($_.Exception.Message)"
    }
}

function Set-AgentFirewall {
    param([string]$AgentExe)

    $rules = @(
        @{ Name = 'LibreNMS Windows Agent TCP 6556 (Domain)'; Profile = 'domain' },
        @{ Name = 'LibreNMS Windows Agent TCP 6556 (Private)'; Profile = 'private' }
    )
    $successful = $true
    foreach ($rule in $rules) {
        & netsh.exe advfirewall firewall delete rule "name=$($rule.Name)" | Out-Null
        if ($LASTEXITCODE -notin @(0, 1)) {
            Write-Warning "Could not remove firewall rule $($rule.Name); exit code $LASTEXITCODE."
            $successful = $false
        }
    }

    if ($AddFirewallRule -eq 1) {
        foreach ($rule in $rules) {
            & netsh.exe advfirewall firewall add rule "name=$($rule.Name)" dir=in action=allow protocol=TCP "localport=$ListenPort" "profile=$($rule.Profile)" "program=$AgentExe" enable=yes | Out-Null
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Could not create firewall rule $($rule.Name); exit code $LASTEXITCODE. The agent remains installed, but firewall policy must allow TCP $ListenPort."
                $successful = $false
            }
        }
    }

    return $successful
}

function Write-MsiFailureEvidence {
    param(
        [string]$MsiLogPath,
        [datetime]$Started
    )

    Write-Warning "Windows Installer verbose log: $MsiLogPath"
    if (Test-Path -LiteralPath $MsiLogPath) {
        $lines = @(Get-Content -LiteralPath $MsiLogPath -ErrorAction SilentlyContinue)
        $returnValueThree = @($lines | Select-String -SimpleMatch 'Return value 3' | Select-Object -Last 1)
        if ($returnValueThree.Count -gt 0) {
            $start = [Math]::Max(0, $returnValueThree[0].LineNumber - 31)
            Write-Warning 'Windows Installer failure context:'
            $lines | Select-Object -Skip $start -First 36 | ForEach-Object { Write-Warning $_ }
        } else {
            $lines | Select-Object -Last 40 | ForEach-Object { Write-Warning $_ }
        }
    }

    try {
        $events = @(Get-WinEvent -FilterHashtable @{ LogName = 'Application'; StartTime = $Started } -ErrorAction SilentlyContinue |
            Where-Object { $_.ProviderName -in @('MsiInstaller', '.NET Runtime', 'Application Error') } |
            Select-Object -First 8)
        foreach ($eventRecord in $events) {
            $message = ([string]$eventRecord.Message -replace '\s+', ' ').Trim()
            Write-Warning "Event $($eventRecord.ProviderName)/$($eventRecord.Id): $message"
        }
    } catch {
        Write-Verbose "Could not read related Windows events: $($_.Exception.Message)"
    }
}

function Test-AgentProtocol {
    param(
        [string]$Address,
        [int]$Port
    )

    $connectAddress = $Address
    if ([string]::IsNullOrWhiteSpace($connectAddress) -or $connectAddress -eq '*' -or $connectAddress -eq '0.0.0.0') {
        $connectAddress = '127.0.0.1'
    } elseif ($connectAddress -eq '::') {
        $connectAddress = '::1'
    }

    $client = New-Object Net.Sockets.TcpClient
    try {
        $asyncResult = $client.BeginConnect($connectAddress, $Port, $null, $null)
        if (-not $asyncResult.AsyncWaitHandle.WaitOne(5000)) {
            throw "Timed out connecting to $connectAddress`:$Port."
        }
        $client.EndConnect($asyncResult)
        $client.ReceiveTimeout = 15000
        $stream = $client.GetStream()
        $buffer = New-Object byte[] 65536
        $count = $stream.Read($buffer, 0, $buffer.Length)
        $response = [Text.Encoding]::UTF8.GetString($buffer, 0, $count)
        if ($response -notmatch '<<<windows_agent>>>') {
            throw "The listener at $connectAddress`:$Port did not return a LibreNMS Windows Agent payload."
        }
    } finally {
        $client.Close()
    }
}

function Assert-AgentInstalled {
    param(
        [string]$ExpectedVersion,
        [string]$ExpectedFactoryTalkNativeCountersMode,
        [bool]$ExpectedServiceRunning
    )

    $expectedExe = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe'
    $serviceRecord = Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction SilentlyContinue
    $serviceExe = Get-ServiceExecutablePath -ServiceRecord $serviceRecord
    $candidateExe = if ($serviceExe) { $serviceExe } else { $expectedExe }
    if (-not (Test-Path -LiteralPath $candidateExe)) {
        throw "Agent executable was not found after installation. Expected: $expectedExe. Service path: $serviceExe"
    }

    $actualVersion = (Get-Item -LiteralPath $candidateExe).VersionInfo.FileVersion
    if ($actualVersion -ne "$ExpectedVersion.0") {
        throw "Agent executable version mismatch. Expected $ExpectedVersion.0 but found $actualVersion at $candidateExe."
    }

    $installedConfigPath = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.json'
    if (-not (Test-Path -LiteralPath $installedConfigPath)) {
        throw "Agent configuration was not found after installation: $installedConfigPath"
    }
    $config = Get-Content -LiteralPath $installedConfigPath -Raw | ConvertFrom-Json
    $actualNativeCountersMode = [string]$config.collectors.factoryTalk.nativeCountersMode
    if ($actualNativeCountersMode -ne $ExpectedFactoryTalkNativeCountersMode) {
        throw "FactoryTalk native counter mode mismatch. Expected $ExpectedFactoryTalkNativeCountersMode but found $actualNativeCountersMode."
    }
    if ([string]$config.listener.address -ne $ListenAddress -or [int]$config.listener.port -ne $ListenPort) {
        throw "Listener configuration mismatch. Expected $ListenAddress`:$ListenPort."
    }

    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if (-not $service) {
        throw "$serviceName was not found after installation."
    }
    $expectedStatus = if ($ExpectedServiceRunning) { 'Running' } else { 'Stopped' }
    $service.WaitForStatus($expectedStatus, [TimeSpan]::FromSeconds(30))
    $service.Refresh()
    if ([string]$service.Status -ne $expectedStatus) {
        throw "$serviceName is $($service.Status), expected $expectedStatus."
    }
    if ($ExpectedServiceRunning) {
        Test-AgentProtocol -Address $ListenAddress -Port $ListenPort
    }

    [pscustomobject]@{
        ExePath = $candidateExe
        FileVersion = $actualVersion
        ConfigPath = $installedConfigPath
        ServiceStatus = $service.Status
    }
}

function Remove-LegacyAgentService {
    param([object]$ServiceRecord)

    if (-not $ServiceRecord) {
        return
    }
    $serviceExe = Get-ServiceExecutablePath -ServiceRecord $ServiceRecord
    if (-not $serviceExe -or -not (Test-Path -LiteralPath $serviceExe)) {
        throw "An unmanaged $serviceName service exists, but its executable could not be verified: $serviceExe"
    }
    $productName = [string](Get-Item -LiteralPath $serviceExe).VersionInfo.ProductName
    if ($productName -ne 'LibreNMS Windows Agent') {
        throw "An unmanaged service named $serviceName already exists at $serviceExe. It was not changed because it is not a verified LibreNMS Windows Agent binary."
    }

    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($service -and $service.Status -ne 'Stopped') {
        Stop-Service -Name $serviceName -Force
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
    }
    & sc.exe delete $serviceName | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not remove the verified unmanaged $serviceName service; sc.exe exit code $LASTEXITCODE."
    }
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if (-not (Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) {
            return
        }
        Start-Sleep -Milliseconds 500
    }
    throw "The verified unmanaged $serviceName service is still pending deletion."
}

function Restore-LegacyAgentService {
    param(
        [object]$ServiceRecord,
        [bool]$WasRunning,
        [string]$BackupDirectory,
        [string]$OriginalInstallDirectory
    )

    if (-not $ServiceRecord) {
        return
    }

    $serviceExe = Get-ServiceExecutablePath -ServiceRecord $ServiceRecord
    if ($BackupDirectory -and $OriginalInstallDirectory -and
        (Test-Path -LiteralPath $BackupDirectory) -and
        -not (Test-Path -LiteralPath $serviceExe)) {
        New-Item -ItemType Directory -Force -Path $OriginalInstallDirectory | Out-Null
        Get-ChildItem -LiteralPath $BackupDirectory -File | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination $OriginalInstallDirectory -Force
        }
    }

    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if (-not $service) {
        $startupType = switch ([string]$ServiceRecord.StartMode) {
            'Auto' { 'Automatic' }
            'Disabled' { 'Disabled' }
            default { 'Manual' }
        }
        New-Service -Name $serviceName -BinaryPathName ([string]$ServiceRecord.PathName) -DisplayName ([string]$ServiceRecord.DisplayName) -StartupType $startupType -Description ([string]$ServiceRecord.Description) | Out-Null
        $service = Get-Service -Name $serviceName
    }

    if ($WasRunning) {
        if ($service.Status -eq 'Running') {
            Restart-Service -Name $serviceName -Force
        } else {
            Start-Service -Name $serviceName
        }
    } elseif ($service.Status -ne 'Stopped') {
        Stop-Service -Name $serviceName -Force
    }
}

function Remove-InstallerBackups {
    param(
        [string]$ConfigBackupPath,
        [string]$LegacyBackupPath
    )

    $expectedConfigBackup = [IO.Path]::GetFullPath((Join-Path $WorkDir "agent-config-before-$Version.json"))
    if ($ConfigBackupPath -and [IO.Path]::GetFullPath($ConfigBackupPath) -eq $expectedConfigBackup) {
        Remove-Item -LiteralPath $ConfigBackupPath -Force -ErrorAction SilentlyContinue
    }

    $expectedLegacyBackup = [IO.Path]::GetFullPath((Join-Path $WorkDir "legacy-agent-files-before-$Version"))
    if ($LegacyBackupPath -and [IO.Path]::GetFullPath($LegacyBackupPath) -eq $expectedLegacyBackup -and
        (Test-Path -LiteralPath $LegacyBackupPath)) {
        Remove-Item -LiteralPath $LegacyBackupPath -Recurse -Force -ErrorAction SilentlyContinue
    }
}

Assert-Administrator

if (-not $BaseUrl) {
    $BaseUrl = "https://raw.githubusercontent.com/$RepoOwner/$RepoName/$RepoBranch"
}
$BaseUrl = $BaseUrl.TrimEnd('/')
$msiName = "librenms-windows-agent-$Version.msi"
$configName = "librenms-windows-agent-config-$Version-win.json"
$msiArtifactPath = "artifacts/$msiName"
$configArtifactPath = "artifacts/$configName"

New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
$msiPath = Join-Path $WorkDir $msiName
$templatePath = Join-Path $WorkDir $configName
$shaPath = Join-Path $WorkDir 'SHA256SUMS'
if (-not $LogPath) {
    $timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $LogPath = Join-Path $WorkDir "install-agent-$Version-$timestamp.log"
}
$LogPath = [IO.Path]::GetFullPath($LogPath)
$logDirectory = Split-Path -Parent $LogPath
if (-not $logDirectory) {
    throw "LogPath must include a directory: $LogPath"
}
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/SHA256SUMS" -OutFile $shaPath
Get-VerifiedArtifact -BaseUrl $BaseUrl -ArtifactPath $msiArtifactPath -Destination $msiPath -ChecksumPath $shaPath
Get-VerifiedArtifact -BaseUrl $BaseUrl -ArtifactPath $configArtifactPath -Destination $templatePath -ChecksumPath $shaPath

$installRecords = @(Get-AgentInstallRecords)
$existingServiceRecord = Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction SilentlyContinue
$existingServiceWasRunning = $existingServiceRecord -and [string]$existingServiceRecord.State -eq 'Running'
Assert-InstallationPreflight -Address $ListenAddress -Port $ListenPort -ExistingService $existingServiceRecord -InstallRecords $installRecords

$targetConfig = Join-Path $env:ProgramData 'LibreNMS\Windows Agent\agent.json'
$configExisted = Test-Path -LiteralPath $targetConfig -PathType Leaf
$configBackup = Join-Path $WorkDir "agent-config-before-$Version.json"
if ($configExisted) {
    Copy-Item -LiteralPath $targetConfig -Destination $configBackup -Force
}

$legacyBackupDirectory = ''
$legacyInstallDirectory = ''
$msiSucceeded = $false
$installStarted = Get-Date
try {
    Set-AgentConfiguration -TemplatePath $templatePath -TargetPath $targetConfig

    if ($existingServiceRecord -and $installRecords.Count -eq 0) {
        $legacyExecutable = Get-ServiceExecutablePath -ServiceRecord $existingServiceRecord
        if (-not $legacyExecutable -or -not (Test-Path -LiteralPath $legacyExecutable -PathType Leaf)) {
            throw "The unmanaged $serviceName executable could not be verified: $legacyExecutable"
        }
        if ([string](Get-Item -LiteralPath $legacyExecutable).VersionInfo.ProductName -ne 'LibreNMS Windows Agent') {
            throw "The unmanaged $serviceName executable is not a verified LibreNMS Windows Agent binary: $legacyExecutable"
        }
        $legacyInstallDirectory = Split-Path -Parent $legacyExecutable
        $legacyBackupDirectory = Join-Path $WorkDir "legacy-agent-files-before-$Version"
        New-Item -ItemType Directory -Force -Path $legacyBackupDirectory | Out-Null
        Get-ChildItem -LiteralPath $legacyInstallDirectory -File |
            Where-Object { $_.Name -like 'LibreNMS.WindowsAgent.*' -or $_.Name -eq 't.json' } |
            ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $legacyBackupDirectory -Force }
        Remove-LegacyAgentService -ServiceRecord $existingServiceRecord
    }

    $arguments = @(
        '/i',
        "`"$msiPath`"",
        "START_AGENT_SERVICE=$StartService",
        'REBOOT=ReallySuppress',
        '/norestart',
        '/L*V',
        "`"$LogPath`""
    )
    if ($Silent) {
        $arguments += '/qn'
    }

    $startProcessParameters = @{
        FilePath = 'msiexec.exe'
        ArgumentList = $arguments
        Wait = $true
        PassThru = $true
    }
    if ($Silent) {
        $startProcessParameters.WindowStyle = 'Hidden'
    }
    $process = Start-Process @startProcessParameters
    if ($process.ExitCode -ne 0 -and $process.ExitCode -ne 3010) {
        Write-MsiFailureEvidence -MsiLogPath $LogPath -Started $installStarted
        throw "Windows Installer failed with exit code $($process.ExitCode). Review: $LogPath"
    }
    $msiSucceeded = $true

    $expectedExe = Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe'
    $firewallConfigured = Set-AgentFirewall -AgentExe $expectedExe

    if ($StartService -eq 1) {
        $service = Get-Service -Name $serviceName -ErrorAction Stop
        if ($service.Status -ne 'Running') {
            Start-Service -Name $serviceName
        }
    } else {
        $service = Get-Service -Name $serviceName -ErrorAction Stop
        if ($service.Status -ne 'Stopped') {
            Stop-Service -Name $serviceName -Force
        }
    }

    $expectedNativeCountersMode = if ($EnableFactoryTalkNativeCounters -eq 1) { 'local' } else { 'disabled' }
    $installed = Assert-AgentInstalled -ExpectedVersion $Version -ExpectedFactoryTalkNativeCountersMode $expectedNativeCountersMode -ExpectedServiceRunning ($StartService -eq 1)
    Remove-InstallerBackups -ConfigBackupPath $configBackup -LegacyBackupPath $legacyBackupDirectory

    Write-Output "Installed LibreNMS Windows Agent $Version"
    Write-Output "Executable: $($installed.ExePath)"
    Write-Output "Config: $($installed.ConfigPath)"
    Write-Output "FactoryTalk native counters: $expectedNativeCountersMode"
    Write-Output "Service: $serviceName ($($installed.ServiceStatus))"
    Write-Output "Firewall rules configured: $firewallConfigured"
    Write-Output "Windows Installer log: $LogPath"
} catch {
    if (-not $msiSucceeded) {
        if ($configExisted -and (Test-Path -LiteralPath $configBackup)) {
            Copy-Item -LiteralPath $configBackup -Destination $targetConfig -Force
        } elseif (-not $configExisted -and (Test-Path -LiteralPath $targetConfig)) {
            Remove-Item -LiteralPath $targetConfig -Force -ErrorAction SilentlyContinue
        }

        if ($existingServiceRecord -and $installRecords.Count -eq 0) {
            Restore-LegacyAgentService -ServiceRecord $existingServiceRecord -WasRunning $existingServiceWasRunning -BackupDirectory $legacyBackupDirectory -OriginalInstallDirectory $legacyInstallDirectory
        } elseif ($existingServiceRecord) {
            $restoredService = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
            if ($restoredService) {
                if ($existingServiceWasRunning) {
                    Restart-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
                } elseif ($restoredService.Status -ne 'Stopped') {
                    Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
                }
            }
        }
        Remove-InstallerBackups -ConfigBackupPath $configBackup -LegacyBackupPath $legacyBackupDirectory
    }
    throw
}
