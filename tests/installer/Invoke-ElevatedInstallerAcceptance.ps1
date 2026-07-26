[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$InstallerScript,
    [Parameter(Mandatory = $true)][string]$BaseUrl,
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][string]$TestRoot,
    [string]$PreviousMsiPath = ''
)

$ErrorActionPreference = 'Stop'
$serviceName = 'LibreNMSWindowsAgent'
$dataDirectory = [IO.Path]::GetFullPath((Join-Path $env:ProgramData 'LibreNMS\Windows Agent'))
$installDirectory = [IO.Path]::GetFullPath((Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent'))
$workDirectory = Join-Path $TestRoot 'installer-work'
$script:uninstallCount = 0

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'This acceptance test requires an elevated PowerShell session.'
    }
}

function Get-AgentProductCode {
    $roots = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
    )
    foreach ($root in $roots) {
        if (-not (Test-Path -LiteralPath $root)) {
            continue
        }
        foreach ($key in Get-ChildItem -LiteralPath $root) {
            $item = Get-ItemProperty -LiteralPath $key.PSPath
            if ($item.DisplayName -eq 'LibreNMS Windows Agent') {
                return $key.PSChildName
            }
        }
    }
    return ''
}

function Invoke-AgentInstaller {
    & $resolvedInstaller `
        -Version $Version `
        -BaseUrl $BaseUrl `
        -WorkDir $workDirectory `
        -Silent
}

function Assert-AgentOperational {
    param([string]$ExpectedInstalledVersion)

    $service = Get-Service -Name $serviceName -ErrorAction Stop
    if ($service.Status -ne 'Running') {
        throw "$serviceName is $($service.Status), expected Running."
    }
    $serviceRecord = Get-CimInstance Win32_Service -Filter "Name='$serviceName'"
    if ($serviceRecord.PathName -notmatch [regex]::Escape($installDirectory)) {
        throw "Unexpected service path: $($serviceRecord.PathName)"
    }
    $executable = Join-Path $installDirectory 'LibreNMS.WindowsAgent.Service.exe'
    if ((Get-Item -LiteralPath $executable).VersionInfo.FileVersion -ne "$ExpectedInstalledVersion.0") {
        throw "The installed executable does not report version $ExpectedInstalledVersion.0."
    }
    $config = Get-Content -LiteralPath (Join-Path $dataDirectory 'agent.json') -Raw | ConvertFrom-Json
    if ([string]$config.listener.address -ne '0.0.0.0' -or [int]$config.listener.port -ne 6556) {
        throw 'The installed listener configuration is not 0.0.0.0:6556.'
    }
    $client = New-Object Net.Sockets.TcpClient
    try {
        $asyncResult = $client.BeginConnect('127.0.0.1', 6556, $null, $null)
        if (-not $asyncResult.AsyncWaitHandle.WaitOne(5000)) {
            throw 'Timed out connecting to the installed agent.'
        }
        $client.EndConnect($asyncResult)
        $client.ReceiveTimeout = 15000
        $stream = $client.GetStream()
        $buffer = New-Object byte[] 65536
        $count = $stream.Read($buffer, 0, $buffer.Length)
        $response = [Text.Encoding]::UTF8.GetString($buffer, 0, $count)
        if ($response -notmatch '<<<windows_agent>>>') {
            throw 'The installed agent did not return its protocol header.'
        }
    } finally {
        $client.Close()
    }
}

function Uninstall-Agent {
    $productCode = Get-AgentProductCode
    if (-not $productCode) {
        return
    }
    $script:uninstallCount++
    $uninstallLog = Join-Path $TestRoot "uninstall-$($script:uninstallCount).log"
    $arguments = @('/x', $productCode, '/qn', 'REBOOT=ReallySuppress', '/norestart', '/L*V', "`"$uninstallLog`"")
    $process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru -WindowStyle Hidden
    if ($process.ExitCode -notin @(0, 1605, 3010)) {
        throw "Acceptance cleanup uninstall failed with exit code $($process.ExitCode). Log: $uninstallLog"
    }
}

function Install-PreviousAgent {
    param([string]$MsiPath)

    $resolvedMsi = (Resolve-Path -LiteralPath $MsiPath).Path
    $logPath = Join-Path $TestRoot 'previous-version-install.log'
    $arguments = @('/i', "`"$resolvedMsi`"", '/qn', 'REBOOT=ReallySuppress', '/norestart', '/L*V', "`"$logPath`"")
    $process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru -WindowStyle Hidden
    if ($process.ExitCode -notin @(0, 3010)) {
        throw "Previous-version MSI installation failed with exit code $($process.ExitCode). Log: $logPath"
    }
}

function Remove-TestResidue {
    & netsh.exe advfirewall firewall delete rule 'name=LibreNMS Windows Agent TCP 6556 (Domain)' | Out-Null
    & netsh.exe advfirewall firewall delete rule 'name=LibreNMS Windows Agent TCP 6556 (Private)' | Out-Null

    $expectedDataDirectory = [IO.Path]::GetFullPath((Join-Path $env:ProgramData 'LibreNMS\Windows Agent'))
    if ($dataDirectory -eq $expectedDataDirectory -and (Test-Path -LiteralPath $dataDirectory)) {
        Remove-Item -LiteralPath $dataDirectory -Recurse -Force
    }
    $expectedInstallDirectory = [IO.Path]::GetFullPath((Join-Path $env:ProgramFiles 'LibreNMS\Windows Agent'))
    if ($installDirectory -eq $expectedInstallDirectory -and (Test-Path -LiteralPath $installDirectory)) {
        Remove-Item -LiteralPath $installDirectory -Recurse -Force
    }
    Remove-Item -LiteralPath 'HKLM:\SOFTWARE\LibreNMS\Windows Agent' -Recurse -Force -ErrorAction SilentlyContinue
}

Assert-Administrator
$resolvedInstaller = (Resolve-Path -LiteralPath $InstallerScript).Path
New-Item -ItemType Directory -Force -Path $TestRoot, $workDirectory | Out-Null

if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
    throw "Refusing acceptance test because $serviceName already exists."
}
if (Get-AgentProductCode) {
    throw 'Refusing acceptance test because a LibreNMS Windows Agent MSI is already registered.'
}
if (Test-Path -LiteralPath $dataDirectory) {
    throw "Refusing acceptance test because the data directory already exists: $dataDirectory"
}
if (Test-Path -LiteralPath $installDirectory) {
    throw "Refusing acceptance test because the install directory already exists: $installDirectory"
}

try {
    if ($PreviousMsiPath) {
        Write-Output 'Acceptance: previous-version installation'
        Install-PreviousAgent -MsiPath $PreviousMsiPath
        $previousVersion = (Get-Item -LiteralPath (Join-Path $installDirectory 'LibreNMS.WindowsAgent.Service.exe')).VersionInfo.FileVersion
        $previousThreeFieldVersion = $previousVersion -replace '\.0$', ''
        Assert-AgentOperational -ExpectedInstalledVersion $previousThreeFieldVersion

        $preservedConfigPath = Join-Path $dataDirectory 'agent.json'
        $preservedConfig = Get-Content -LiteralPath $preservedConfigPath -Raw | ConvertFrom-Json
        $preservedConfig.listener.cacheRefreshSeconds = 137
        [IO.File]::WriteAllText(
            $preservedConfigPath,
            ($preservedConfig | ConvertTo-Json -Depth 30) + [Environment]::NewLine,
            [Text.UTF8Encoding]::new($false))

        Write-Output 'Acceptance: version-boundary upgrade with configuration preservation'
        Invoke-AgentInstaller
        Assert-AgentOperational -ExpectedInstalledVersion $Version
        $upgradedConfig = Get-Content -LiteralPath $preservedConfigPath -Raw | ConvertFrom-Json
        if ([int]$upgradedConfig.listener.cacheRefreshSeconds -ne 137) {
            throw 'The version-boundary upgrade did not preserve the existing configuration.'
        }

        Uninstall-Agent
        Remove-TestResidue
        if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
            throw 'The service remained after upgrade-scenario cleanup.'
        }
    }

    Write-Output 'Acceptance: fresh one-command installation'
    Invoke-AgentInstaller
    Assert-AgentOperational -ExpectedInstalledVersion $Version

    Write-Output 'Acceptance: same-version repair'
    Invoke-AgentInstaller
    Assert-AgentOperational -ExpectedInstalledVersion $Version

    Write-Output 'Acceptance: clean uninstall'
    Uninstall-Agent
    if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
        throw 'The service remained after uninstall.'
    }
    if (Get-AgentProductCode) {
        throw 'The MSI registration remained after uninstall.'
    }

    Write-Output 'Acceptance: occupied-port preflight'
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 6556)
    $listener.Start()
    try {
        $blocked = $false
        try {
            Invoke-AgentInstaller
        } catch {
            if ($_.Exception.Message -match 'TCP port 6556 is already in use') {
                $blocked = $true
            } else {
                throw
            }
        }
        if (-not $blocked) {
            throw 'The installer did not block an occupied listener port.'
        }
        if (Get-AgentProductCode) {
            throw 'The occupied-port preflight changed MSI registration.'
        }
    } finally {
        $listener.Stop()
    }

    Write-Output 'Acceptance: reinstall after a preflight refusal'
    Invoke-AgentInstaller
    Assert-AgentOperational -ExpectedInstalledVersion $Version

    Write-Output "Elevated installer acceptance passed for $Version."
} finally {
    Uninstall-Agent
    Remove-TestResidue
    if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
        throw 'Acceptance cleanup left the service installed.'
    }
    if (Get-AgentProductCode) {
        throw 'Acceptance cleanup left MSI registration installed.'
    }
}
