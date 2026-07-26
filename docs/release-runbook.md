# Release Runbook

Releases are built directly from the universal source in this repository.
There is no private-repository promotion or identifier-conversion step.

## 1. Choose The Version

Update the version in `Directory.Build.props`, including `Version`,
`AssemblyVersion`, and `FileVersion`. Use a new MSI version for every public
agent upgrade.

For an overlay-only release, leave `Directory.Build.props`, the MSI artifact,
and Windows installer version unchanged. Update only the overlay version in
`install.sh`, the capability manifest, current public documentation, and the
overlay checksum entry.

Update current-version references in:

- `install.sh`
- `install-agent.ps1`
- `README.md`
- `CURRENT-STATE.md`
- `AGENTS.md`
- this runbook

## 2. Validate Source

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
bash -n ./install.sh
powershell.exe -NoProfile -Command "[void][scriptblock]::Create((Get-Content -Raw .\install-agent.ps1))"
```

When PHP is available:

```powershell
php .\tests\librenms-overlay\run-parser-fixtures.php
php .\tests\librenms-overlay\run-app-page-fixtures.php
php .\tests\librenms-overlay\run-horizon-central-tests.php
```

## 3. Build Native Release Payloads

```powershell
.\scripts\build-release.ps1 -UpdateChecksums
```

Overlay-only release while preserving the current MSI:

```powershell
.\scripts\build-release.ps1 -Version <overlay-version> -OverlayOnly -UpdateChecksums
```

Agent-only release while preserving the current overlay:

```powershell
.\scripts\build-release.ps1 -Version <agent-version> -AgentOnly -OverlayVersion <overlay-version> -UpdateChecksums
```

This builds:

- `artifacts/librenms-windows-agent-<version>.msi`
- `artifacts/librenms-windows-agent-config-<version>.json`
- `artifacts/librenms-windows-agent-overlay-<version>.tar.gz`

The MSI build validates generic service output, the 23 default collectors,
major-upgrade metadata, conditional service startup, the .NET Framework launch
condition, non-fatal firewall registration, and the stable UpgradeCode. The
portable installer integration test administratively extracts the exact MSI,
checks its executable version, and validates the matching versioned
configuration. The overlay builder creates the manifest from native
`librenms-overlay/` source, runs PHP lint when available, and rejects private
or legacy identifiers.

## 4. Verify Payloads

```powershell
tar -tzf .\artifacts\librenms-windows-agent-overlay-<version>.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-<version>.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-<version>.msi
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-config-<version>.json
Get-Content .\SHA256SUMS
```

Extract the overlay and run `php -l` over every PHP file when PHP is available.
Run `tests/installer/Invoke-ElevatedInstallerAcceptance.ps1` in a clean,
supported Windows environment and verify previous-version upgrade, fresh
install, same-version repair, occupied-port refusal, configuration
preservation, service restart, TCP response, uninstall, and cleanup behavior.
Every acceptance MSI log must finish with exit status zero and contain no
`Return value 3` marker.

## 5. Public-Safety Review

Review the complete committed snapshot, including unchanged files. Block the
release if it contains credentials, private keys, tokens, certificates, private
hostnames, private IP inventories, machine-user paths, customer details,
environment-specific device IDs, deployment helpers, or legacy branding.

Product/vendor names detected by supported collectors are allowed when they are
generic product functionality rather than environment facts.

## 6. Track, Commit, And Publish

Update `README.md`, `CURRENT-STATE.md`, `CHANGELOG.md`, and `docs/work-log.md`.
Commit only the reviewed release files. After the committed snapshot passes the
full public-safety review, push `main` to the public GitHub repository.

Verify the raw URLs for `install.sh`, `install-agent.ps1`, `SHA256SUMS`, the MSI,
and the overlay package after the push. If authentication or network access is
unavailable, keep the local commit and report publication as pending.
