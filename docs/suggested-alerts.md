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
| SQL database engine stopped | `sql_instances_not_running` | `> 0` when SQL is expected | Warning |
| SQL Agent stopped when expected | `sql_agents_expected_not_running` | `> 0` when SQL is expected | Warning |
| SQL component service stopped | `sql_components_expected_not_running` | `> 0` when SQL is expected | Warning |
| SQL instance with no active listener | `sql_instances_without_listener` | `> 0` when SQL is expected | Warning |
| SQL surface health rollup | `sql_health_issues` | `> 0` when SQL is expected | Warning |
| IIS site stopped | `iis_sites_stopped` | `> 0` when IIS is expected | Warning |
| IIS app pool stopped | `iis_app_pools_stopped` | `> 0` when IIS is expected | Warning |
| Horizon Connection Server health | `horizon_health_issues` | `> 0` on server-side Horizon hosts | Critical |
| FactoryTalk core service health | `factorytalk_health_issues` | `> 0` on FactoryTalk hosts | Warning |

The SQL counters above are expectedness-aware, so they stay quiet on hosts that
are configured not to run a component:

- `sql_agents_expected_not_running` counts a stopped SQL Server Agent only when
  its start mode is `Auto` and the paired database engine is running. A SQL
  Agent left on `Manual` or `Disabled` is reported as
  `sql_agents_not_expected_running` instead, which is informational and should
  not be alerted on without local knowledge that the host expects it running.
- `sql_components_expected_not_running` counts stopped `Auto` component services
  (Analysis Services, Reporting Services, Integration Services, Full-Text,
  Launchpad, VSS writer). Telemetry is never counted.
- `sql_health_issues` is the rollup of the engine, agent, component, and SQL
  Browser verdicts. Alert on it instead of the individual counters when one
  SQL rule per host is enough.
- `sql_unmapped_engine_processes` is diagnostic, not a failure: it counts
  `sqlservr.exe` processes that no detected service owns, which means instance
  discovery is incomplete on that host. Per-user LocalDB instances are excluded
  and counted in `sql_localdb_processes` instead; do not alert on that one.

## SQL Server Performance Counters

`windows_agent_sql_server_performance` is new visibility and ships non-alerting:
none of the rules below is enabled anywhere, and none should be turned on before
the numbers have been watched on the hosts in question for long enough to know
their normal range. A development build read the counters on a multi-instance
SQL Server 2017 host; availability still depends on each host, so treat a
missing metric as "not published here" first.

| Purpose | Metric | Suggested condition | Severity |
| --- | --- | --- | --- |
| Blocked sessions on any instance | `sql_perf_blocked_max` | `> 0` sustained across several polls | Warning |
| Queries queued for workspace memory | `sql_perf_grants_pending_max` | `> 0` sustained across several polls | Warning |
| Buffer pool turning over too fast | `sql_perf_ple_min` | `< 300` and `>= 0` | Warning |
| SQL Agent job failures | `sql_perf_jobs_failed_total` | `> 0` | Warning |
| Counter pressure rollup | `sql_perf_pressure_issues` | `> 0` when SQL is expected | Warning |

Notes that change how these should be written:

- Every counter metric carries `-1` when the agent could not read it. A rule must
  exclude `-1` explicitly (`>= 0` alongside the real condition) or it will fire on
  hosts that publish nothing, which is the opposite of the intent.
- `sql_perf_blocked_max` and `sql_perf_grants_pending_max` are instantaneous
  samples taken once per poll. A single non-zero sample is normal on a busy
  instance; only a sustained one is an incident. Use a multi-poll condition.
- `sql_perf_ple_min` is the minimum across instances, so one small instance with a
  cold cache drags it down for the host. Prefer the per-instance key below when a
  host runs instances of very different sizes.
- `sql_perf_jobs_failed_total` sums valid failure deltas between the agent's
  latest two counter collections. It is not a lifetime or daily total. An
  unreadable counter or first sample has no usable delta and is `-1`; a later
  measured zero means no new failures. A counter reset reports the current
  count since SQL Agent restarted. These are collection deltas, not a durable
  job-event history: a collection that LibreNMS does not poll may be missed.
- The 300-second page life expectancy threshold is the historical rule of thumb for
  a small buffer pool and is far too low for a large one. It is the collector
  default (`pageLifeExpectancyWarning`) and should be raised per host rather than
  alerted on as shipped.

Per-instance metrics are keyed `sql_perf_<measure>:<key>`, where `<key>` is the SQL
instance name sanitized to `[A-Za-z0-9_-]` — the same contract the Horizon pools
use. Available measures are `state`, `blocked`, `grants_pending`, `ple`,
`connections`, `batches`, `jobs_failed`, and `pressure`. `sql_perf_state:<key>` is a
severity ordinal: `0` ok, `1` info, `2` warning, `3` critical, with `-1` disabled and
`-2` counters unreadable, so a `>= 2` rule skips an instance that published nothing.

## Horizon Per-Pool Availability

Per-pool metrics are keyed `horizon_pool_<measure>:<key>`, where `<key>` is the
sanitized pool name (see `docs/horizon-monitoring.md`). Rules select pools by
qualifying the metric name; always include the trailing colon.

| Purpose | Metric qualifier | Suggested condition | Severity |
| --- | --- | --- | --- |
| One pool below N ready | `= "horizon_pool_ready:FTVW11SE"` | `< N` | Warning/Critical |
| One pool below X% ready | `= "horizon_pool_ready_percent:FTVW11SE"` | `< X` | Warning/Critical |
| Any pool at a site below N ready | `contains "horizon_pool_ready:FTVW11"` | `< N` | Warning |
| Collector says pool critical | `contains "horizon_pool_state:"` | `>= 3` | Critical |
| Collector says warning or worse | `contains "horizon_pool_state:"` | `>= 2` | Warning |
| Suppress drained pools | `contains "horizon_pool_maintenance:"` | pair as `... && ...maintenance = 0` | — |
| A pool stopped reporting | `horizon_pools_incomplete` (estate rollup) | `> 0` | Warning |

- These rows are self-scoping: a `horizon_pool_*:<key>` row exists only on the
  device that reports that pool, so pool-specific rules match only the right
  host. Do not use the estate-wide summary rollups (which default to `0`
  everywhere) for pool-level conditions.
- `horizon_pool_state` is a severity ordinal (`ok=0, info=1, warning=2,
  critical=3`); `disabled=-1` and `incomplete=-2` sit below `ok`, so `>= 2` /
  `>= 3` rules skip them. `info` is a single unavailable spare while ready
  capacity remains — usually not worth paging. Detecting "a pool went dark" is
  the job of `horizon_pools_incomplete`, not a vanished per-pool row.
- Require the condition to persist for at least two polls so instant-clone
  replacement waves do not page from a single sample.

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
