[CmdletBinding()]
param(
    [string]$Version = '',
    [string]$ArtifactsDir = ''
)

$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
$overlayRoot = Join-Path $repoRoot 'librenms-overlay'
if (-not $ArtifactsDir) {
    $ArtifactsDir = Join-Path $repoRoot 'artifacts'
}
if (-not $Version) {
    [xml]$props = Get-Content -LiteralPath (Join-Path $repoRoot 'Directory.Build.props')
    $Version = $props.Project.PropertyGroup.Version
}
if (-not $Version) {
    throw 'Could not determine overlay version.'
}

$packageName = "librenms-windows-agent-overlay-$Version"
$workRoot = Join-Path ([IO.Path]::GetTempPath()) ($packageName + '-' + [guid]::NewGuid().ToString('N'))
$stagingRoot = Join-Path $workRoot $packageName
$payloadRoot = Join-Path $stagingRoot 'payload'
$supportRoot = Join-Path $payloadRoot 'windows-agent-overlay'
$targetPackage = Join-Path $ArtifactsDir "$packageName.tar.gz"
$utf8NoBom = [Text.UTF8Encoding]::new($false)

if (-not (Test-Path -LiteralPath (Join-Path $overlayRoot 'includes'))) {
    throw "Overlay source is missing: $overlayRoot\includes"
}

New-Item -ItemType Directory -Force -Path $payloadRoot, $supportRoot, $ArtifactsDir | Out-Null
try {
    Copy-Item -LiteralPath (Join-Path $overlayRoot 'includes') -Destination $payloadRoot -Recurse -Force
    Copy-Item -LiteralPath (Join-Path $overlayRoot 'tools\validate-app.php') -Destination $supportRoot -Force
    Copy-Item -LiteralPath (Join-Path $overlayRoot 'tools\delete-apps.php') -Destination $supportRoot -Force

    foreach ($name in @('install-overlay.sh', 'rollback-overlay.sh', 'validate-overlay.sh', 'web-validate.py', 'README.md')) {
        Copy-Item -LiteralPath (Join-Path $overlayRoot $name) -Destination $stagingRoot -Force
    }
    Copy-Item -LiteralPath (Join-Path $overlayRoot 'alerts') -Destination $stagingRoot -Recurse -Force
    Copy-Item -LiteralPath (Join-Path $overlayRoot 'systemd') -Destination $stagingRoot -Recurse -Force

    [string[]]$manifest = Get-ChildItem -LiteralPath $payloadRoot -Recurse -File |
        ForEach-Object { $_.FullName.Substring($payloadRoot.Length + 1).Replace('\', '/') }
    [Array]::Sort($manifest, [StringComparer]::Ordinal)
    [IO.File]::WriteAllText((Join-Path $stagingRoot 'manifest.txt'), (($manifest -join "`n") + "`n"), $utf8NoBom)

    $legacyLabel = ('a' + '51')
    $privateDomainLabel = ('ama' + 'son')
    $privateProjectLabel = ('home' + 'lab')
    $legacyPattern = '(?i)\b' + [regex]::Escape($legacyLabel) + '\b|' +
        [regex]::Escape($privateDomainLabel) + '|10\.9\.|' + [regex]::Escape($privateProjectLabel)
    $legacy = Get-ChildItem -LiteralPath $stagingRoot -Recurse -File |
        Where-Object { $_.Extension -in @('.php', '.sh', '.py', '.md', '.txt', '.service', '.timer') } |
        Select-String -Pattern $legacyPattern
    if ($legacy) {
        $paths = $legacy | ForEach-Object Path | Sort-Object -Unique
        throw "Overlay staging contains private or legacy identifiers:`n$($paths -join "`n")"
    }

    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($php) {
        Get-ChildItem -LiteralPath $stagingRoot -Recurse -Filter '*.php' -File | ForEach-Object {
            & php -l $_.FullName | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
        }
    } else {
        Write-Warning 'PHP is unavailable; skipped overlay PHP lint.'
    }

    tar -C $workRoot -czf $targetPackage $packageName
    if ($LASTEXITCODE -ne 0) { throw 'Overlay tarball creation failed.' }
    tar -tzf $targetPackage | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Overlay tarball listing failed.' }
    Write-Output $targetPackage
} finally {
    Remove-Item -LiteralPath $workRoot -Recurse -Force -ErrorAction SilentlyContinue
}
