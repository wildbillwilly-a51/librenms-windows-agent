# Current State

This is the read-first handoff for the universal LibreNMS Windows Agent
project.

The canonical GitHub repository is `librenms-windows-agent`. This checkout
still uses the prior `librenms-windows-agent-installer` directory name only
because the active Codex workspace holds that directory open. The prior A51
development repository is retained locally as `librenms-windows-agent-legacy`
only until consolidation is fully verified.

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

- Version: `0.6.11`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.11.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.11.msi`
- Checksums: `SHA256SUMS`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

The source migration preserves the current `0.6.11` public artifacts and their
checksums. The next functional release should be built natively from this
repository with a new version.

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

The source migration workstation currently has a .NET runtime but no .NET SDK,
so C# compilation, console tests, and WiX MSI rebuilding require an SDK-enabled
environment. Overlay packaging, shell syntax, PowerShell parsing, tar listing,
checksum validation, and source safety scans remain locally available.

The GitHub repository-name swap is complete, the canonical `origin` points to
`wildbillwilly-a51/librenms-windows-agent`, and the verified local commits are
published. The remaining local directory rename requires releasing the active
Codex workspace's Windows file handle.

## Next Recommended Action

Close or switch away from the active Codex workspace, rename the local
`librenms-windows-agent-installer` directory to `librenms-windows-agent`, and
reopen the project from that path. Then install or use an approved .NET SDK
environment, run the migrated agent tests and native MSI build, and begin the
next universal collector or overlay work directly in this repository.
