[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [string]$Version = '',
    [string]$ArtifactsDir = '',
    [switch]$SkipTests,
    [switch]$UpdateChecksums
)

$ErrorActionPreference = 'Stop'
$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
if (-not $ArtifactsDir) { $ArtifactsDir = Join-Path $repoRoot 'artifacts' }
if (-not $Version) {
    [xml]$props = Get-Content -LiteralPath (Join-Path $repoRoot 'Directory.Build.props')
    $Version = $props.Project.PropertyGroup.Version
}
if (-not $Version) { throw 'Could not determine release version.' }

if (-not $SkipTests) {
    & dotnet run --project (Join-Path $repoRoot 'tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj') -c $Configuration
    if ($LASTEXITCODE -ne 0) { throw 'Windows agent tests failed.' }
}

$msi = & (Join-Path $PSScriptRoot 'build-msi.ps1') -Configuration $Configuration -Version $Version -ArtifactsDir $ArtifactsDir
if ($LASTEXITCODE -ne 0) { throw 'MSI build failed.' }
$msi = ($msi | Select-Object -Last 1).Trim()
$overlay = & (Join-Path $PSScriptRoot 'build-overlay-package.ps1') -Version $Version -ArtifactsDir $ArtifactsDir
if ($LASTEXITCODE -ne 0) { throw 'Overlay build failed.' }
$overlay = ($overlay | Select-Object -Last 1).Trim()

$msiHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $msi).Hash.ToLowerInvariant()
$overlayHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $overlay).Hash.ToLowerInvariant()
if ($UpdateChecksums) {
    $manifest = @(
        "$overlayHash  artifacts/librenms-windows-agent-overlay-$Version.tar.gz",
        "$msiHash  artifacts/librenms-windows-agent-$Version.msi"
    ) -join "`n"
    [IO.File]::WriteAllText((Join-Path $repoRoot 'SHA256SUMS'), ($manifest + "`n"), [Text.UTF8Encoding]::new($false))
}

Write-Output "Overlay: $overlay"
Write-Output "Overlay SHA256: $overlayHash"
Write-Output "MSI: $msi"
Write-Output "MSI SHA256: $msiHash"
