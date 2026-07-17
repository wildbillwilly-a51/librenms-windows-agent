# LibreNMS Overlay

This overlay makes the Windows Agent data visible in LibreNMS.

It adds:

- `includes/polling/unix-agent/windows_agent.inc.php`
- `includes/polling/applications/windows-agent.inc.php`
- `includes/html/pages/device/apps/windows-agent.inc.php`
- application graph definitions for reachability, reboot state, watched
  services, classified services, Event Logs, watched processes, watched TCP
  ports, role visibility, Windows performance depth, AD/DC local health, VMware
  Horizon, TLS health, backup/storage, and Datto backup health

The overlay depends on LibreNMS `unix-agent` already being enabled for the
Windows device. It does not replace SNMP polling and does not change the
Windows-side agent protocol.

The app page is grouped by purpose under tabs and starts with a compact health
overview. Section detail tables and trend graphs are collapsed by default so
not-detected roles stay visible without blank tables or graph-heavy panels.
FactoryTalk uses an issue-first operational view: a status and next-action
banner, six key metrics, actionable conditions, and the five busiest runtime
processes appear before any inventory. Complete process metrics, product and
service inventory, ports, native Counter Monitor output, and Linx counter rows
remain available in nested disclosures. Primary FactoryTalk trends appear
first, while secondary graphs are one additional disclosure deeper. Issue
counts come only from the FactoryTalk collector's scored health result and
stopped core services. Optional listeners, non-core services, runtime/native
availability, cumulative send failures, and transaction utilization remain
informational and do not change section health.

Horizon uses the same compact operational pattern: collector-confirmed health,
next action, six key service/listener/certificate metrics, and collapsed raw
inventory appear before its two trend graphs. Issue rows correspond only to
the Horizon collector's scored automatic-service, required TCP 443,
expired-certificate, and critical-expiry checks. Optional configured listeners
remain visible without becoming health issues.

Startup type, current state, and status are displayed as service data only; they
are not used as inclusion or exclusion filters. Role, AD/DFSR, and logged-on
user sections are visibility-only. Auto-classified services, logged-on user
metrics, and AD/DFSR metrics do not create alert rules by default.

## Package Install

Build the portable overlay package from the repo:

```bash
scripts/build-librenms-overlay-package.sh
```

The artifact is written to:

```text
artifacts/librenms-windows-agent-overlay-<version>.tar.gz
```

On a LibreNMS host, extract the package and install it:

```bash
tar -xzf librenms-windows-agent-overlay-<version>.tar.gz
cd librenms-windows-agent-overlay-<version>
LIBRENMS_ROOT=/opt/librenms bash ./install-overlay.sh
```

The installer:

- lints all packaged PHP files before copying
- backs up replaced files under
  `/var/backups/librenms-windows-agent-overlay/<timestamp>/`
- installs only files listed in `manifest.txt`
- runs LibreNMS `validate.php`
- stores a copy of the package under
  `/usr/local/lib/librenms-windows-agent-overlay/current`
- installs `/usr/local/sbin/librenms-windows-agent-overlay-reapply`
- skips unchanged files during reapply so update hooks or timers do not create
  backup churn

The reapply command exists so production hosts can restore the overlay after a
LibreNMS update if custom files are removed or replaced:

```bash
librenms-windows-agent-overlay-reapply
```

For LibreNMS update persistence, run the reapply command after every LibreNMS
upgrade. Sites that use LibreNMS' manual update flow can add it as a final
post-update step. Sites that prefer automatic recovery can install the included
systemd timer after confirming the reapply command is idempotent in their
environment.

Optional example systemd units are included under `systemd/` for sites that
want periodic reapply:

```bash
sudo install -m 0644 systemd/librenms-windows-agent-overlay-reapply.service /etc/systemd/system/
sudo install -m 0644 systemd/librenms-windows-agent-overlay-reapply.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now librenms-windows-agent-overlay-reapply.timer
```

Rollback uses the same manifest:

```bash
LIBRENMS_ROOT=/opt/librenms bash ./rollback-overlay.sh
```

Add `--delete-apps` only when you intentionally want to remove the
`windows-agent` application rows and metrics. Add `--remove-state` only
when you also want to remove the persisted package copy and reapply command.

## Remote Deploy

For the Windows Agent test environment, `scripts/deploy-librenms-overlay.sh` builds this
package, copies it to `windows-agent-librenms`, runs `install-overlay.sh`, and then runs
package validation. Override these variables for another LibreNMS instance:

- `WINDOWS_AGENT_LIBRENMS_HOST`
- `WINDOWS_AGENT_LIBRENMS_SSH_USER`
- `WINDOWS_AGENT_LIBRENMS_SSH_KEY`
- `WINDOWS_AGENT_LIBRENMS_ROOT`
- `WINDOWS_AGENT_EXPECTED_APP_COUNT`
- `WINDOWS_AGENT_EXPECTED_METRIC_COUNT`
- `WINDOWS_AGENT_EXPECTED_DEVICE_IDS`

Use `WINDOWS_AGENT_LIBRENMS_DRY_RUN=1` to preview a remote install.
