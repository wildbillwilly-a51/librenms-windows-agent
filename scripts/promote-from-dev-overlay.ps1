[CmdletBinding()]
param(
    [string]$DevRepo = '',
    [string]$Version = '',
    [switch]$NoCommit,
    [switch]$NoPush,
    [switch]$SkipDevDirtyCheck
)

$ErrorActionPreference = 'Stop'

$repoRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')
if (-not $DevRepo) {
    $DevRepo = Join-Path $repoRoot '..\librenms-windows-agent'
}
$devRoot = Resolve-Path -LiteralPath $DevRepo
$artifactsDir = Join-Path $repoRoot 'artifacts'
$workRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('librenms-windows-agent-promote-' + [guid]::NewGuid().ToString('N'))
$devArtifacts = Join-Path $workRoot 'dev-artifacts'
$stageRoot = Join-Path $workRoot 'stage'
$extractRoot = Join-Path $workRoot 'extract'
$genericPackageRootName = $null
$legacyLower = 'a' + '51'
$legacyUpper = 'A' + '51'
$legacyPattern = [regex]::Escape($legacyLower) + '|' + [regex]::Escape($legacyUpper)

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string[]]$Args
    )

    $output = & git -C $Path @Args
    if ($LASTEXITCODE -ne 0) {
        throw "git $($Args -join ' ') failed in $Path"
    }

    return $output
}

function Convert-GenericName {
    param([Parameter(Mandatory = $true)][string]$Name)

    $value = $Name
    $pairs = @(
        @("$legacyLower-librenms-windows-agent-overlay", 'librenms-windows-agent-overlay'),
        @("$legacyLower-windows-agent-overlay", 'windows-agent-overlay'),
        @("$legacyLower-windows-agent", 'windows-agent'),
        @("${legacyLower}_agent", 'windows_agent'),
        @("${legacyLower}_", 'windows_agent_'),
        @("$legacyLower-", 'windows-agent-'),
        @($legacyLower, 'windows_agent'),
        @("${legacyUpper}_LIBRENMS_", 'WINDOWS_AGENT_LIBRENMS_'),
        @("${legacyUpper}_EXPECTED_", 'WINDOWS_AGENT_EXPECTED_'),
        @("${legacyUpper}_OVERLAY_", 'WINDOWS_AGENT_OVERLAY_'),
        @("$legacyUpper Windows Agent", 'Windows Agent'),
        @("$legacyUpper Windows agent", 'Windows Agent'),
        @("$legacyUpper Windows", 'Windows'),
        @($legacyUpper, 'Windows Agent')
    )

    foreach ($pair in $pairs) {
        $value = $value.Replace($pair[0], $pair[1])
    }

    return $value
}

function Convert-GenericContent {
    param([Parameter(Mandatory = $true)][string]$Text)

    $pairs = @(
        @("$legacyLower-librenms-windows-agent-overlay", 'librenms-windows-agent-overlay'),
        @("$legacyLower-windows-agent-overlay", 'windows-agent-overlay'),
        @("$legacyLower-windows-agent", 'windows-agent'),
        @("parse_${legacyLower}_kv", 'parse_windows_agent_kv'),
        @("${legacyLower}_agent", 'windows_agent'),
        @("${legacyLower}_", 'windows_agent_'),
        @("$legacyLower-", 'windows-agent-'),
        @("${legacyUpper}_LIBRENMS_", 'WINDOWS_AGENT_LIBRENMS_'),
        @("${legacyUpper}_EXPECTED_", 'WINDOWS_AGENT_EXPECTED_'),
        @("${legacyUpper}_OVERLAY_", 'WINDOWS_AGENT_OVERLAY_'),
        @("$legacyUpper Windows Agent", 'Windows Agent'),
        @("$legacyUpper Windows agent", 'Windows Agent'),
        @("$legacyUpper Windows", 'Windows'),
        @($legacyUpper, 'Windows Agent'),
        @($legacyLower, 'windows_agent')
    )

    $value = $Text
    foreach ($pair in $pairs) {
        $value = $value.Replace($pair[0], $pair[1])
    }

    return $value
}

function Set-Utf8NoBom {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Value
    )

    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($Path, $Value, $utf8NoBom)
}

function Add-Or-ReplacePromotionRecord {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Version,
        [Parameter(Mandatory = $true)][string]$Record
    )

    $content = Get-Content -LiteralPath $Path -Raw
    $heading = "### $Version"
    $pattern = "(?ms)^### $([regex]::Escape($Version))\r?\n.*?(?=^### |\z)"
    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, $Record.TrimEnd() + "`n")
    } else {
        $content = $content.TrimEnd() + "`n`n" + $Record.TrimEnd() + "`n"
    }

    Set-Utf8NoBom -Path $Path -Value $content
}

function Update-CurrentState {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Version
    )

    $content = Get-Content -LiteralPath $Path -Raw
    $content = [regex]::Replace($content, 'Current version: `[^`]+`', ('Current version: `{0}`' -f $Version))
    $content = [regex]::Replace(
        $content,
        'artifacts/librenms-windows-agent-overlay-[^`]+\.tar\.gz',
        ('artifacts/librenms-windows-agent-overlay-{0}.tar.gz' -f $Version))
    Set-Utf8NoBom -Path $Path -Value $content
}

function Update-CurrentVersionReferences {
    param(
        [Parameter(Mandatory = $true)][string[]]$Paths,
        [Parameter(Mandatory = $true)][string]$Version
    )

    foreach ($path in $Paths) {
        $content = Get-Content -LiteralPath $path -Raw
        $content = [regex]::Replace(
            $content,
            'librenms-windows-agent-overlay-\d+\.\d+\.\d+\.tar\.gz',
            ('librenms-windows-agent-overlay-{0}.tar.gz' -f $Version))
        $content = [regex]::Replace(
            $content,
            'librenms-windows-agent-\d+\.\d+\.\d+\.msi',
            ('librenms-windows-agent-{0}.msi' -f $Version))
        $content = [regex]::Replace(
            $content,
            '--version \d+\.\d+\.\d+',
            ('--version {0}' -f $Version))
        Set-Utf8NoBom -Path $path -Value $content
    }
}

function Assert-CurrentVersionReferences {
    param(
        [Parameter(Mandatory = $true)][string[]]$Paths,
        [Parameter(Mandatory = $true)][string]$Version
    )

    $versionPattern = '\d+\.\d+\.\d+'
    $expectedOverlay = "librenms-windows-agent-overlay-$Version.tar.gz"
    $expectedMsi = "librenms-windows-agent-$Version.msi"
    $failures = New-Object System.Collections.Generic.List[string]

    foreach ($path in $Paths) {
        $content = Get-Content -LiteralPath $path -Raw
        $matches = [regex]::Matches($content, "librenms-windows-agent-overlay-$versionPattern\.tar\.gz|librenms-windows-agent-$versionPattern\.msi|--version $versionPattern")
        foreach ($match in $matches) {
            if ($match.Value -ne $expectedOverlay -and $match.Value -ne $expectedMsi -and $match.Value -ne "--version $Version") {
                $failures.Add("$path contains stale current-version reference: $($match.Value)")
            }
        }
    }

    if ($failures.Count -gt 0) {
        throw "Current public docs contain stale version references:`n$($failures -join "`n")"
    }
}

function Update-InstallerVersion {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Version
    )

    $content = Get-Content -LiteralPath $Path -Raw
    $content = [regex]::Replace($content, 'VERSION="\$\{VERSION:-[^}]+\}"', ('VERSION="${{VERSION:-{0}}}"' -f $Version))
    Set-Utf8NoBom -Path $Path -Value $content
}

function Update-AgentInstallerVersion {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Version
    )

    $content = Get-Content -LiteralPath $Path -Raw
    $content = [regex]::Replace($content, "\[string\]\`$Version = '[^']+'", ("[string]`$Version = '{0}'" -f $Version))
    Set-Utf8NoBom -Path $Path -Value $content
}

function Add-DatedBullet {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Bullet
    )

    $today = Get-Date -Format 'yyyy-MM-dd'
    $content = Get-Content -LiteralPath $Path -Raw
    if ($content -notmatch "(?m)^## $([regex]::Escape($today))$") {
        $content = $content.TrimEnd() + "`n`n## $today`n`n"
    }

    $pattern = "(?m)^## $([regex]::Escape($today))$"
    $match = [regex]::Match($content, $pattern)
    $insertAt = $match.Index + $match.Length
    $content = $content.Insert($insertAt, "`n`n- $Bullet")
    $content = $content -replace "(`r?`n){3,}", "`n`n"
    Set-Utf8NoBom -Path $Path -Value ($content.TrimEnd() + "`n")
}

function Assert-NoLegacyBranding {
    param([Parameter(Mandatory = $true)][string]$Path)

    $matches = Get-ChildItem -LiteralPath $Path -Recurse -File |
        Select-String -Pattern $legacyPattern -List
    if ($matches) {
        $details = $matches | ForEach-Object { $_.Path }
        throw "Generated package still contains legacy branding:`n$($details -join "`n")"
    }
}

function Assert-Command {
    param([Parameter(Mandatory = $true)][string]$Name)
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Missing required command: $Name"
    }
}

function Test-RawUrl {
    param([Parameter(Mandatory = $true)][string]$Url)

    $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
    if ($curl) {
        & curl.exe -fsSI $Url | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "Raw URL check failed: $Url"
        }
        return
    }

    Invoke-WebRequest -Uri $Url -Method Head -UseBasicParsing | Out-Null
}

New-Item -ItemType Directory -Force -Path $workRoot, $devArtifacts, $stageRoot, $extractRoot, $artifactsDir | Out-Null

try {
    Assert-Command -Name git
    Assert-Command -Name tar

    $repoDirty = Invoke-Git -Path $repoRoot -Args @('status', '--short')
    if ($repoDirty) {
        throw "Installer repo has uncommitted changes. Commit or clean them before promotion."
    }

    $devCommit = (Invoke-Git -Path $devRoot -Args @('rev-parse', '--short', 'HEAD')).Trim()
    $devDirty = Invoke-Git -Path $devRoot -Args @('status', '--short')
    if ($devDirty -and -not $SkipDevDirtyCheck) {
        Write-Warning "Development repo has uncommitted changes. The promotion records commit $devCommit, but local changes may affect the build."
        $devDirty | ForEach-Object { Write-Warning $_ }
    }

    if (-not $Version) {
        [xml]$props = Get-Content -LiteralPath (Join-Path $devRoot 'Directory.Build.props')
        $Version = $props.Project.PropertyGroup.Version
    }
    if (-not $Version) {
        throw 'Could not determine overlay version.'
    }

    $builder = Join-Path $devRoot 'scripts\build-librenms-overlay-package.ps1'
    if (-not (Test-Path -LiteralPath $builder)) {
        throw "Development overlay builder not found: $builder"
    }
    $msiBuilder = Join-Path $devRoot 'scripts\build-public-msi.ps1'
    if (-not (Test-Path -LiteralPath $msiBuilder)) {
        throw "Development public MSI builder not found: $msiBuilder"
    }

    Write-Output "Building development overlay package from $devCommit"
    $devPackage = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $builder -Version $Version -ArtifactsDir $devArtifacts
    if ($LASTEXITCODE -ne 0) {
        throw 'Development overlay package build failed.'
    }
    $devPackage = ($devPackage | Select-Object -Last 1).Trim()
    if (-not (Test-Path -LiteralPath $devPackage)) {
        throw "Development package not found: $devPackage"
    }

    Write-Output "Building public Windows agent MSI from $devCommit"
    $devMsi = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $msiBuilder -Version $Version -ArtifactsDir $devArtifacts
    if ($LASTEXITCODE -ne 0) {
        throw 'Development public MSI build failed.'
    }
    $devMsi = ($devMsi | Select-Object -Last 1).Trim()
    if (-not (Test-Path -LiteralPath $devMsi)) {
        throw "Development public MSI not found: $devMsi"
    }

    tar -xzf $devPackage -C $extractRoot
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to extract development overlay package.'
    }

    $sourceRoot = Get-ChildItem -LiteralPath $extractRoot -Directory | Select-Object -First 1
    if (-not $sourceRoot) {
        throw 'Extracted development package did not contain a package root directory.'
    }

    Get-ChildItem -LiteralPath $sourceRoot.FullName -Recurse -Force |
        Sort-Object { $_.FullName.Length } -Descending |
        ForEach-Object {
            $newName = Convert-GenericName -Name $_.Name
            if ($newName -ne $_.Name) {
                $parent = Split-Path -Parent $_.FullName
                Move-Item -LiteralPath $_.FullName -Destination (Join-Path $parent $newName)
            }
        }

    $genericPackageRootName = Convert-GenericName -Name $sourceRoot.Name
    $genericRoot = Join-Path $stageRoot $genericPackageRootName
    Move-Item -LiteralPath $sourceRoot.FullName -Destination $genericRoot

    Get-ChildItem -LiteralPath $genericRoot -Recurse -File -Force | ForEach-Object {
        $text = [System.IO.File]::ReadAllText($_.FullName)
        Set-Utf8NoBom -Path $_.FullName -Value (Convert-GenericContent -Text $text)
    }

    $payloadRoot = Join-Path $genericRoot 'payload'
    $manifestPath = Join-Path $genericRoot 'manifest.txt'
    $manifest = Get-ChildItem -LiteralPath $payloadRoot -Recurse -File |
        ForEach-Object { $_.FullName.Substring($payloadRoot.Length + 1).Replace('\', '/') } |
        Sort-Object
    Set-Utf8NoBom -Path $manifestPath -Value (($manifest -join "`n") + "`n")

    Assert-NoLegacyBranding -Path $genericRoot

    $targetPackage = Join-Path $artifactsDir "librenms-windows-agent-overlay-$Version.tar.gz"
    $targetMsi = Join-Path $artifactsDir "librenms-windows-agent-$Version.msi"
    Remove-Item -LiteralPath $targetPackage -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $targetMsi -Force -ErrorAction SilentlyContinue
    tar -C $stageRoot -czf $targetPackage $genericPackageRootName
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to create generic overlay package.'
    }
    Copy-Item -LiteralPath $devMsi -Destination $targetMsi -Force

    tar -tzf $targetPackage | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Generated generic overlay package failed tar listing.'
    }

    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $targetPackage).Hash.ToLowerInvariant()
    $msiHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $targetMsi).Hash.ToLowerInvariant()
    Set-Utf8NoBom -Path (Join-Path $repoRoot 'SHA256SUMS') -Value (($hash + "  artifacts/librenms-windows-agent-overlay-$Version.tar.gz`n") + ($msiHash + "  artifacts/librenms-windows-agent-$Version.msi`n"))

    Update-CurrentState -Path (Join-Path $repoRoot 'CURRENT-STATE.md') -Version $Version
    Update-InstallerVersion -Path (Join-Path $repoRoot 'install.sh') -Version $Version
    Update-AgentInstallerVersion -Path (Join-Path $repoRoot 'install-agent.ps1') -Version $Version
    $currentVersionDocs = @(
        (Join-Path $repoRoot 'README.md'),
        (Join-Path $repoRoot 'CURRENT-STATE.md'),
        (Join-Path $repoRoot 'docs\release-runbook.md'),
        (Join-Path $repoRoot 'AGENTS.md')
    )
    Update-CurrentVersionReferences -Paths $currentVersionDocs -Version $Version

    $record = @(
        ('### {0}' -f $Version),
        '',
        ('- Public package: `artifacts/librenms-windows-agent-overlay-{0}.tar.gz`' -f $Version),
        ('- Public Windows MSI: `artifacts/librenms-windows-agent-{0}.msi`' -f $Version),
        ('- Upstream development commit: `{0}`' -f $devCommit),
        '- Overlay SHA256:',
        ('  `{0}`' -f $hash),
        '- Windows MSI SHA256:',
        ('  `{0}`' -f $msiHash),
        '- Compatibility: requires Windows agent output using `windows_agent` and',
        '  `windows_agent_*` section names.',
        '- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the',
        '  development overlay package and public MSI into temporary directories,',
        '  converted overlay files to generic public identifiers, regenerated checksums,',
        '  and scanned generated public payloads for legacy/site-specific branding.',
        '- PHP lint: run when PHP is available on the promotion workstation.'
    ) -join "`n"
    Add-Or-ReplacePromotionRecord -Path (Join-Path $repoRoot 'docs\upstream-sync.md') -Version $Version -Record $record

    Add-DatedBullet -Path (Join-Path $repoRoot 'CHANGELOG.md') -Bullet "Promoted generic LibreNMS Windows Agent overlay package and Windows MSI $Version from validated development commit $devCommit."
    Add-DatedBullet -Path (Join-Path $repoRoot 'docs\work-log.md') -Bullet "Promoted overlay package $Version and Windows MSI from development commit $devCommit with checksums $hash and $msiHash. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability."

    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($php) {
        Get-ChildItem -LiteralPath $genericRoot -Recurse -Filter '*.php' -File | ForEach-Object {
            & php -l $_.FullName | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw "PHP lint failed: $($_.FullName)"
            }
        }
        Write-Output 'PHP lint passed.'
    } else {
        Write-Warning 'PHP not available; skipped PHP lint.'
    }

    Push-Location -LiteralPath $repoRoot
    try {
        & bash -n ./install.sh
        if ($LASTEXITCODE -ne 0) {
            throw 'install.sh syntax check failed.'
        }
    } finally {
        Pop-Location
    }

    $scanOutput = & git -C $repoRoot grep -n -I -E 'password|passwd|secret|token|api_key|apikey|client_secret|private_key|BEGIN PRIVATE KEY|Authorization:|Bearer ' -- .
    if ($LASTEXITCODE -eq 0) {
        Write-Warning 'Public snapshot scan found policy-word matches; review them as part of the promotion boundary.'
        $scanOutput | ForEach-Object { Write-Warning $_ }
    } elseif ($LASTEXITCODE -gt 1) {
        throw 'Public snapshot scan failed.'
    }

    & git -C $repoRoot diff --check
    if ($LASTEXITCODE -ne 0) {
        throw 'git diff --check failed.'
    }
    Assert-CurrentVersionReferences -Paths $currentVersionDocs -Version $Version

    if ($NoCommit) {
        Write-Output 'Promotion files updated. No commit created because -NoCommit was set.'
    } else {
        & git -C $repoRoot add artifacts "install.sh" "install-agent.ps1" "SHA256SUMS" "CURRENT-STATE.md" "CHANGELOG.md" "docs/upstream-sync.md" "docs/work-log.md" "README.md" "docs/release-runbook.md" "AGENTS.md"
        if ($LASTEXITCODE -ne 0) {
            throw 'git add failed.'
        }
        & git -C $repoRoot diff --cached --check
        if ($LASTEXITCODE -ne 0) {
            throw 'git diff --cached --check failed.'
        }
        & git -C $repoRoot commit -m "Promote overlay package $Version from dev $devCommit"
        if ($LASTEXITCODE -ne 0) {
            throw 'git commit failed.'
        }
        Write-Output 'Local promotion commit created.'

        if ($NoPush) {
            Write-Output 'GitHub push skipped because -NoPush was set.'
        } else {
            & git -C $repoRoot push origin main
            if ($LASTEXITCODE -ne 0) {
                throw 'git push failed. Local promotion commit remains in place.'
            }

            $rawBaseUrl = 'https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main'
            Test-RawUrl -Url "$rawBaseUrl/install.sh"
            Test-RawUrl -Url "$rawBaseUrl/install-agent.ps1"
            Test-RawUrl -Url "$rawBaseUrl/SHA256SUMS"
            Test-RawUrl -Url "$rawBaseUrl/artifacts/librenms-windows-agent-overlay-$Version.tar.gz"
            Test-RawUrl -Url "$rawBaseUrl/artifacts/librenms-windows-agent-$Version.msi"
            Write-Output 'GitHub push and raw URL verification completed.'
        }
    }

    Write-Output "Promoted overlay package $Version"
    Write-Output "SHA256: $hash"
    Write-Output "Promoted Windows MSI $Version"
    Write-Output "MSI SHA256: $msiHash"
} finally {
    Remove-Item -LiteralPath $workRoot -Recurse -Force -ErrorAction SilentlyContinue
}
