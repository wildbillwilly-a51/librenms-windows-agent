[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [string]$Version = '',
    [string]$AgentVersion = '',
    [string]$OverlayVersion = '',
    [string]$ArtifactsDir = '',
    [switch]$SkipTests,
    [switch]$OverlayOnly,
    [switch]$AgentOnly,
    [switch]$UpdateChecksums
)

$ErrorActionPreference = 'Stop'
$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
if (-not $ArtifactsDir) { $ArtifactsDir = Join-Path $repoRoot 'artifacts' }
[xml]$props = Get-Content -LiteralPath (Join-Path $repoRoot 'Directory.Build.props')
if (-not $AgentVersion) { $AgentVersion = $props.Project.PropertyGroup.Version }
if (-not $Version) { $Version = $AgentVersion }
if (-not $Version) { throw 'Could not determine release version.' }
if ($OverlayOnly -and $AgentOnly) { throw 'OverlayOnly and AgentOnly cannot be used together.' }

if (-not $SkipTests) {
    & dotnet run --project (Join-Path $repoRoot 'tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj') -c $Configuration
    if ($LASTEXITCODE -ne 0) { throw 'Windows agent tests failed.' }
}

if ($OverlayOnly) {
    $msi = Join-Path $ArtifactsDir "librenms-windows-agent-$AgentVersion.msi"
    if (-not (Test-Path -LiteralPath $msi)) {
        throw "Preserved MSI is missing for overlay-only release: $msi"
    }
} else {
    $msi = & (Join-Path $PSScriptRoot 'build-msi.ps1') -Configuration $Configuration -Version $Version -ArtifactsDir $ArtifactsDir
    if ($LASTEXITCODE -ne 0) { throw 'MSI build failed.' }
    $msi = ($msi | Select-Object -Last 1).Trim()
    $AgentVersion = $Version
}
$agentConfig = Join-Path $ArtifactsDir "librenms-windows-agent-config-$AgentVersion.json"
if (-not (Test-Path -LiteralPath $agentConfig)) {
    if ($OverlayOnly) {
        $agentConfig = ''
    } else {
        throw "Versioned agent configuration is missing: $agentConfig"
    }
}
if (-not $OverlayOnly -and -not $SkipTests) {
    & (Join-Path $repoRoot 'tests\installer\Test-Installer.ps1') -MsiPath $msi -ConfigPath $agentConfig -ExpectedVersion $AgentVersion
    if ($LASTEXITCODE -ne 0) { throw 'Installer integration tests failed.' }
}

if ($AgentOnly) {
    if (-not $OverlayVersion) {
        $manifestPath = Join-Path $repoRoot 'SHA256SUMS'
        $overlayEntry = Get-Content -LiteralPath $manifestPath |
            Where-Object { $_ -match 'artifacts/librenms-windows-agent-overlay-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$' } |
            Select-Object -First 1
        if ($overlayEntry -and $overlayEntry -match 'librenms-windows-agent-overlay-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$') {
            $OverlayVersion = $matches[1]
        }
    }
    if (-not $OverlayVersion) { throw 'Could not determine the preserved overlay version.' }
    $overlay = Join-Path $ArtifactsDir "librenms-windows-agent-overlay-$OverlayVersion.tar.gz"
    if (-not (Test-Path -LiteralPath $overlay)) {
        throw "Preserved overlay is missing for agent-only release: $overlay"
    }
} else {
    $overlay = & (Join-Path $PSScriptRoot 'build-overlay-package.ps1') -Version $Version -ArtifactsDir $ArtifactsDir
    if ($LASTEXITCODE -ne 0) { throw 'Overlay build failed.' }
    $overlay = ($overlay | Select-Object -Last 1).Trim()
    $OverlayVersion = $Version
}

$msiHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $msi).Hash.ToLowerInvariant()
$agentConfigHash = if ($agentConfig) { (Get-FileHash -Algorithm SHA256 -LiteralPath $agentConfig).Hash.ToLowerInvariant() } else { '' }
$overlayHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $overlay).Hash.ToLowerInvariant()
if ($UpdateChecksums) {
    $manifestLines = @("$overlayHash  artifacts/librenms-windows-agent-overlay-$OverlayVersion.tar.gz")
    if ($agentConfig) {
        $manifestLines += "$agentConfigHash  artifacts/librenms-windows-agent-config-$AgentVersion.json"
    }
    $manifestLines += "$msiHash  artifacts/librenms-windows-agent-$AgentVersion.msi"
    $manifest = $manifestLines -join "`n"
    [IO.File]::WriteAllText((Join-Path $repoRoot 'SHA256SUMS'), ($manifest + "`n"), [Text.UTF8Encoding]::new($false))
}

Write-Output "Overlay: $overlay"
Write-Output "Overlay SHA256: $overlayHash"
if ($agentConfig) {
    Write-Output "Agent config: $agentConfig"
    Write-Output "Agent config SHA256: $agentConfigHash"
}
Write-Output "MSI: $msi"
Write-Output "MSI SHA256: $msiHash"
