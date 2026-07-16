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

- Version: `0.6.13`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.13.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.13.msi`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `e948079cd045fc08bd3d6b6bdef6434e93832bc1393218ca4d6150ca9e7768ab`
- Windows MSI SHA256: `f762c5c137261a8b6d47a4bb5fc167379e8623f51be3514938ecd2c3e302c66e`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Release `0.6.13` enables the complete bounded FactoryTalk collection set for
normal MSI installs and upgrades, including localhost-only Diagnostics Counter
Monitor snapshots. `ENABLE_FACTORYTALK_NATIVE_COUNTERS=0` provides an explicit
MSI opt-out.

## Product Contract

- Windows service: `LibreNMSWindowsAgent`
- Listener: Checkmk-compatible TCP on port `6556`
- Protocol sections: `windows_agent` and `windows_agent_*`
- LibreNMS application type: `windows-agent`
- Default collector count: `22`
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

Install the 0.6.13 overlay on the LibreNMS management node and every applicable
poller, then upgrade an authorized non-production FactoryTalk host to the 0.6.13
MSI. Verify Windows-native runtime sections and observe at least two
fifteen-minute Counter Monitor snapshot cycles.
