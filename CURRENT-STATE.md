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

- Overlay version: `0.6.19`
- Windows agent version: `0.6.15`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.19.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.15.msi`
- Versioned agent config: `artifacts/librenms-windows-agent-config-0.6.15.json`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `b1ebee731985199c0c6661b536a76aa5516b952d351d2b4d83be8977df93644b`
- Windows MSI SHA256: `80cd00920000c108d0bbe7a73b96289aca40e07231dda88ecd90312e4d622b20`
- Versioned config SHA256: `79f37b860b2aab30a373fecc7af604b48f0a6d8e416d7057f184544da0816294`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Windows agent release `0.6.15` retains the 0.6.14 collector behavior while
repairing the complete install and upgrade path. The bootstrap verifies a
matching versioned config, checks prerequisites and port ownership, prepares
configuration before service startup, leaves registered upgrades inside MSI
rollback, retains verbose diagnostics, and verifies a live protocol response.
Overlay release `0.6.19` provides the current Horizon operations UI and central
collector. Horizon credentials remain only on the collector node, and local
Windows telemetry stays independent.

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
.\scripts\build-release.ps1 -Version 0.6.15 -AgentOnly -OverlayVersion 0.6.19 -UpdateChecksums
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

Deployment is intentionally waiting at an explicit approval checkpoint. After
publication, install Windows agent 0.6.15 first on the server that exposed the
0.6.14 failure and confirm the retained verbose log, running service, local
payload, firewall result, and LibreNMS reachability before any wider rollout.
The Horizon health-classification implementation remains the next product
objective after installer rollout is accepted.
