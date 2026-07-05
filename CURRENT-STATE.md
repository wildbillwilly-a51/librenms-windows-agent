# Current State

This is the read-first handoff for the generic LibreNMS Windows Agent Installer
project.

## Project Boundary

- Local project root: the `librenms-windows-agent-installer` folder on the
  maintainer workstation.
- Public GitHub distribution mirror:
  `https://github.com/wildbillwilly-a51/librenms-windows-agent-installer`
- LibreNMS overlay installer entry point: `install.sh`
- Windows agent installer entry point: `install-agent.ps1`
- Published overlay package:
  `artifacts/librenms-windows-agent-overlay-0.6.3.tar.gz`
- Published Windows MSI:
  `artifacts/librenms-windows-agent-0.6.3.msi`
- Package checksum manifest: `SHA256SUMS`
- Project rules: `AGENTS.md`
- Work history: `docs/work-log.md`

The local Git repository is the primary project record. GitHub is the public
distribution mirror for sanitized installer content.

## Current Release

- Current version: `0.6.3`
- Public LibreNMS overlay install command:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh | sudo bash
```

- Public Windows agent silent install command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install-agent.ps1 -OutFile $env:TEMP\install-agent.ps1; & $env:TEMP\install-agent.ps1 -Silent"
```

The repository owner path is account-derived. All installer, overlay, app, and
package identifiers are generic.

## Current Functionality

- Publishes a Windows MSI that installs the `LibreNMSWindowsAgent` service.
- Installs a generic LibreNMS server-side overlay for Windows Agent visibility.
- Expects Windows agents to emit `windows_agent` and `windows_agent_*` sections
  through LibreNMS `unix-agent`.
- Adds the LibreNMS application type `windows-agent` with UI label
  `Windows Agent`.
- Installs graph/page/parser files from the overlay package.
- Installs a reapply command and systemd timer so the overlay can be restored
  after LibreNMS updates.
- Verifies the downloaded package with `SHA256SUMS` before installation.

The Windows MSI supports silent `msiexec` properties for listener address,
listener port, firewall rule, service start, config path, and config
preservation.

## Validation Baseline

Smallest useful local validation:

```powershell
bash -n ./install.sh
powershell.exe -NoProfile -Command "[void][scriptblock]::Create((Get-Content -Raw .\install-agent.ps1))"
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.3.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.3.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-0.6.3.msi
```

Before publishing, also scan public content for credentials, private
environment details, and legacy site-specific branding. When PHP is installed,
extract the overlay package and run `php -l` over all packaged PHP files.

## Relationship To The Development Project

The private homelab development project remains the development and validation
source for new Windows Agent visibility features. This repository is the
public, generic distribution product.

Recommended durable link:

1. Develop and validate new overlay behavior in the private development project.
2. Run `scripts/promote-from-dev-overlay.ps1` from this installer repo.
3. Record the upstream source commit, package version, checksum, validation, and
   compatibility notes in `docs/upstream-sync.md`.
4. Commit locally in this repository.
5. Push the verified public-safe snapshot to GitHub automatically as part of
   the installer repo workflow.

Long term, the more reliable design is to make the generic overlay source live
in this public repository and have the private development project consume or
test that generic source. That avoids repeated text conversion from a
site-specific overlay into a generic product.
