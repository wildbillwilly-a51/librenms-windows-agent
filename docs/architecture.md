# Architecture

## Design Goal

The agent is LibreNMS-first, but not hard-coded as a one-off script. The service
has a small stable core:

- collect structured section data
- isolate collector failures and timeouts
- render Checkmk-compatible plaintext
- serve one read-only TCP payload per connection

New functionality should normally be added as a collector, not by changing the
listener or renderer.

## Projects

- `LibreNMS.WindowsAgent.Core`: protocol, config models, rendering, collector runner,
  allowlist matching, and TCP server.
- `LibreNMS.WindowsAgent.Service`: Windows service entry point, config loading,
  logging, support bundle, and Windows-specific collectors.
- `LibreNMS.WindowsAgent.Tests`: no-dependency test runner for core behavior.

## Collector Contract

A collector implements `IAgentCollector` and returns one or more `AgentSection`
objects. The section name becomes the Checkmk header:

```text
<<<windows_agent_pending_reboot>>>
pending=1 sources=windows_update
```

Collectors can also return a `local` section. All `local` lines are merged into
one final `<<<local>>>` block so LibreNMS/Checkmk-style local checks are easy to
alert on.

Collector rules:

- Collect enough to answer whether a server function is healthy, plus enough
  context to explain likely cause. Do not collect every available byte just
  because it is readable.
- Use stable, machine-readable key/value lines.
- Summarize first and emit detail only when it supports health, root cause, or
  operator action.
- Avoid secrets, private key material, user content, full logs, raw directory
  dumps, and high-cardinality inventories unless explicitly required for a
  health determination.
- Prefer read-only CIM/WMI, registry, service-control, or Windows APIs.
- Keep expensive checks disabled by default or bounded by timeouts.
- Return degraded state rather than throwing where practical.

## Compatibility

The service targets .NET Framework 4.6.2 so the current Windows Server
2016/2019/2022 test hosts can run it without a .NET 4.8 prerequisite. Windows
Server 2012 R2 remains in scope with .NET Framework 4.6.2 or later installed.
The public package uses a WiX x64 MSI, preserves config on upgrade by default,
and keeps a stable UpgradeCode while generating a new ProductCode for each
version. PowerShell remains available for source-tree service installation and
diagnostics.

## Initial Sections

- `windows_agent`: agent metadata and protocol identity.
- `windows_agent_windows_os`: Windows caption, version, build, architecture, and boot time.
- `windows_agent_uptime`: uptime seconds and boot timestamp.
- `windows_agent_cpu`: CPU name, cores, logical processors, load, and clock.
- `windows_agent_memory`: physical and virtual memory totals.
- `windows_agent_disks`: fixed logical disk size/free/used values.
- `windows_agent_services_summary`: classified service group totals.
- `windows_agent_services`: classified service details.
- `windows_agent_services_excluded`: explicitly excluded low-value service details.
- `windows_agent_roles`: detected Windows server roles.
- `windows_agent_ad_summary`: Active Directory/domain-controller summary state.
- `windows_agent_ad_replication`: AD replication target/source visibility.
- `windows_agent_ad_dfsr`: DFSR replicated folder/member health visibility.
- `windows_agent_ad_fsmo`: FSMO role owner visibility.
- `windows_agent_ad_dc_health_summary`, `windows_agent_ad_dc_services`, `windows_agent_ad_dc_dns`,
  `windows_agent_ad_dc_time`, `windows_agent_ad_dc_shares`, and `windows_agent_ad_dc_security_events`:
  local-only domain-controller readiness evidence for core services, DNS
  service state, time status, SYSVOL/NETLOGON publication, and bounded
  security event category counts.
- `windows_agent_logged_on_users`: interactive console/RDP user session visibility.
- `windows_agent_pending_reboot`: pending reboot state and source.
- `windows_agent_windows_update`: Windows Update service state and reboot requirement.
- `windows_agent_event_logs`: recent critical, error, and warning counts by configured
  Windows Event Log.
- `windows_agent_event_log_high_value_summary` and `windows_agent_event_log_high_value`: bounded
  high-value critical/error event evidence grouped by log, provider, event ID,
  and level, with only the most recent configured samples per signature.
- `windows_agent_processes`: configured process match counts and aggregate resource
  counters.
- `windows_agent_tcp_ports`: configured local TCP listener state.
- `windows_agent_performance_summary`, `windows_agent_performance_disks`,
  `windows_agent_performance_network`, and `windows_agent_performance_processes`: bounded local
  pressure evidence for CPU queue, memory/paging, disk latency/queue, network
  errors, and top CPU/memory process context.
- `windows_agent_sql_server_summary` and `windows_agent_sql_server_instances`: local SQL Server
  service/instance/listener visibility without SQL login.
- `windows_agent_iis_summary`, `windows_agent_iis_sites`, `windows_agent_iis_app_pools`, and
  `windows_agent_iis_bindings`: local IIS role, site, app pool, binding, and certificate
  thumbprint visibility.
- `windows_agent_horizon_summary`, `windows_agent_horizon_services`, `windows_agent_horizon_processes`,
  `windows_agent_horizon_ports`, and `windows_agent_horizon_certificates`: local VMware/Omnissa
  Horizon footprint, service/process, listener, and host certificate visibility
  without Horizon credentials or external API access.
- `windows_agent_tls_certificates_summary` and `windows_agent_tls_certificates`: LocalMachine
  certificate health, expiration, chain, key strength, private-key, and local
  HTTP.SYS binding signals without exporting private key material.
- `windows_agent_backup_storage_summary`, `windows_agent_vss_writers`, and
  `windows_agent_backup_services`: VSS writer state and known backup/storage service
  visibility.
- `windows_agent_datto_backup_summary`, `windows_agent_datto_backup_services`,
  `windows_agent_datto_backup_processes`, and `windows_agent_datto_backup_evidence`: local Datto
  Backup Agent service/process/evidence health without Datto cloud or appliance
  API access.
- `local`: alertable status lines for legacy watched services and reboot state.
