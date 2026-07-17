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

## Current Release

- Version: `0.6.14`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.14.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.14.msi`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `151b8389fada2f833d2374e844af83497c75cb44dd7aeefbe15f70b632af08c8`
- Windows MSI SHA256: `e2dc68edd5b0aaa1f21828e8292d37b7412dcb0353dcf11db0f458859d759b89`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Release `0.6.14` retains the complete bounded FactoryTalk collection set and
adds local Horizon process telemetry plus the disabled-by-default read-only
Horizon API integration. The Horizon overlay separates Windows/Microsoft AD,
Horizon configuration replication (AD LDS), and Horizon domain access, and
adds pod, gateway, session, and instant/linked-clone pool health. Existing
installations remain rollback-safe and setup requires the service to reach
`Running` before it succeeds.

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

## Current Validation Limitation

The workstation has the required .NET SDK and successfully builds the service,
portable tests, WiX MSI, and overlay package. The test executable uses supported
major-version runtime roll-forward because .NET 8 is not installed locally.
PHP is not installed in Windows or WSL, so overlay PHP lint and fixture runners
must be run on a PHP-capable environment before or during overlay deployment.

## Next Recommended Action

Install the 0.6.14 overlay on a LibreNMS test node and applicable test poller,
then upgrade an authorized non-production Horizon Connection Server with the
0.6.14 MSI. Provision a dedicated read-only Horizon credential, verify pod and
pool API contracts, and observe at least two polls before enabling alerts.
