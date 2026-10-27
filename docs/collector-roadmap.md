# Collector Roadmap

The collector set is broad but intentionally bounded. Add new checks in this
order so the agent stays supportable.

## Collector Philosophy

Collectors are function-health checks, not Windows data exhaust. Each collector
should gather enough evidence to answer whether a server role or function is
working correctly, plus concise context for likely cause and operator action.
Avoid raw inventories, full logs, broad dumps, secrets, private key material,
and high-cardinality data unless those details directly support a health
decision.

Default shape:

- summary state first
- actionable detail second
- common role fields when available: `state`, `detected`, `health_issues`,
  `evidence`, `health_scope`, and `next_action`
- no alerting by default unless explicitly approved
- local-only collection unless a future design explicitly justifies credentials

## Implemented Foundation Collectors

- Agent metadata, OS, uptime, CPU, memory, disks.
- Classified Windows service visibility, with legacy watched services retained
  for essential service local checks.
- Role detection through `windows_agent_roles`.
- Role detection combines local service/process evidence with installed Windows
  Server roles/features when `Get-WindowsFeature` is available.
- Active Directory/DFSR/FSMO/DC local health visibility through `windows_agent_ad_*`
  sections.
- Domain-controller security event summaries are DC-only, bounded, and
  category-count based. They do not emit user names or raw event messages.
- Logged-on interactive/RDP user visibility through `windows_agent_logged_on_users`.
- Pending reboot and Windows Update reboot-required state.
- Event Log summary through `windows_agent_event_logs`.
- Watched process count/resource summary through `windows_agent_processes`.
- Watched local TCP listener state through `windows_agent_tcp_ports`.
- SQL Server surface visibility through `windows_agent_sql_server_*` sections:
  per-instance engine state, registry-sourced edition/patch level/TCP endpoints,
  engine-to-process mapping, expectedness-aware SQL Agent and component verdicts,
  SQL Browser requirement, and non-engine components (Analysis Services,
  Reporting Services, Integration Services, Full-Text, Launchpad, VSS writer,
  telemetry). Still no database queries and no credentials: nothing here needs a
  SQL login.
- SQL Server server-level performance counters through
  `windows_agent_sql_server_performance`: memory grants, page life expectancy,
  blocked processes, batch/compile rates, lock and latch waits, deadlocks, tempdb
  and version store, log flush activity, optional Availability Group queue depth,
  and SQL Agent job counts as deltas. Read through PDH by the account the agent
  already runs as, so this still needs no SQL login. Per-database counters remain
  deferred for cardinality; depth that requires a connection — backup age, job
  outcome, database state, wait and file-IO statistics — is item 5 below and is
  surveyed in `docs/sql-collector-expansion.md`.
- IIS visibility through `windows_agent_iis_*` sections.
- VMware Horizon visibility through `windows_agent_horizon_*` sections, using local
  service/process/listener/certificate evidence only.
- TLS certificate store visibility and local health validation through
  `windows_agent_tls_certificates_*` sections. Current health signals include expiration,
  not-yet-valid state, chain validity with revocation disabled by default, weak
  key/signature indicators, missing private key, and HTTP.SYS binding/store
  mismatch counts.
- Backup/storage visibility through `windows_agent_backup_storage_summary`,
  `windows_agent_vss_writers`, and `windows_agent_backup_services`.
- Datto-local backup health through `windows_agent_datto_backup_*` sections, using local
  service/process/log evidence only.
- Windows performance depth through `windows_agent_performance_*` sections, using local
  pressure counters and bounded top process context.
- FactoryTalk Windows-native runtime metrics through
  `windows_agent_factorytalk_runtime_*` sections. Optional FactoryTalk
  Diagnostics Counter Monitor snapshots add allowlisted Linx connection,
  backplane, transaction, and Live Data client counters. Snapshot execution is
  localhost-only, signed-executable-only, independently throttled, raw-XML-free,
  and disabled by default.
- The LibreNMS application page uses an action-first overview: role state,
  why the role matters, evidence, next check, health scope, and then secondary
  inventory/detail tables.
- Recommended alert rules are documented in `docs/suggested-alerts.md`; they
  are not auto-created or enabled by the overlay.

Classified services, roles, AD/DFSR/DC health, logged-on users, event log,
process, and TCP port collectors emit structured `windows_agent_*` sections for visibility.
Auto-classified services, logged-on users, and role/AD health do not emit
`local` checks; only legacy `collectors.watchedServices` services do. This
keeps grouped application/vendor/role visibility from creating LibreNMS alert
noise by itself.

## Next Good Collectors

1. Add bounded IIS app-pool recycle/crash and HTTPERR summaries after fixture
   coverage is designed.
2. Add more precise Horizon Connection Server local event/performance evidence
   while staying credential-free and API-free.
3. Evaluate additional FactoryTalk diagnostics only after field experience with
   the bounded runtime and Counter Monitor sections; continue avoiding project
   files, controller subscriptions, and raw diagnostics by default.
4. Hyper-V: VM state, integration service state, replication health.
5. Deeper SQL Server, in the order surveyed in `docs/sql-collector-expansion.md`.
   Server-level performance counters through PDH are done and shipped as
   `windows_agent_sql_server_performance`, and a development build read them on a
   real multi-instance SQL Server 2017 host. Installed-service validation remains
   separate from that `--once` evidence. What remains, in order: bounded ERRORLOG and
   event-log evidence, then capped per-database counters, then optional local
   integrated queries for database/job/backup state after credential and
   permission handling is explicitly designed.

## Collector Acceptance Criteria

Each collector should include:

- a stable `windows_agent_*` section
- optional `local` checks only for states that should alert by default
- timeout-safe collection
- no secret output
- bounded, low-cardinality detail that supports health or likely root cause
- fixture or unit coverage for rendered section shape
- docs showing config and expected output
