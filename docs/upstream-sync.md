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
- overlay package: `librenms-windows-agent-overlay-0.6.3.tar.gz`
- Windows MSI: `librenms-windows-agent-0.6.3.msi`
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
- Upstream development commit: `7338e23`
- Overlay SHA256:
  `29d9149b16764b15d7d97f97661d2b75eaa3af4720bae4df3b016a29e6355a4e`
- Windows MSI SHA256:
  `eb4a0372106be8e27d91393a8783e9e2a6f1b48d3f49757669542a52babc58ce`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.

### 0.6.1

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.1.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.1.msi`
- Upstream development commit: `b97c5c5`
- Overlay SHA256:
  `c060f5bd155b3782b512ced1ac617b84a299ea25f261cf55ac0c0b0eabc4a173`
- Windows MSI SHA256:
  `0e048d6640b791db904f68fc2c85027687e0d9a48b255295e8a760acdb5ce896`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.

### 0.6.2

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.2.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.2.msi`
- Upstream development commit: `db0126b`
- Overlay SHA256:
  `b5418bb1863316bedde423cb3a0c4e43fecf5e28ea3b71eb35cf3ec6c521d212`
- Windows MSI SHA256:
  `60858d312631ecc4206d8a02dc0ce986eff18d5022238c4d11abc7727f134b47`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.

### 0.6.3

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.3.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.3.msi`
- Upstream development commit: `6d595f2`
- Overlay SHA256:
  `d78bb063ecc6b18900dfb37f42c62074b1d96cd65389e505a5df34d0ce36930a`
- Windows MSI SHA256:
  `05bbf6851568da4bc72096bd4c65c719093c1652a20cff3bf9095aa869124d33`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.

### 0.6.4

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.4.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.4.msi`
- Upstream development commit: `c9e48c3`
- Overlay SHA256:
  `92b04928d69ab3bec8f5f89e5c4cfbe0fca11e6453456dde9e80ec7262c1ac67`
- Windows MSI SHA256:
  `1a515ccaa735c0eede0eeca6dff64891b498df90fdcffdb6d95f87a08f7bfbfb`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.

### 0.6.5

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.5.tar.gz`
- Public Windows MSI: `artifacts/librenms-windows-agent-0.6.5.msi`
- Upstream development commit: `17acd26`
- Overlay SHA256:
  `2d1f8417e4887e5258cb7e9f4e1ac7f33aa1f7c5909e8505cda5e87072e66f9a`
- Windows MSI SHA256:
  `22ba5d2f727056124389369892332d6e411a0c50d97222c1ead485d3aaa6043a`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Promotion method: `scripts/promote-from-dev-overlay.ps1` built the
  development overlay package and public MSI into temporary directories,
  converted overlay files to generic public identifiers, regenerated checksums,
  and scanned generated public payloads for legacy/site-specific branding.
- PHP lint: run when PHP is available on the promotion workstation.
