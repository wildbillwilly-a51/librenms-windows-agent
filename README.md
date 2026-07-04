# LibreNMS Windows Agent Installer

One-command installer for the generic Windows Agent LibreNMS overlay.

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

- LibreNMS `unix-agent` parser for `windows_agent_*` sections.
- LibreNMS application parser and page for the `windows-agent` app.
- Application graph definitions for Windows Agent health and visibility.
- A reapply command and systemd timer so the overlay can be restored after
  LibreNMS updates.

The Windows device must already run an agent that emits `windows_agent_*`
sections over TCP `6556`. This installer only changes the LibreNMS server side.

## After Install

Enable the `unix-agent` module on the Windows device in LibreNMS, then force a
first poll if desired:

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
