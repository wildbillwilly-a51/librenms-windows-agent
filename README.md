# LibreNMS Windows Agent Installer

One-command installers for the generic Windows Agent LibreNMS overlay and the
Windows-side agent MSI.

## Windows Agent MSI

Run PowerShell as Administrator on each Windows host that should expose the
agent on TCP `6556`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install-agent.ps1 -OutFile $env:TEMP\install-agent.ps1; & $env:TEMP\install-agent.ps1"
```

Silent install:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -Command "iwr -UseBasicParsing https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install-agent.ps1 -OutFile $env:TEMP\install-agent.ps1; & $env:TEMP\install-agent.ps1 -Silent"
```

`ListenAddress` is the local bind address on the Windows host. The default
`0.0.0.0` means the agent listens on all local network interfaces; it is not
the LibreNMS server or poller IP. Use a specific Windows host IP only when the
agent should listen on one interface.

Direct MSI download:

```text
https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/artifacts/librenms-windows-agent-0.6.0.msi
```

Direct interactive MSI install after download:

```powershell
msiexec /i librenms-windows-agent-0.6.0.msi
```

Direct silent MSI install after download, using defaults:

```powershell
msiexec /i librenms-windows-agent-0.6.0.msi /qn
```

Optional override example:

```powershell
msiexec /i librenms-windows-agent-0.6.0.msi /qn LISTEN_ADDRESS=192.0.2.25 LISTEN_PORT=6556 ADD_FIREWALL_RULE=1 START_SERVICE=1
```

Silent uninstall:

```powershell
msiexec /x librenms-windows-agent-0.6.0.msi /qn
```

Supported MSI properties are `LISTEN_ADDRESS`, `LISTEN_PORT`,
`ADD_FIREWALL_RULE`, `START_SERVICE`, `CONFIG_PATH`, and `PRESERVE_CONFIG`.
They are optional; the default install path normally needs no additional
Windows-side configuration.

## LibreNMS Server Overlay

Run this command on each LibreNMS server or distributed poller node that may
render or poll Windows Agent data:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh | sudo bash
```

The installer expects LibreNMS at `/opt/librenms`. Override that path when
needed:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh | sudo env LIBRENMS_ROOT=/path/to/librenms bash
```

Preview without changing the server:

```bash
curl -fsSL https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh | sudo bash -s -- --dry-run
```

## What It Installs

- Windows MSI installing the `LibreNMSWindowsAgent` service.
- LibreNMS `unix-agent` parser for `windows_agent_*` sections.
- LibreNMS application parser and page for the `windows-agent` app.
- Application graph definitions for Windows Agent health and visibility.
- A reapply command and systemd timer so the overlay can be restored after
  LibreNMS updates.

The Windows device must run the Windows agent MSI or another compatible agent
that emits `windows_agent_*` sections over TCP `6556`.

## After Install

The Windows machine must already exist in LibreNMS as a device, normally from
SNMP discovery. The overlay does not discover Windows hosts by itself; it adds
Windows Agent parsing and the `Windows Agent` application view for an existing
LibreNMS device.

Recommended order:

1. Discover or add the Windows machine in LibreNMS with SNMP.
2. Install the Windows agent MSI on the Windows machine.
3. Install the LibreNMS server overlay on the management node and each poller
   that may poll or render the device.
4. Enable the `unix-agent` module on the Windows device in LibreNMS.
5. Confirm the LibreNMS server or poller can reach the Windows host on TCP
   `6556`.

Force a first poll if desired:

```bash
cd /opt/librenms
sudo -u librenms php /opt/librenms/lnms device:poll "<DEVICE_ID>" --modules="unix-agent,applications"
```

The Applications tab should show `Windows Agent` after successful polling.

## Rollback

The overlay package includes a rollback script. On a node where the installer
has run:

```bash
cd /usr/local/lib/librenms-windows-agent-overlay/current
sudo bash ./rollback-overlay.sh --librenms-root /opt/librenms
```

Add `--delete-apps` only when you intentionally want to remove existing
`windows-agent` application rows and metrics.
