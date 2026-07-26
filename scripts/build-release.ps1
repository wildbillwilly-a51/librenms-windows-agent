[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [string]$Version = '',
    [string]$AgentVersion = '',
    [string]$ArtifactsDir = '',
    [switch]$SkipTests,
    [switch]$OverlayOnly,
    [switch]$UpdateChecksums
)

$ErrorActionPreference = 'Stop'
$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
if (-not $ArtifactsDir) { $ArtifactsDir = Join-Path $repoRoot 'artifacts' }
[xml]$props = Get-Content -LiteralPath (Join-Path $repoRoot 'Directory.Build.props')
if (-not $AgentVersion) { $AgentVersion = $props.Project.PropertyGroup.Version }
if (-not $Version) { $Version = $AgentVersion }
if (-not $Version) { throw 'Could not determine release version.' }

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
$overlay = & (Join-Path $PSScriptRoot 'build-overlay-package.ps1') -Version $Version -ArtifactsDir $ArtifactsDir
if ($LASTEXITCODE -ne 0) { throw 'Overlay build failed.' }
$overlay = ($overlay | Select-Object -Last 1).Trim()

$msiHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $msi).Hash.ToLowerInvariant()
$overlayHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $overlay).Hash.ToLowerInvariant()
if ($UpdateChecksums) {
    $manifest = @(
        "$overlayHash  artifacts/librenms-windows-agent-overlay-$Version.tar.gz",
        "$msiHash  artifacts/librenms-windows-agent-$AgentVersion.msi"
    ) -join "`n"
    [IO.File]::WriteAllText((Join-Path $repoRoot 'SHA256SUMS'), ($manifest + "`n"), [Text.UTF8Encoding]::new($false))
}

Write-Output "Overlay: $overlay"
Write-Output "Overlay SHA256: $overlayHash"
Write-Output "MSI: $msi"
Write-Output "MSI SHA256: $msiHash"
