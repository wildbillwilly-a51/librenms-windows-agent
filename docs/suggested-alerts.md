# Suggested LibreNMS Alerts

The Windows agent exposes application metrics that can support LibreNMS alert
rules. The overlay does not create or enable alert rules automatically. Treat
these as recommended starting points and tune thresholds for the local estate.

## Baseline Agent Health

| Purpose | Metric | Suggested condition | Severity |
| --- | --- | --- | --- |
| Agent stopped or unreachable | `agent_up` | `!= 1` | Critical |
| Collector failures | `agent_collectors_failed` | `> 0` | Warning |
| Collector timeouts | `agent_collectors_timed_out` | `> 0` | Warning |
| Poller worker-time risk | `agent_collect_duration_ms` | `> 10000` for repeated polls | Warning |

## Windows Host Health

| Purpose | Metric | Suggested condition | Severity |
| --- | --- | --- | --- |
| Pending reboot | `pending_reboot` | `= 1` for more than one maintenance window | Warning |
| Windows Update reboot required | `windows_update_reboot_required` | `= 1` for more than one maintenance window | Warning |
| Explicit watched service down | `watched_services_not_running` | `> 0` | Warning |
| Explicit watched process missing | `watched_processes_not_running` | `> 0` | Warning |
| Explicit watched TCP listener missing | `watched_tcp_ports_not_listening` | `> 0` | Warning |

## Role And Workload Health

| Purpose | Metric | Suggested condition | Severity |
| --- | --- | --- | --- |
| Domain controller local health | `ad_dc_health_issues` | `> 0` on detected DCs | Critical |
| DC account lockout burst | `ad_dc_security_lockouts` | `> estate baseline` | Warning |
| DC authentication failure burst | `ad_dc_security_auth_failures` | `> estate baseline` | Warning |
| DC privileged group changes | `ad_dc_security_privileged_changes` | `> 0` outside a change window | Warning |
| SQL service or Agent stopped | `sql_instances_not_running` | `> 0` when SQL is expected | Warning |
| IIS site stopped | `iis_sites_stopped` | `> 0` when IIS is expected | Warning |
| IIS app pool stopped | `iis_app_pools_stopped` | `> 0` when IIS is expected | Warning |
| Horizon Connection Server health | `horizon_health_issues` | `> 0` on server-side Horizon hosts | Critical |
| FactoryTalk core service health | `factorytalk_health_issues` | `> 0` on FactoryTalk hosts | Warning |

## Certificate And Backup Health

| Purpose | Metric | Suggested condition | Severity |
| --- | --- | --- | --- |
| Scored certificate unhealthy | `tls_certificates_unhealthy` | `> 0` | Warning |
| Bound/service certificate expired | `tls_certificates_expired` | `> 0` with `health_scope=scored` context | Critical |
| VSS writer failed | `vss_writers_failed` | `> 0` | Warning |
| Local backup service stopped | `backup_services_not_running` | `> 0` only when that local service is expected | Warning |
| Local Datto health issue | `datto_backup_health_issues` | `> 0` when `expectedBackupMode=local_agent` or local Datto is detected | Critical |
| Recent Datto critical failures | `datto_backup_recent_critical_failures` | `> 0` | Critical |

## Rule Design Notes

- Keep default alerts narrow: alert on clear broken function, not broad
  inventory.
- Use LibreNMS device groups or rule conditions so role-specific rules apply
  only to hosts where the role is expected.
- For domain controller security-event counters, establish a baseline before
  alerting. Some authentication failures and lockouts can be normal in large
  environments.
- For `agentless_vcenter` backup mode, do not alert on missing local Datto
  evidence. Guest-local checks cannot prove appliance-side backup success.
- For TLS, prioritize bound or service certificates. Unbound expired store
  certificates are useful inventory but often should not page anyone.
