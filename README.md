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

### 4. Install Or Update The Windows Agent

Run PowerShell as Administrator on each Windows host:

```powershell
iwr -UseBasicParsing https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install-agent.ps1 -OutFile $env:TEMP\install-agent.ps1
& $env:TEMP\install-agent.ps1 -Silent
```

Direct MSI link:

```text
https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/artifacts/librenms-windows-agent-0.6.13.msi
```

The default Windows install is normally enough. It installs the
`LibreNMSWindowsAgent` service, listens on `0.0.0.0:6556`, creates the Windows
firewall rule, starts the service, and preserves existing config on upgrade.
On FactoryTalk hosts, the MSI also enables the complete bounded FactoryTalk
feature set, including localhost Counter Monitor snapshots every 15 minutes.
The repaired 0.6.13 package can replace an earlier 0.6.13 build in place. Major
upgrades remove the prior package inside the MSI rollback boundary, and setup
reports success only after the installed Windows service reaches `Running`.

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
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo bash -s -- --version 0.6.13
```

Preview without changing the node:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent/main/install.sh | sudo bash -s -- --dry-run
```

### Windows Agent MSI Options

Interactive install after downloading the MSI:

```powershell
msiexec /i librenms-windows-agent-0.6.13.msi
```

Silent install after downloading the MSI:

```powershell
msiexec /i librenms-windows-agent-0.6.13.msi /qn
```

Silent install with explicit MSI properties:

```powershell
msiexec /i librenms-windows-agent-0.6.13.msi /qn LISTEN_ADDRESS=0.0.0.0 LISTEN_PORT=6556 ADD_FIREWALL_RULE=1 START_SERVICE=1 ENABLE_FACTORYTALK_NATIVE_COUNTERS=1
```

Supported MSI properties:

- `LISTEN_ADDRESS`, default `0.0.0.0`
- `LISTEN_PORT`, default `6556`
- `ADD_FIREWALL_RULE`, default `1`
- `START_SERVICE`, default `1`
- `CONFIG_PATH`, optional explicit config path
- `PRESERVE_CONFIG`, default `1`
- `ENABLE_FACTORYTALK_NATIVE_COUNTERS`, default `1`; use `0` to disable native
  Counter Monitor snapshots while retaining Windows-native FactoryTalk runtime
  metrics

Silent uninstall:

```powershell
msiexec /x librenms-windows-agent-0.6.13.msi /qn
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
counters and is enabled by default when FactoryTalk is detected. Release 0.6.13
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

Expected output includes `<<<windows_agent>>>`, `collectors_run=22`, and
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
