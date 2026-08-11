# Current State

This is the read-first handoff for the universal LibreNMS Windows Agent
project.

The canonical GitHub repository and local checkout are both named
`librenms-windows-agent`. The prior development repository is retained locally
as `librenms-windows-agent-legacy` only for historical recovery.

## Project Boundary

This repository is now the canonical development and public distribution
source for:

- the Windows agent core and service under `src/`;
- portable agent tests and LibreNMS fixtures under `tests/`;
- WiX MSI source under `installer/`;
- native generic LibreNMS overlay source under `librenms-overlay/`;
- public install, build, release, checksum, and rollback workflows.

Private sibling projects are not upstream sources for universal features. They
may consume public builds for environment-specific deployment validation, but
their hostnames, IPs, credentials, device IDs, deployment scripts, branding,
and private exports do not belong here.

## Current Releases

- Overlay version: `0.6.21`
- Windows agent version: `0.6.16`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.21.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.16.msi`
- Versioned agent config: `artifacts/librenms-windows-agent-config-0.6.16-win.json`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `98750562af7ebe68519b6ee722c3442e666652de5f2e3d4a64cbfd7c70c6671b`
- Windows MSI SHA256: `5a40c9965a44179b09c57e4e3951e55982b983bfd1fd83b4e93cbeaaf5811732`
- Versioned config SHA256: `94fd8b56e0ac2ca15f50dd0ffff1d3f9167032b4717aeecd5b091f336fbe404b`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Windows agent release `0.6.16` keeps the repaired install and upgrade path and
adds explicit Horizon service expectedness plus active-certificate health. The bootstrap verifies a
matching versioned config, checks prerequisites and port ownership, prepares
configuration before service startup, leaves registered upgrades inside MSI
rollback, retains verbose diagnostics, and verifies a live protocol response.
Overlay release `0.6.21` keeps the scope-separated Horizon health, explicit
redundancy and component semantics, retained condition history, and fail-soft
vendor metrics introduced in `0.6.20`, and gives each detected first-order role
its own application-page tab: `Active Directory`, `FactoryTalk`, and `Horizon`,
each shown only while that role is detected and placed ahead of `Overview`. It
also restores the Horizon trend graphs that had become unreachable, and reports
tab-level issues against the tab that actually owns the evidence. Horizon
credentials remain only on the collector node, and local Windows telemetry stays
independent. Both release artifacts and their public installers are published on
GitHub `main`; the raw public bytes match `SHA256SUMS`.

## Product Contract

- Windows service: `LibreNMSWindowsAgent`
- Listener: Checkmk-compatible TCP on port `6556`
- Protocol sections: `windows_agent` and `windows_agent_*`
- LibreNMS application type: `windows-agent`
- Default collector count: `23`
- Supported MSI upgrade identity remains unchanged.

New visibility is non-alerting by default unless explicitly approved. Preserve
existing section names and RRD schemas; add graph families for new metric
shapes.

## Development Workflow

Run the smallest relevant validation first:

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
bash -n ./install.sh
.\scripts\build-overlay-package.ps1 -ArtifactsDir <temporary-output-directory>
.\scripts\build-msi.ps1 -ArtifactsDir <temporary-output-directory>
```

For an intentional release:

```powershell
.\scripts\build-release.ps1 -UpdateChecksums
```

For an agent-only release that preserves the current overlay:

```powershell
.\scripts\build-release.ps1 -Version 0.6.16 -AgentOnly -OverlayVersion 0.6.21 -UpdateChecksums
```

Before publishing, review the full committed snapshot for secrets, private
environment facts, machine-user paths, and legacy branding. When PHP is
available, run PHP lint and both overlay fixture runners.

## Current Validation Environment

The workstation has the required .NET SDK and successfully builds the service,
portable tests, WiX MSI, and overlay package. WSL provides PHP 8.3 for complete
source and packaged PHP lint plus parser, app-page, and centralized-collector
fixtures.

## Next Recommended Action

Overlay `0.6.21` is published and is the installer default. Rollout timing
belongs to the operator: publishing changes no deployed node, because the
overlay reapply timer re-applies the locally staged copy and performs no
download.

Apply `0.6.21` to overlay nodes when convenient and confirm the application page
renders a role tab for each detected first-order role, that an undetected role
shows no tab, and that the restored Horizon trend graphs draw. Windows agent
`0.6.16` requires no reinstall for this overlay release; bring any agent still
below `0.6.16` up to the current version so the field does not stay on mixed
agent versions.
