# LibreNMS Windows Agent

Canonical universal source and public distribution repository for the LibreNMS
Windows Agent and LibreNMS server overlay.

The Windows agent listens on TCP `6556` and emits Checkmk-style
`windows_agent_*` sections. The LibreNMS overlay teaches LibreNMS how to parse
those sections and show the `Windows Agent` application.

## Primary Runbook

Use this path for a normal installation or upgrade.

### 1. Confirm LibreNMS Device Discovery

The Windows machine should already exist in LibreNMS, normally through SNMP.
The overlay does not discover Windows hosts; it adds Windows Agent visibility
to existing LibreNMS devices.

Each LibreNMS poller that may own the Windows device must be able to reach the
Windows host on TCP `6556`.

### 2. Enable LibreNMS Poller Modules Globally

Enable the LibreNMS `Applications` and `Unix Agent` poller modules globally so
new Windows Agent devices do not need per-device module changes.

GUI path:

1. Open LibreNMS.
2. Go to `Global Settings` -> `Poller` -> `Poller Modules`.
3. Enable `Applications`.
4. Enable `Unix Agent`.

CLI path from the LibreNMS server:

```bash
cd /opt/librenms
sudo -u librenms ./lnms config:set poller_modules.applications true
sudo -u librenms ./lnms config:set poller_modules.unix-agent true
sudo -u librenms ./lnms config:get poller_modules.applications
sudo -u librenms ./lnms config:get poller_modules.unix-agent
```

### 3. Install The LibreNMS Overlay

Run this on the LibreNMS management/web node and on every distributed poller
node that may poll Windows Agent devices:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo bash
```

The overlay installs:

- `unix-agent` parser support for `windows_agent_*` sections.
- `windows-agent` application polling support.
- LibreNMS application page and graph definitions.
- A systemd reapply timer so the overlay can be restored after LibreNMS
  updates.

The FactoryTalk application view is issue-first. Its operational view shows a
health assessment, recommended next action, core service state, runtime CPU and
memory, Linx connections, transaction utilization, native snapshot state, and
the five busiest FactoryTalk processes. Complete product, service, process,
port, and native-counter rows remain available under `Inventory and raw
diagnostics`; additional graphs are also collapsed separately. These display
cues do not create LibreNMS alerts. Only the FactoryTalk collector's scored
health result and stopped core services are presented as issues. Optional
listeners, non-core services, runtime/native availability, and cumulative
counter values remain informational.

The Horizon application view is a problems-first operational workspace. It
leads with actionable conditions and pool capacity, defaults trends to 30
days, and keeps platform health, collector reliability, and complete
diagnostics available below. Every pool expands in place into a bounded,
filterable machine inventory; machine rows can be selected for focused state,
evidence, and next-action detail. Connection Server conditions open local
server detail instead of leaving the Horizon workspace.
Horizon configuration replication (AD LDS) and per-member Horizon domain
access remain separate from the Windows/Microsoft AD collector.

Central clone-pool policy separates capacity failure from capacity exhaustion,
because they are different operator problems. A single machine state taxonomy
decides, independently, whether a machine is available for a new session, how
severe its own state is, and whether it counts as a problem machine.

- Faulted spares drive severity: no ready spares while a spare is faulted is
  critical, two or more faulted spares is a warning, and one faulted spare while
  ready capacity remains is informational.
- Exhaustion is a warning, not a fault. A pool whose machines are all serving or
  holding sessions has no placement capacity, but nothing is broken.
- A disconnected session is unavailable, not available and not faulted. It holds
  its machine until logoff, so it is counted as occupied rather than as ready
  capacity or as a problem machine.
- Machines that are intentionally withheld, such as maintenance mode, or still
  becoming ready, such as provisioning or customizing, are informational and
  never score as failures on their own.
- A machine state the overlay does not recognize is reported as incomplete and
  excluded from capacity scoring. Not knowing a state is not evidence of a
  problem. The Horizon machine state inventory shows the capacity treatment,
  severity, and problem-machine flag for every reported state, so an unrecognized
  state is visible on the page.

This visibility does not enable LibreNMS notifications. Release 0.6.14's
Windows-side API prototype remains disabled by default. Overlay release 0.6.20
provides the cluster-safe, poll-triggered centralized collector and the new
operational UI. See
[Horizon monitoring design](docs/horizon-monitoring.md) for scope and setup.

### Central Horizon API Collector (Overlay 0.6.20)

Keep the Windows agent on every Horizon server for local
service/process/listener/certificate telemetry; do not place Horizon API
credentials on those servers or on distributed LibreNMS pollers. On exactly one
LibreNMS management node, the overlay helper stores one read-only service
credential in LibreNMS' protected `.env` and protected pod definitions in
`.horizon-pods.json`.

Every normal `windows-agent` application poll, including an explicit
`lnms device:poll ... --modules="unix-agent,applications"` run, performs a fast
non-secret Redis registration lookup. Only the configured display device can
enqueue its site. A management-node worker consumes those deduplicated hints,
uses a Redis-backed per-site lock and cooldown, and performs the API collection.
An independent five-minute systemd timer provides fallback collection when no
poll emits a trigger. Redis failure never fails a device poll.

The site code and DNS suffix derive the only bootstrap targets, in fixed order:
`abc-vcs1.example.test`, then `abc-vcs2.example.test`. Healthy Connection
Servers returned by the API become later failover candidates after suffix and
pod-identity validation. Gateways are displayed but never used as API targets.
The configured display device is only the LibreNMS page where pod data appears;
it is not the preferred API endpoint or a monitoring dependency.

Installing the overlay alone does not enable discovery, contact Horizon, start
the worker, or create the fallback timer. Setup on the management node:

```bash
cd /opt/librenms
sudo -u librenms php windows-agent-overlay/horizon-central-config.php credential set
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod discover \
  --dns-suffix example.test
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod discover \
  --dns-suffix example.test --apply
sudo -u librenms php windows-agent-overlay/horizon-central-config.php config validate
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod list
sudo -u librenms php windows-agent-overlay/horizon-central-config.php test network --site abc
```

Discovery is preview-only unless `--apply` is present. It groups existing
LibreNMS devices named `<site>-vcs<number>.<dns-suffix>`, requires an enabled
`windows-agent` application, performs strict DNS/TLS/authentication/pod-identity
validation through one seed, accepts API-discovered members, and proposes only
additive enrollment. Existing pod and display-device choices are preserved.
Ambiguous, unreachable, unauthorized, TLS-invalid, and waiting-for-agent sites
are explained and skipped.

The password is entered through a hidden prompt and is never accepted on the
command line. `test network` performs DNS and strict TLS checks without a
credential. Run the authenticated API test and enable the worker/fallback only
during an explicitly authorized setup window:

```bash
sudo -u librenms php windows-agent-overlay/horizon-central-config.php test api --site abc
sudo -u librenms php windows-agent-overlay/horizon-central-collector.php --site abc --force
sudo php /opt/librenms/windows-agent-overlay/horizon-central-config.php worker enable \
  --librenms-root /opt/librenms
```

Overlay upgrades do not replace `.env`, `.horizon-pods.json`, or the collector's
last-good state. The display device is only a stable LibreNMS application/data
anchor: its down state does not stop API collection, while deletion of the
device or application produces a bounded reassignment error. Central collection
owns the Horizon API/platform RRD writes, so pollers cannot create duplicate or
conflicting central samples. Failed refreshes retain the last good application
snapshot and write unknown RRD samples.

The central snapshot also records bounded unhealthy-service evidence, bounded
issue-machine detail with explicit truncation, collection duration,
endpoint/request/page counts, returned inventory counts, completeness, and
outcome. These metrics feed a new additive collector-health RRD family; no
existing RRD schema changes.

Health is published independently for platform, dependencies, capacity, and
collector reliability, plus an overall rollup. `horizon_pod_summary.state`
remains platform-only and the legacy `health_state` field aliases the overall
state. Disabled Connection Servers are excluded from redundancy; one failed
member while two healthy peers remain is a warning, while only one healthy
enabled member remains critical. CRL Prefetch is optional and appears as one
grouped informational observation when unavailable across the pod. Optional
vendor health/system metrics are fail-soft and never make an otherwise
complete snapshot partial. Current conditions retain bounded first/last-seen,
consecutive-sample, and recovery history.

Use `config status`, `pod list`, and `worker status` for sanitized state.
`capability show` prints the generic overlay/configuration/private-integration
contract without credentials or site-specific knowledge.

### 4. Install Or Update The Windows Agent

Run PowerShell as Administrator on each Windows host:

```powershell
iwr -UseBasicParsing https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install-agent.ps1 -OutFile $env:TEMP\install-agent.ps1
& $env:TEMP\install-agent.ps1 -Silent
```

Direct MSI link:

```text
https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/artifacts/librenms-windows-agent-0.6.16.msi
```

The default Windows install is normally enough. It installs the
`LibreNMSWindowsAgent` service, listens on `0.0.0.0:6556`, reconciles the
Windows firewall rules, starts the service, and preserves existing config on
upgrade.
On FactoryTalk hosts, the MSI also enables the complete bounded FactoryTalk
feature set, including localhost Counter Monitor snapshots every 15 minutes.
Release 0.6.16 upgrades prior packages inside the MSI rollback boundary, and
setup reports success only after the installed Windows service reaches
`Running` and returns a valid agent payload. The one-command installer checks
the .NET Framework prerequisite and listener port before changing the host,
prepares the final configuration before service startup, and retains a verbose
Windows Installer log under
`%TEMP%\librenms-windows-agent\install-agent-<version>-<timestamp>.log`.
The MSI has no agent PowerShell custom actions. Windows Installer installs the
default configuration, preserves an existing `agent.json`, starts the service,
and attempts program-scoped domain/private firewall rules for TCP 6556.
Firewall policy cannot roll back an otherwise valid installation; the wrapper
reconciles the rules afterward and reports whether they were configured.

`0.0.0.0` is the local bind address on the Windows host. It is not the LibreNMS
server or poller IP.

### 5. Enable The Windows Agent Application On The Device

LibreNMS also requires the `Windows Agent` application to be enabled on each
Windows device. This is separate from the global `Applications` and
`Unix Agent` poller modules.

GUI path:

1. Open the Windows device in LibreNMS.
2. Open the device config/settings page.
3. Go to the `Applications` tab.
4. Enable `Windows Agent`.
5. Save the device settings.

### 6. Poll And Verify

Force a first poll if desired:

```bash
cd /opt/librenms
sudo -u librenms ./lnms device:poll "<DEVICE_ID>" --modules="unix-agent,applications"
```

After polling, open the Windows device in LibreNMS. The `Apps` or
`Applications` view should show `Windows Agent`.

The application page always provides `Overview`, `Roles & Workloads`,
`Security & Certificates`, `Backup`, `Services & Events`, and
`Agent Performance`. Each detected first-order role also gets its own tab,
shown only while that role is detected and placed ahead of `Overview`:
`Active Directory` on a domain controller, `FactoryTalk` on a FactoryTalk host,
and `Horizon` on a Horizon server. The leftmost tab present is the landing tab,
so a role host opens on its role. Roles that were evaluated but not detected are
listed in the `Detected Roles` table on `Roles & Workloads`.

Quick network check from the poller that owns the device:

```bash
WINDOWS_HOST="<windows-hostname-or-ip>"
timeout 5 nc -vz "$WINDOWS_HOST" 6556
timeout 15 bash -c "cat < /dev/null | nc '$WINDOWS_HOST' 6556" | head -40
```

### 7. Plan Poller Capacity

The Windows agent adds poller worker time to each Windows device that has
`Unix Agent` enabled. Field validation with the full default collector set
showed about `8-10` poller worker-seconds per Windows-agent device per poll
cycle. LibreNMS application parsing was negligible, around `0.02` seconds; the
main cost is the poller worker waiting for the TCP `6556` agent payload.

Capacity estimate:

```text
Windows-agent devices * 8-10 seconds = added worker-seconds per poll cycle

100 Windows devices = about 800-1000 added worker-seconds per cycle
150 Windows devices = about 1200-1500 added worker-seconds per cycle
```

Before a broad rollout, check LibreNMS `Poller Cluster Health` and compare
`Worker Seconds Consumed/Maximum` on each active poller. Roll out in batches,
then wait a few normal polling intervals and confirm:

- active pollers are not consistently above about `90%` worker-seconds used;
- `Devices Pending` stays near zero;
- poller `Last Checkin` remains current;
- no single poller receives most of the Windows-agent devices.

If a poller is close to saturation, rebalance devices, add poller capacity, or
tune collector runtime before continuing the rollout.

## Addendum

### Per-Device Module Overrides

Use per-device settings only when a device should differ from the global
LibreNMS module defaults. These settings are different from the per-device
`Applications` tab where `Windows Agent` is enabled.

GUI path:

1. Open the Windows device in LibreNMS.
2. Open device settings.
3. Go to `Modules`.
4. Enable or disable `Applications` and `Unix Agent` for that device.

CLI enable by device ID:

```bash
cd /opt/librenms
DEVICE_ID="<DEVICE_ID>"

sudo -u librenms env DEVICE_ID="$DEVICE_ID" php -r '
chdir("/opt/librenms");
require "includes/init.php";
$device = \App\Models\Device::findOrFail((int) getenv("DEVICE_ID"));
$device->setAttrib("poll_applications", true);
$device->setAttrib("poll_unix-agent", true);
echo "Enabled Applications and Unix Agent for device " . $device->device_id . PHP_EOL;
'
```

CLI remove per-device overrides:

```bash
cd /opt/librenms
DEVICE_ID="<DEVICE_ID>"

sudo -u librenms env DEVICE_ID="$DEVICE_ID" php -r '
chdir("/opt/librenms");
require "includes/init.php";
$device = \App\Models\Device::findOrFail((int) getenv("DEVICE_ID"));
$device->forgetAttrib("poll_applications");
$device->forgetAttrib("poll_unix-agent");
echo "Removed Applications and Unix Agent overrides for device " . $device->device_id . PHP_EOL;
'
```

### Overlay Options

Custom LibreNMS root:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo env LIBRENMS_ROOT=/path/to/librenms bash
```

Install a specific overlay version:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo bash -s -- --version 0.6.25
```

Preview without changing the node:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo bash -s -- --dry-run
```

### Windows Agent Installation

Interactive install after downloading the MSI:

```powershell
msiexec /i librenms-windows-agent-0.6.16.msi /L*V "$env:TEMP\librenms-windows-agent-0.6.16-install.log"
```

Silent install after downloading the MSI:

```powershell
msiexec /i librenms-windows-agent-0.6.16.msi /qn /L*V "$env:TEMP\librenms-windows-agent-0.6.16-install.log"
```

The direct MSI intentionally uses one reliable default path:

- listener `0.0.0.0:6556`
- existing `agent.json` preserved
- complete FactoryTalk collection enabled, including local Counter Monitor
- service installed as automatic and started during installation
- domain/private inbound TCP 6556 firewall rules attempted natively without
  making firewall-policy refusal fatal to service installation

The one-command `install-agent.ps1` wrapper retains its optional listener,
configuration, service, firewall, and native-counter parameters. It downloads
the MSI and its matching versioned configuration, verifies both checksums,
backs up and prepares the effective configuration, then lets the MSI perform
the upgrade and service transition transactionally. It never uninstalls a
registered MSI before the replacement transaction.

Silent uninstall:

```powershell
msiexec /x librenms-windows-agent-0.6.16.msi /qn /L*V "$env:TEMP\librenms-windows-agent-0.6.16-uninstall.log"
```

### Collector Expectations

All public collectors are enabled by default. They are designed to collect
broad inventory while scoring health only when the host clearly owns that role.

Backup health is expectation-driven:

- `auto`: default; local Datto health is scored only when a local Datto agent
  is detected.
- `local_agent`: Datto is expected locally.
- `agentless_vcenter`: backup is expected through vCenter or the backup
  platform; the Windows guest does not claim job success or failure.
- `none`: no local backup expectation.

FactoryTalk runtime visibility uses bounded local Windows process performance
counters and is enabled by default when FactoryTalk is detected. Release 0.6.14
MSI installs and upgrades enable the complete FactoryTalk feature set by
setting native FactoryTalk Diagnostics Counter Monitor collection to `local`,
including when the existing configuration is preserved. Non-MSI configurations
retain the conservative `disabled` default. The effective MSI configuration is:

```json
"factoryTalk": {
  "mode": "auto",
  "includeRuntimeMetrics": true,
  "nativeCountersMode": "local",
  "nativeCounterIntervalSeconds": 900,
  "nativeCounterTimeoutSeconds": 30,
  "nativeCounterExecutablePath": ""
}
```

The native snapshot collector runs only against `localhost`, accepts only a
trusted Rockwell-signed `FTCounterMonitor.exe`, does not interrupt an existing
Counter Monitor instance, and never returns or retains raw snapshot XML. It
emits only allowlisted aggregate Linx connection, backplane, transaction, and
Live Data client counters. These metrics are informational and non-alerting by
default. The interval is clamped to at least 300 seconds and the timeout to
5-60 seconds.

Change collector expectations in:

```text
C:\ProgramData\LibreNMS\Windows Agent\agent.json
```

Then restart the service:

```powershell
Restart-Service LibreNMSWindowsAgent
```

### Overlay Rollback

Run on a LibreNMS node where the overlay installer has completed:

```bash
cd /usr/local/lib/librenms-windows-agent-overlay/current
sudo bash ./rollback-overlay.sh --librenms-root /opt/librenms
```

Add `--delete-apps` only when you intentionally want to remove existing
`windows-agent` application rows and metrics.

### Windows Agent Diagnostics

A useful local smoke test on a Windows host:

```powershell
& "C:\Program Files\LibreNMS\Windows Agent\LibreNMS.WindowsAgent.Service.exe" --once --config "C:\ProgramData\LibreNMS\Windows Agent\agent.json" | Select-String '^<<<windows_agent|collectors_run|collect_duration_ms'
```

Expected output includes `<<<windows_agent>>>`, `collectors_run=23`, and
`collectors_failed=0`.

## Development

Universal agent, collector, MSI, and overlay work is developed directly in this
repository. The source tree uses only the public `LibreNMS.WindowsAgent`
namespaces and `windows_agent_*` protocol; no private identifier-conversion
promotion step is required.

Key paths:

- `src/LibreNMS.WindowsAgent.Core` — protocol, configuration, collector runner,
  and shared health logic.
- `src/LibreNMS.WindowsAgent.Service` — Windows service host and collectors.
- `tests/LibreNMS.WindowsAgent.Tests` — portable console test suite.
- `tests/librenms-overlay` — parser and app-page fixtures.
- `installer/` — WiX MSI and Windows configuration actions.
- `librenms-overlay/` — native generic LibreNMS parser, application, graph,
  installer, rollback, and validation source.

Build and test:

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
.\scripts\build-overlay-package.ps1 -ArtifactsDir <temporary-output-directory>
.\scripts\build-msi.ps1 -ArtifactsDir <temporary-output-directory>
```

Build both release payloads and refresh `SHA256SUMS` only when intentionally
preparing a release:

```powershell
.\scripts\build-release.ps1 -UpdateChecksums
```
