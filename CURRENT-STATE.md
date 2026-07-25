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

- Overlay version: `0.6.15`
- Windows agent version: `0.6.14`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.15.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.14.msi`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `295949ee3e3b19a062837d928b9658fbafb05c429fc9e6a6a5b884f20b9cf074`
- Windows MSI SHA256: `e2dc68edd5b0aaa1f21828e8292d37b7412dcb0353dcf11db0f458859d759b89`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Windows agent release `0.6.14` retains the complete bounded FactoryTalk
collection set and adds local Horizon process telemetry plus the
disabled-by-default read-only Horizon API integration. Overlay release `0.6.15`
adds the centralized Horizon pod collector, keeps API credentials off Windows
hosts, and preserves local agent telemetry independently. Existing
installations remain rollback-safe, and Windows setup requires the service to
reach `Running` before it succeeds.

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

Before publishing, review the full committed snapshot for secrets, private
environment facts, machine-user paths, and legacy branding. When PHP is
available, run PHP lint and both overlay fixture runners.

## Current Validation Environment

The workstation has the required .NET SDK and successfully builds the service,
portable tests, WiX MSI, and overlay package. WSL provides PHP 8.3 for complete
source and packaged PHP lint plus parser, app-page, and centralized-collector
fixtures.

## Next Recommended Action

Install overlay 0.6.15 on the required LibreNMS nodes. On the designated active
node only, provision a dedicated read-only Horizon credential through the
protected prompt, validate one non-production pod manually, and verify
failover, polling, and LibreNMS presentation before enabling the managed
five-minute schedule. The Windows MSI remains at 0.6.14 and does not require an
upgrade for this overlay-only release.
