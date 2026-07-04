# Current State

This is the read-first handoff for the generic LibreNMS Windows Agent Installer
project.

## Project Boundary

- Local project root: the `librenms-windows-agent-installer` folder on the
  maintainer workstation.
- Public GitHub distribution mirror:
  `https://github.com/wildbillwilly-a51/librenms-windows-agent-installer`
- Installer entry point: `install.sh`
- Published overlay package:
  `artifacts/librenms-windows-agent-overlay-0.6.0.tar.gz`
- Package checksum manifest: `SHA256SUMS`
- Project rules: `AGENTS.md`
- Work history: `docs/work-log.md`

The local Git repository is the primary project record. GitHub is the public
distribution mirror for sanitized installer content.

## Current Release

- Current version: `0.6.0`
- Public install command:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh | sudo bash
```

The repository owner path is account-derived. All installer, overlay, app, and
package identifiers are generic.

## Current Functionality

- Installs a generic LibreNMS server-side overlay for Windows Agent visibility.
- Expects Windows agents to emit `windows_agent` and `windows_agent_*` sections
  through LibreNMS `unix-agent`.
- Adds the LibreNMS application type `windows-agent` with UI label
  `Windows Agent`.
- Installs graph/page/parser files from the overlay package.
- Installs a reapply command and systemd timer so the overlay can be restored
  after LibreNMS updates.
- Verifies the downloaded package with `SHA256SUMS` before installation.

This repo does not install the Windows-side agent.

## Validation Baseline

Smallest useful local validation:

```powershell
bash -n ./install.sh
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.0.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.0.tar.gz
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
4. Commit locally first in this repository.
5. Push the verified public-safe snapshot to GitHub.

Long term, the more reliable design is to make the generic overlay source live
in this public repository and have the private development project consume or
test that generic source. That avoids repeated text conversion from a
site-specific overlay into a generic product.
