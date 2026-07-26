[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$MsiPath,
    [Parameter(Mandatory = $true)][string]$ConfigPath,
    [Parameter(Mandatory = $true)][string]$ExpectedVersion
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$installerScript = Join-Path $repoRoot 'install-agent.ps1'
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $installerScript,
    [ref]$tokens,
    [ref]$parseErrors)
if ($parseErrors.Count -gt 0) {
    throw "install-agent.ps1 has parse errors: $($parseErrors -join '; ')"
}

$source = Get-Content -LiteralPath $installerScript -Raw
$requiredPatterns = [ordered]@{
    'verbose MSI logging' = "'/L\*V'"
    'rollback-safe major upgrade' = 'START_AGENT_SERVICE=\$StartService'
    'reboot suppression' = "'REBOOT=ReallySuppress'"
    'versioned configuration artifact' = 'librenms-windows-agent-config-\$Version\.json'
    'framework preflight' = 'minimumDotNetRelease = 394802'
    'listener preflight' = 'Get-ListeningOwners -Port \$Port'
    'MSI failure evidence' = 'Write-MsiFailureEvidence'
}
foreach ($entry in $requiredPatterns.GetEnumerator()) {
    if ($source -notmatch $entry.Value) {
        throw "install-agent.ps1 is missing $($entry.Key)."
    }
}
if ($source -match 'Uninstall-ExistingAgentPackages' -or $source -match "msiexec(?:\.exe)?['`"]?\s+.*?/x") {
    throw 'install-agent.ps1 must not uninstall an MSI before the replacement transaction.'
}

$configurationOffset = $source.IndexOf('Set-AgentConfiguration -TemplatePath')
$msiOffset = $source.IndexOf('$process = Start-Process')
if ($configurationOffset -lt 0 -or $msiOffset -lt 0 -or $configurationOffset -gt $msiOffset) {
    throw 'The final configuration must be prepared before Windows Installer starts the service.'
}

$versionParameter = $ast.ParamBlock.Parameters |
    Where-Object { $_.Name.VariablePath.UserPath -eq 'Version' } |
    Select-Object -First 1
if (-not $versionParameter -or $versionParameter.DefaultValue.SafeGetValue() -ne $ExpectedVersion) {
    throw "install-agent.ps1 does not default to version $ExpectedVersion."
}

$resolvedMsi = (Resolve-Path -LiteralPath $MsiPath).Path
$resolvedConfig = (Resolve-Path -LiteralPath $ConfigPath).Path
$workRoot = Join-Path ([IO.Path]::GetTempPath()) ('librenms-agent-installer-test-' + [guid]::NewGuid().ToString('N'))
$extractRoot = Join-Path $workRoot 'extract'
$logPath = Join-Path $workRoot 'administrative-install.log'
New-Item -ItemType Directory -Force -Path $extractRoot | Out-Null

try {
    $arguments = @(
        '/a',
        "`"$resolvedMsi`"",
        "TARGETDIR=`"$extractRoot`"",
        '/qn',
        '/L*V',
        "`"$logPath`""
    )
    $process = Start-Process -FilePath msiexec.exe -ArgumentList $arguments -Wait -PassThru -WindowStyle Hidden
    if ($process.ExitCode -ne 0) {
        $tail = if (Test-Path -LiteralPath $logPath) { (Get-Content -LiteralPath $logPath -Tail 50) -join [Environment]::NewLine } else { 'No log was created.' }
        throw "MSI administrative extraction failed with exit code $($process.ExitCode).`n$tail"
    }

    $serviceExe = Get-ChildItem -LiteralPath $extractRoot -Filter 'LibreNMS.WindowsAgent.Service.exe' -Recurse -File | Select-Object -First 1
    if (-not $serviceExe) {
        throw 'The extracted MSI does not contain LibreNMS.WindowsAgent.Service.exe.'
    }
    if ($serviceExe.VersionInfo.FileVersion -ne "$ExpectedVersion.0") {
        throw "Extracted service version is $($serviceExe.VersionInfo.FileVersion), expected $ExpectedVersion.0."
    }

    $validationOutput = & $serviceExe.FullName --validate-config --config $resolvedConfig 2>&1
    if ($LASTEXITCODE -ne 0 -or ($validationOutput -join "`n") -notmatch 'Configuration OK') {
        throw "The exact packaged configuration failed service validation: $($validationOutput -join [Environment]::NewLine)"
    }
} finally {
    $resolvedWorkRoot = [IO.Path]::GetFullPath($workRoot)
    $resolvedTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolvedWorkRoot.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase) -and
        (Test-Path -LiteralPath $resolvedWorkRoot)) {
        Remove-Item -LiteralPath $resolvedWorkRoot -Recurse -Force
    }
}

Write-Output "Installer source and administrative MSI extraction passed for $ExpectedVersion."
