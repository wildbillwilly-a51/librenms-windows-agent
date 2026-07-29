# Project Summary

## Purpose

<!-- Stable description of what the project exists to do and who or what uses it. -->

The LibreNMS Windows Agent is a universal, credential-free Windows monitoring
agent plus a matching LibreNMS server-side overlay. The agent runs as a Windows
service and serves a read-only, Checkmk-compatible telemetry payload that
LibreNMS polls to determine whether Windows server roles and functions are
healthy. The overlay renders that telemetry as a LibreNMS application page and
provides an opt-in central collector for authenticated VMware/Omnissa Horizon
pod inventory.

This repository is the canonical development source and the sanitized public
distribution mirror. It stays generic: no lab-specific hostnames, IP addresses,
device IDs, credentials, or environment-specific LibreNMS details belong here.
Site-specific deployment lives in a separate private project.

## Status

<!-- Current maturity, lifecycle, or operational status. Keep task details elsewhere. -->

Actively maintained and released. The current published Windows agent, overlay,
versioned config, checksums, and public installers are recorded in
`CURRENT-STATE.md` (the version source of truth) and are synchronized to
`origin/main`. Release artifacts are validated and public-safe. Live deployment
to a Horizon environment is gated behind explicit user authorization and is
tracked in `docs/codex-handoff.md`, not here.

## Architecture

<!-- Major components and how responsibilities are divided. -->

Three .NET projects plus a PHP overlay:

- `src/LibreNMS.WindowsAgent.Core` — protocol, config models, Checkmk
  rendering, collector runner, allowlist matching, and the read-only TCP
  server.
- `src/LibreNMS.WindowsAgent.Service` — Windows service entry point, config
  loading, logging, support bundle, and the Windows-specific collectors under
  `Collectors/`.
- `tests/LibreNMS.WindowsAgent.Tests` — dependency-free test runner for core
  behavior.
- `librenms-overlay/` — generic LibreNMS overlay: PHP app-page, polling, and
  the central Horizon pod collector (the only credentialed component, which
  runs on one opted-in LibreNMS node — the Windows agent itself stays
  credential-free).

The service has a small stable core (collect structured sections, isolate
collector failures/timeouts, render Checkmk plaintext, serve one payload per
connection). New functionality is added as a collector implementing
`IAgentCollector`, not by changing the listener or renderer. See
`docs/architecture.md` for the full collector contract and section catalog.

## Entry Points And Key Paths

<!-- Primary commands, executables, modules, configuration, and important directories. -->

- Windows service: `LibreNMSWindowsAgent`, listening on TCP `6556`.
- Public installers: `install-agent.ps1` (Windows) and `install.sh` (overlay).
- Release artifacts: `artifacts/` (MSI, overlay tarball, versioned config),
  pinned by `SHA256SUMS`.
- MSI source: `installer/` (WiX x64).
- Build/maintenance scripts: `scripts/` (`build-release.ps1`,
  `build-msi.ps1`, `build-overlay-package.ps1`, `github-sync.ps1`, etc.).
- Docs: `README.md`, `CURRENT-STATE.md`, `docs/architecture.md`,
  `docs/collector-roadmap.md`, `docs/horizon-monitoring.md`,
  `docs/release-runbook.md`, `docs/codex-handoff.md`.

## Interfaces And Dependencies

<!-- External interfaces, runtimes, services, libraries, and system dependencies. -->

- Protocol: Checkmk-compatible plaintext over TCP `6556`; sections are
  `windows_agent` and `windows_agent_*`; LibreNMS application type is
  `windows-agent`.
- Runtime: .NET Framework 4.6.2 (so Windows Server 2016/2019/2022 hosts run it
  without a .NET 4.8 prerequisite; Server 2012 R2 in scope with 4.6.2+).
- Collectors prefer read-only CIM/WMI, registry, service-control, and Windows
  APIs.
- Overlay runs inside a LibreNMS install (PHP). The central Horizon collector
  uses shared Redis for non-secret coordination only and adds no database
  schema or external secret service.
- Distribution: GitHub raw URLs under the repo owner path (see `install.sh` /
  `install-agent.ps1`); `gh` CLI is used by the sync scripts.

## Commands

<!-- Standard build, test, validation, and run commands. -->

Smallest relevant validation first:

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
bash -n ./install.sh
.\scripts\build-overlay-package.ps1 -ArtifactsDir <temporary-output-directory>
.\scripts\build-msi.ps1 -ArtifactsDir <temporary-output-directory>
```

Release (intentional): `.\scripts\build-release.ps1 -UpdateChecksums`.
Agent-only release preserving the overlay:
`.\scripts\build-release.ps1 -Version <v> -AgentOnly -OverlayVersion <v> -UpdateChecksums`.

When PHP is available, also run the overlay fixture runners under
`tests/librenms-overlay/` and lint packaged PHP. Always scan the full committed
snapshot for secrets and private facts before publishing.

## Constraints And Conventions

<!-- Durable technical constraints, safety boundaries, and project conventions. -->

- Keep the repo generic — no site-specific hostnames, IPs, device IDs,
  credentials, keys, tokens, or private LibreNMS details.
- Preserve the public one-command installer contract and the stable
  `windows_agent` / `windows_agent_*` protocol and `windows-agent` application
  identity unless the user approves a breaking change.
- Add new RRD graph families instead of changing existing RRD schemas.
- New visibility is non-alerting by default unless alerts are explicitly
  approved.
- The Windows agent stays credential-free; authenticated Horizon inventory is
  an overlay-side function on one opted-in node.
- `.context/` is generated private continuity state — leave it untracked and
  never publish it.
- Authorization is required before deployment, credential/auth changes,
  destructive operations, or expansion beyond the stated objective.
- Full project rules and work-tracking/commit-push policy are in `AGENTS.md`
  (imported by `CLAUDE.md`).

## Current Work

Current objective state is maintained in `docs/codex-handoff.md`.
