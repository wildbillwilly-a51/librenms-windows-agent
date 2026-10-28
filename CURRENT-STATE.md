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

These are the public installer defaults. Overlay `0.6.33` is an overlay-only
release and preserves Windows agent `0.6.17` byte-for-byte. Previously published
agent and overlay payloads are immutable; the next change ships as a new version.

- Overlay version: `0.6.33`
- Windows agent version: `0.6.17`
- Overlay: `artifacts/librenms-windows-agent-overlay-0.6.33.tar.gz`
- Windows MSI: `artifacts/librenms-windows-agent-0.6.17.msi`
- Versioned agent config: `artifacts/librenms-windows-agent-config-0.6.17-win.json`
- Checksums: `SHA256SUMS`
- Overlay SHA256: `d7b1fe2da0eedc90abb6ba4bfd4f97a5c47e5927ff7d300936f69d929d1e65a1`
- Windows MSI SHA256: `8e588074498671a3772bb8ca5520f0734cba23d430b66eb04458038a411a96c9`
- Versioned config SHA256: `94fd8b56e0ac2ca15f50dd0ffff1d3f9167032b4717aeecd5b091f336fbe404b`
- Public overlay installer: `install.sh`
- Public Windows installer: `install-agent.ps1`

Windows agent release `0.6.17` makes the SQL Server collector accurate and adds
credential-free depth. Per-instance TCP ports come from the registry instead of a
1433/1434 guess; engine services map to their process IDs, with per-user LocalDB
kept apart from unowned engines; non-engine components get their own section;
SQL Agent, component and Browser verdicts are expectedness-aware; and server-level
performance counters are read through PDH, with SQL Agent counters read only while
the Agent runs. It also fixes `install-agent.ps1` on Windows PowerShell 4.0, which
had failed on stock Windows Server 2012 R2 since `0.6.15`. The bootstrap verifies
a matching versioned config, checks prerequisites and port ownership, prepares
configuration before service startup, leaves registered upgrades inside MSI
rollback, retains verbose diagnostics, and verifies a live protocol response.
Before publication, `0.6.17` was canary-installed through `install-agent.ps1` on a
multi-instance SQL Server 2017 host running Windows Server 2012 R2.
Overlay release `0.6.33` preserves native graph-row HTML and lazy loading, fixing
SQL tooltip quotes that disrupted tab layout in `0.6.32`. It supersedes that
overlay release; upgrade nodes still on it. Agent `0.6.17` is unchanged.

Overlay release `0.6.32` adds an automatically detected SQL Server tab, native
instance/issue filters, expandable evidence and separate-unit trends over existing
RRDs. Shared evaluation emits additive qualified metrics with absolute expiry,
explicit unknown/reset handling and independently qualified sampled jobs. The
locked final save merges current sibling data and preserves central Horizon
metrics. Existing RRD schemas and the agent release are unchanged; no rules are
enabled. See `docs/sql-qualified-metrics.md` for validity and alert limitations.

Overlay release `0.6.31` adds SQL component/performance parsing, per-instance
application metrics, two new SQL RRD graph families and richer SQL inventory
and attention rows. Unreadable and first-sample job deltas remain `-1`, distinct
from measured zero. Counter totals include only readable instances, and job
counts are collection deltas rather than durable event history. Older agents
remain compatible; the new counters require the matching agent sections. The
original SQL RRD schema is unchanged, and no alert rules are enabled.

Overlay release `0.6.30` adds per-pool Horizon availability metrics. Each clone pool's
availability numbers are flattened into `application_metrics` as
`horizon_pool_<measure>:<key>` (key = pool name sanitized to `[A-Za-z0-9_-]`), so
LibreNMS alert rules — which compare a single metric to a constant — can target an
individual pool's ready count, ready percent, or collector severity. Seven measures per
pool (`ready`, `ready_percent`, `machines_total`, `state`, `maintenance`, `spare_total`,
`unready`); a `horizon_pool_availability_metrics` capability advertises support.
Additive to application metrics only, non-alerting by default, no RRD/protocol/identity
change. Overlay release `0.6.29` makes the central Horizon pod view visible on every pod
member, not just the collector's display device. The central collector now fans the
same pod-wide snapshot (status, pools, members, gateways, conditions) out to every
Connection Server in the pod that runs the agent, so an operator can open any member
and see identical pod data with local host evidence alongside it. Each member's own
poll preserves the fanned-out data because the collector stamps the central snapshot
source, which the parser keys on; data and metrics are both written so member graphs
populate too. Fan-out is on by default and can be disabled per pod with
`publish_to_members: false`. The Horizon tab now also names the Connection Server that
answered the API ("Collected … • via …"). No RRD schema, protocol, or application
identity change. Overlay release `0.6.28` makes Horizon condition rows state the real reason.
Connection Server, standalone gateway, and directory conditions no longer ship a
hardcoded sentence: each now carries evidence built from the affected object's own
fields, so an invalid Connection Server certificate reads
`active certificate invalid · Horizon status=OK · role=… · v…` instead of the
generic "health or redundancy is degraded." The Connection Server drawer renders an
invalid certificate in red with a certificate-specific next action, and the reason
labels for the member and gateway condition codes are now curated rather than
auto-title-cased. No RRD schema, protocol, or application identity change.
Overlay release `0.6.27` refines pool severity and fixes a display bug. Pool conditions now show the pool's name instead of its internal id. Pool severity is capacity-first: fewer than the minimum ready spares (default two) with faulted or stuck machines present is critical; fewer than the minimum with nothing broken is a warning; more than one machine unavailable is a warning even when spare capacity remains. Overlay release `0.6.26` brings the application page onto one uniform contract and completes the Horizon machine severity model. Every tab now leads with the same summary header — state, a plain-language verdict, up to six decision-grade tiles, and an attention list — above its detailed evidence. The Horizon machine inventory uses a three-colour severity model: red for genuinely down machines (agent unreachable, errors, already-used), yellow for machines intentionally out of service (maintenance, disabled), and unflagged for healthy machines (available, connected, and reconnectable disconnected sessions). A disconnected session is treated by age: a recent disconnect counts as in-session and is reconnectable, while a session disconnected past its reclaim window is stuck — unavailable to other users, reported red, with a message that the user has not reconnected in the elapsed time. The stuck threshold is each pool's own logoff-after-disconnect timer, read automatically from the bulk pool payload, capped by an adjustable global fallback so a Never policy still surfaces and no per-pool configuration is required. The machine filter reads All, In session, Available, and Unavailable. `0.6.25` made the machine inventory list group each row by the collector's placement decision so the list and its counters agree.

`0.6.24` recorded connected versus disconnected per session so only an active
session counts a machine as in use. `0.6.23` introduced the occupied class and
closed a gap where a machine in an occupying state with no session row was dropped
from both the in-use and the spare totals. Pool totals reconcile in both
directions and the tests assert it. Across `0.6.22` through `0.6.26`, a
disconnected machine has moved from being reported as a warning and counted as a
problem machine, through being reported as healthy, to being reported as
unavailable and not a fault, in both the pool counts and the machine list.

One state taxonomy decides, independently, whether a machine is placement
capacity, how severe its own state is, and whether it counts as a problem
machine, so a row can no longer disagree with the aggregate counts. Full
utilisation is reported as capacity exhaustion rather than a fault, faulted
capacity keeps its own reason code, spares that are intentionally withheld or
still becoming ready no longer score as failures, and an unrecognized state is
reported as incomplete instead of manufacturing a warning. The published state
distribution carries the classification for each reported state so a mishandled
state is visible on the page. `0.6.21` gave each detected first-order
role its own application-page tab: `Active Directory`, `FactoryTalk`, and
`Horizon`, each shown only while that role is detected and placed ahead of
`Overview`, and restored the Horizon trend graphs that had become unreachable.
Horizon credentials remain only on the collector node, and local Windows
telemetry stays independent. Both release artifacts and their public installers
are published on GitHub `main`; the raw public bytes match `SHA256SUMS`.

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
.\scripts\build-release.ps1 -Version 0.6.17 -AgentOnly -OverlayVersion 0.6.33 -UpdateChecksums
```

Before publishing, review the full committed snapshot for secrets, private
environment facts, machine-user paths, and legacy branding. When PHP is
available, run PHP lint and all four overlay fixture suites.

## Current Validation Environment

The workstation has the required .NET SDK and successfully builds the service,
portable tests, WiX MSI, and overlay package. WSL provides PHP 8.3 for complete
source and packaged PHP lint plus parser, app-page, and centralized-collector
fixtures.

## Next Recommended Action

Windows agent `0.6.17` and overlay `0.6.33` are the installer defaults. Agent
`0.6.17` is running on its canary host only; every other Windows host stays on its
previous agent until an operator runs `install-agent.ps1` there. Roll the agent
out at the operator's pace. Hosts still on `0.6.16` or older keep working with
overlay `0.6.32` but report no SQL components, counters or LocalDB separation.

Publishing changes no deployed node: the reapply timer uses its locally staged
copy and downloads nothing. Deploy `0.6.33` to the management/web node and every
node that polls Windows-agent devices only when the operator authorizes rollout.
After rollout, confirm SQL tab filters, disclosures/deep links, `sql_q_*` metrics,
`sql_q_expires` and the existing SQL graphs on a device reporting SQL sections.
Other devices remain compatible
but cannot populate counters they do not collect. Keep new visibility
non-alerting unless alert rules are separately approved.

Use the installed rollback script for a local rollback. Historical bootstrap
versions also need the matching checksum manifest from their release commit;
the current `SHA256SUMS` lists only the current release payloads.
