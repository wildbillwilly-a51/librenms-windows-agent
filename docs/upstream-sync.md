# Upstream Sync Model

This document defines the durable relationship between the private development
project and this public installer product.

## Roles

- Private development project: feature development, lab rollout, parser testing,
  and validation against real Windows and LibreNMS behavior.
- Public installer repo: generic release packaging, one-command install path,
  public documentation, checksum manifest, and distribution through GitHub.

The public repo should receive only sanitized generic artifacts.

## Current Sync Method

The current interim model is scripted promotion from the private development
project. Run the promotion from this public installer repository:

```powershell
.\scripts\promote-from-dev-overlay.ps1
```

The script builds the private development overlay package and generic Windows
MSI into temporary directories, converts the overlay package to generic
identifiers, updates the public package/checksum/docs, validates the generated
artifacts, creates a local commit, pushes `main` to GitHub, and verifies the
raw installer/package URLs.

The manual review boundary is the promotion into this installer repository. A
successful installer repo commit is authorization to sync the public GitHub
mirror unless the user explicitly runs with `-NoPush`.

The public conversion targets are:

- section prefix: `windows_agent`
- section family: `windows_agent_*`
- LibreNMS app type: `windows-agent`
- UI name: `Windows Agent`
- overlay package: `librenms-windows-agent-overlay-0.6.0.tar.gz`
- Windows MSI: `librenms-windows-agent-0.6.0.msi`
- Windows service name: `LibreNMSWindowsAgent`

When syncing a new version, record:

- upstream source commit or tag
- public package version
- overlay and MSI SHA256 values
- validation performed in the development project
- local validation performed in this repo
- known compatibility requirements for Windows agent output

## Long-Term Migration Roadmap

The most reliable long-term approach is to invert the current flow:

1. Store the generic overlay source in this public repository.
2. Treat this repo as the product source for `windows_agent_*` and
   `windows-agent`.
3. Let the private development project consume, package, or test the generic
   overlay source during lab validation.
4. Add any lab-specific behavior only as private deployment configuration, not
   as product source.

This avoids recurring risk from text-converting a site-specific overlay into a
generic product. It also makes public releases reproducible from public source.

Recommended migration phases:

1. Add generic overlay source directories to this public repo while preserving
   the current packaged installer.
2. Make the public package builder produce
   `librenms-windows-agent-overlay-<version>.tar.gz` directly from that source.
3. Update the private development project to consume this public generic source
   for lab validation.
4. Keep private deployment details in the development project only.
5. Retire the conversion step once lab validation uses the public generic
   source.

## Acceptable Interim Model

Until the generic overlay source is moved here, use an explicit promotion step:

1. Build and validate the private development overlay and Windows agent.
2. Run `scripts/promote-from-dev-overlay.ps1` from this repo.
3. The script validates, commits locally, pushes to GitHub, and checks raw URLs.

Do not automate a blind copy from the private project into this public repo.
Every sync must include a public-safety scan.

## Promotion Record

### 0.6.0

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.0.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.0.msi`
- Upstream development commit: `bc23c4b`
- Overlay SHA256:
  `f83a6a59656681582d4f980ff8d0a4c41fd26f26441c633881701656980fceb2`
- Windows MSI SHA256:
  `60a0a2fcce8d130cf34e0a6cabdd544e7a69a7156ddd2b17751946a25bfe3d6c`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.
