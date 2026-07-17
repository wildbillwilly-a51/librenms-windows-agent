[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [string]$ArtifactsDir = '',
    [string]$Version = ''
)

$ErrorActionPreference = 'Stop'
$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
if (-not $ArtifactsDir) { $ArtifactsDir = Join-Path $repoRoot 'artifacts' }
if (-not $Version) {
    [xml]$props = Get-Content -LiteralPath (Join-Path $repoRoot 'Directory.Build.props')
    $Version = $props.Project.PropertyGroup.Version
}
if (-not $Version) { throw 'Could not determine MSI version.' }

$workRoot = Join-Path ([IO.Path]::GetTempPath()) ('librenms-windows-agent-msi-' + [guid]::NewGuid().ToString('N'))
$payloadDir = Join-Path $workRoot 'payload'
$assetsDir = Join-Path $workRoot 'assets'
$msiOutputDir = Join-Path $workRoot 'msi'
$targetMsi = Join-Path $ArtifactsDir "librenms-windows-agent-$Version.msi"
$utf8NoBom = [Text.UTF8Encoding]::new($false)

function Get-MsiProperty {
    param([string]$MsiPath, [string]$Name)
    $installer = New-Object -ComObject WindowsInstaller.Installer
    $database = $installer.GetType().InvokeMember('OpenDatabase', 'InvokeMethod', $null, $installer, @($MsiPath, 0))
    $view = $database.GetType().InvokeMember('OpenView', 'InvokeMethod', $null, $database, @("SELECT ``Value`` FROM ``Property`` WHERE ``Property``='$Name'"))
    try {
        $view.GetType().InvokeMember('Execute', 'InvokeMethod', $null, $view, $null) | Out-Null
        $record = $view.GetType().InvokeMember('Fetch', 'InvokeMethod', $null, $view, $null)
        if (-not $record) { return '' }
        return $record.GetType().InvokeMember('StringData', 'GetProperty', $null, $record, 1)
    } finally {
        $view.GetType().InvokeMember('Close', 'InvokeMethod', $null, $view, $null) | Out-Null
    }
}

function Assert-MsiMetadata {
    param([string]$MsiPath, [string]$ExpectedVersion)
    $expectedUpgradeCode = '{7AA78970-198D-4B26-B9FD-FF05F42298B8}'
    $legacyFixedProductCode = '{46FDE2D6-2BDD-4A07-A834-4943C2734D24}'
    if ((Get-MsiProperty $MsiPath 'ProductName') -ne 'LibreNMS Windows Agent') { throw 'Unexpected MSI product name.' }
    if ((Get-MsiProperty $MsiPath 'ProductVersion') -ne $ExpectedVersion) { throw 'Unexpected MSI product version.' }
    if ((Get-MsiProperty $MsiPath 'UpgradeCode') -ne $expectedUpgradeCode) { throw 'Unexpected MSI upgrade code.' }
    $productCode = Get-MsiProperty $MsiPath 'ProductCode'
    if (-not $productCode -or $productCode -eq $legacyFixedProductCode) { throw 'MSI ProductCode was not regenerated.' }
}

function Get-MsiTableValue {
    param([string]$MsiPath, [string]$Query, [int]$Column = 1)
    $installer = New-Object -ComObject WindowsInstaller.Installer
    $database = $installer.GetType().InvokeMember('OpenDatabase', 'InvokeMethod', $null, $installer, @($MsiPath, 0))
    try {
        $view = $database.GetType().InvokeMember('OpenView', 'InvokeMethod', $null, $database, @($Query))
    } catch {
        throw "MSI query failed: $Query. $($_.Exception.Message)"
    }
    try {
        $view.GetType().InvokeMember('Execute', 'InvokeMethod', $null, $view, $null) | Out-Null
        $record = $view.GetType().InvokeMember('Fetch', 'InvokeMethod', $null, $view, $null)
        if (-not $record) { return '' }
        return $record.GetType().InvokeMember('StringData', 'GetProperty', $null, $record, $Column)
    } finally {
        $view.GetType().InvokeMember('Close', 'InvokeMethod', $null, $view, $null) | Out-Null
    }
}

function Assert-MsiInstallBehavior {
    param([string]$MsiPath)
    $sequence = Get-MsiTableValue $MsiPath "SELECT ``Sequence`` FROM ``InstallExecuteSequence`` WHERE ``Action``='RemoveExistingProducts'"
    $initializeSequence = Get-MsiTableValue $MsiPath "SELECT ``Sequence`` FROM ``InstallExecuteSequence`` WHERE ``Action``='InstallInitialize'"
    if (-not $sequence -or -not $initializeSequence -or [int]$sequence -le [int]$initializeSequence) {
        throw 'RemoveExistingProducts must run after InstallInitialize so failed upgrades can roll back safely.'
    }
    $upgradeAttributes = Get-MsiTableValue $MsiPath "SELECT ``Attributes`` FROM ``Upgrade`` WHERE ``ActionProperty``='WIX_UPGRADE_DETECTED'"
    if (-not $upgradeAttributes -or (([int]$upgradeAttributes -band 512) -eq 0)) {
        throw 'The repaired package must allow an existing package with the same three-field version to upgrade.'
    }
    $customActionTable = Get-MsiTableValue $MsiPath "SELECT ``Name`` FROM ``_Tables`` WHERE ``Name``='CustomAction'"
    if ($customActionTable) {
        $configureAction = Get-MsiTableValue $MsiPath "SELECT ``Action`` FROM ``CustomAction`` WHERE ``Action``='ConfigureAgent'"
        if ($configureAction) { throw 'The MSI must not use the legacy PowerShell ConfigureAgent custom action.' }
    }
    $configureScript = Get-MsiTableValue $MsiPath "SELECT ``File`` FROM ``File`` WHERE ``File``='ConfigureAgentScript'"
    if ($configureScript) { throw 'The MSI must not package the legacy PowerShell configuration script.' }
    $serviceEvents = Get-MsiTableValue $MsiPath "SELECT ``Event`` FROM ``ServiceControl`` WHERE ``Name``='LibreNMSWindowsAgent'"
    if (-not $serviceEvents -or (([int]$serviceEvents -band 1) -eq 0)) { throw 'Windows Installer is not configured to start the agent service.' }
    $configAttributes = Get-MsiTableValue $MsiPath "SELECT ``Attributes`` FROM ``Component`` WHERE ``Component``='AgentConfig'"
    if (-not $configAttributes -or (([int]$configAttributes -band 16) -eq 0) -or (([int]$configAttributes -band 128) -eq 0)) {
        throw 'The MSI-owned default config must be permanent and never overwrite an existing config.'
    }
    $configFile = Get-MsiTableValue $MsiPath "SELECT ``FileName`` FROM ``File`` WHERE ``File``='AgentConfigFile'"
    if ($configFile -notmatch 'agent\.json$') { throw 'The MSI does not contain the default agent.json file.' }
    $domainFirewallPort = Get-MsiTableValue $MsiPath "SELECT ``Port`` FROM ``Wix5FirewallException`` WHERE ``Wix5FirewallException``='AgentFirewallDomain'"
    $privateFirewallPort = Get-MsiTableValue $MsiPath "SELECT ``Port`` FROM ``Wix5FirewallException`` WHERE ``Wix5FirewallException``='AgentFirewallPrivate'"
    if ($domainFirewallPort -ne '6556' -or $privateFirewallPort -ne '6556') {
        throw 'The MSI does not contain both native TCP 6556 firewall rules.'
    }
}

New-Item -ItemType Directory -Force -Path $workRoot, $payloadDir, $assetsDir, $msiOutputDir, $ArtifactsDir | Out-Null
try {
    $serviceProject = Join-Path $repoRoot 'src\LibreNMS.WindowsAgent.Service\LibreNMS.WindowsAgent.Service.csproj'
    & dotnet build $serviceProject -c $Configuration -p:DebugType=None -p:DebugSymbols=false
    if ($LASTEXITCODE -ne 0) { throw 'Windows agent build failed.' }

    $buildOutput = Join-Path $repoRoot "src\LibreNMS.WindowsAgent.Service\bin\$Configuration\net462"
    foreach ($name in @('LibreNMS.WindowsAgent.Service.exe', 'LibreNMS.WindowsAgent.Service.exe.config', 'LibreNMS.WindowsAgent.Core.dll')) {
        Copy-Item -LiteralPath (Join-Path $buildOutput $name) -Destination $payloadDir -Force
    }

    $config = Get-Content -Raw -LiteralPath (Join-Path $repoRoot 'samples\agent.json') | ConvertFrom-Json
    $config.listener.allowedClients = @()
    $config.logging.path = '%ProgramData%\LibreNMS\Windows Agent\agent.log'
    $config.collectors.factoryTalk.nativeCountersMode = 'local'
    [IO.File]::WriteAllText((Join-Path $assetsDir 'agent.template.json'), ($config | ConvertTo-Json -Depth 30), $utf8NoBom)

    $legacyLabel = ('a' + '51')
    $privateDomainLabel = ('ama' + 'son')
    $privateProjectLabel = ('home' + 'lab')
    $legacyPattern = '(?i)\b' + [regex]::Escape($legacyLabel) + '\b|' +
        [regex]::Escape($privateDomainLabel) + '|10\.9\.|' + [regex]::Escape($privateProjectLabel)
    $legacy = Get-ChildItem -LiteralPath $payloadDir, $assetsDir -Recurse -File |
        Where-Object { $_.Extension -in @('.config', '.json', '.ps1', '.txt', '.xml') } |
        Select-String -Pattern $legacyPattern
    if ($legacy) {
        $paths = $legacy | ForEach-Object Path | Sort-Object -Unique
        throw "MSI staging contains private or legacy identifiers:`n$($paths -join "`n")"
    }

    $validationConfig = Join-Path $workRoot 'agent.validation.json'
    $config.listener.address = '127.0.0.1'
    $config.logging.path = '%TEMP%\librenms-windows-agent-build.log'
    [IO.File]::WriteAllText($validationConfig, ($config | ConvertTo-Json -Depth 30), $utf8NoBom)
    $onceOutput = & (Join-Path $payloadDir 'LibreNMS.WindowsAgent.Service.exe') --once --config $validationConfig
    if ($LASTEXITCODE -ne 0) { throw 'Windows agent --once validation failed.' }
    $onceText = $onceOutput -join "`n"
    if ($onceText -notmatch '<<<windows_agent>>>' -or $onceText -notmatch '<<<windows_agent_performance>>>') {
        throw 'Windows agent did not emit required generic sections.'
    }
    if ($onceText -notmatch 'collectors_run=22') { throw 'Windows agent did not run all default collectors.' }

    $wixProject = Join-Path $repoRoot 'installer\wix\LibreNMS.WindowsAgent.wixproj'
    & dotnet build $wixProject -c $Configuration -p:ProductVersion=$Version -p:PayloadDir="$payloadDir" -p:AssetsDir="$assetsDir" -p:OutputPath="$msiOutputDir\"
    if ($LASTEXITCODE -ne 0) { throw 'WiX MSI build failed.' }
    $builtMsi = Get-ChildItem -LiteralPath $msiOutputDir -Filter "librenms-windows-agent-$Version.msi" -Recurse -File | Select-Object -First 1
    if (-not $builtMsi) { throw 'Built MSI was not found.' }
    Assert-MsiMetadata -MsiPath $builtMsi.FullName -ExpectedVersion $Version
    Assert-MsiInstallBehavior -MsiPath $builtMsi.FullName
    Copy-Item -LiteralPath $builtMsi.FullName -Destination $targetMsi -Force
    Write-Output $targetMsi
} finally {
    Remove-Item -LiteralPath $workRoot -Recurse -Force -ErrorAction SilentlyContinue
}
