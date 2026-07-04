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

The script builds the private development overlay package into a temporary
directory, converts the package to generic identifiers, updates the public
package/checksum/docs, validates the generated artifact, creates a local
commit, pushes `main` to GitHub, and verifies the raw installer/package URLs.

The manual review boundary is the promotion into this installer repository. A
successful installer repo commit is authorization to sync the public GitHub
mirror unless the user explicitly runs with `-NoPush`.

The public conversion targets are:

- section prefix: `windows_agent`
- section family: `windows_agent_*`
- LibreNMS app type: `windows-agent`
- UI name: `Windows Agent`
- overlay package: `librenms-windows-agent-overlay-0.6.0.tar.gz`

When syncing a new version, record:

- upstream source commit or tag
- public package version
- package SHA256
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

1. Build and validate the private development overlay.
2. Run `scripts/promote-from-dev-overlay.ps1` from this repo.
3. The script validates, commits locally, pushes to GitHub, and checks raw URLs.

Do not automate a blind copy from the private project into this public repo.
Every sync must include a public-safety scan.

## Promotion Record

### 0.6.0

- Public package: `artifacts/librenms-windows-agent-overlay-0.6.0.tar.gz`
- SHA256:
  `604d947221ff7dec81a8b57f166aa73f87073e9cd76791015b79370e9c262454`
- Compatibility: requires Windows agent output using `windows_agent` and
  `windows_agent_*` section names.
- Local validation: shell syntax check, tarball listing, checksum generation,
  raw GitHub URL checks, and public-safety scans.
- PHP lint: skipped locally because PHP was not installed.
