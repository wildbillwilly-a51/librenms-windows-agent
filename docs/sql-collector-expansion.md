# SQL Server Collector Expansion — Investigation

Status: **defects fixed, tiers 1–3 still proposed.** This is the survey that
`docs/collector-roadmap.md` item 5 ("Deeper SQL Server") asks for before design
starts.

The five defects in "Defects found in the current collector" below have been
fixed; each carries a **Fixed** note. Tier 1 server-level counters are
implemented; per-database counters, Tier 2 and Tier 3 remain proposals.

`probe-sql-sources.ps1` was run once on the multi-instance test host on
2026-10-27, elevated but as an administrator rather than as LocalSystem. Its
results are in "On-host probe results" below. Neither the agent build nor the
collector itself has run on a SQL host yet.

## What the collector did before the defect fixes

`SqlServerCollector` (`src/LibreNMS.WindowsAgent.Service/Collectors/SqlServerCollector.cs`,
205 lines) is service-inventory only. Per detected engine service it emits
instance name, service name, display name, state, start mode, whether the
binary path exists, the binary's file version, the paired SQL Agent service
name and state, the SQL Browser state, and a port list.

The overlay renders two RRD datasets — `instances_total` and `not_running` —
and one app-page table. One suggested alert exists
(`sql_instances_not_running > 0`).

That answers exactly one question: *is the Windows service running?* It
answers nothing about whether the database engine is actually healthy.

## Defects found in the current collector

Verified against a live multi-instance SQL Server 2017 test host.

### 1. `listener_ports` is host-wide, not per-instance, and it is wrong

`listener_ports` is built once from all host TCP listeners filtered to ports
1433 and 1434, then stamped onto every instance row identically. On a host with
three instances, all three rows reported `listener_ports=1433,1434`.

An SSRP query to the SQL Browser on that same host returned the truth:

| Instance | Actual TCP port |
| --- | --- |
| default | 1433 |
| named instance A | 56217 |
| named instance B | 50757 |

So two of three rows were wrong, and the one that was right was right by
accident. Named instances use dynamic ports by default; the filter cannot ever
see them. Port 1434/TCP is also not a client port — it is the Dedicated
Administrator Connection, and reporting it as a listener is misleading.
(UDP 1434 is the Browser; it is not a TCP listener at all.)

**Fixed.** `SqlServerRegistryReader` reads `Tcp\IPAll` per instance and
`SqlServerPolicy.ResolvePorts` turns `TcpPort` / `TcpDynamicPorts` into a port
list plus a `port_source` of `static`, `dynamic`, `dynamic_unassigned`,
`disabled` or `unknown`. Each resolved port is then checked against the live
listener table, so the row also carries `listener_active`. The 1433/1434
allowlist is gone.

### 2. Process evidence and service evidence disagree, with nothing to reconcile them

The same host showed **four** `sqlservr.exe` processes but only **three**
engine services. The collector has no instance↔PID mapping, so it cannot
explain the fourth, and an operator reading the page cannot either.

`Win32_Service.ProcessId` maps service to PID directly.

**Fixed.** `ServiceInventoryRecord` now carries `ProcessId`, each instance row
reports `pid`, and the summary reports `unmapped_engine_processes`: the count of
`sqlservr.exe` processes that no detected engine service owns. A non-zero value
raises an attention row on the app page instead of leaving the operator to
notice the discrepancy themselves.

The on-host probe identified the fourth process: a per-user SQL Server LocalDB
instance started from a user profile, which no Windows service owns. That is
the case the counter exists for. The agent emits only the count, never the
process command line, which here contained a user's profile path.

### 3. Non-engine SQL components are detected but discarded

`IsSqlService` matched `MSSQLFDLauncher` (Full-Text) and `ReportServer`
(SSRS), but only engine services became instance rows, so those never appeared.
The test host also ran `MSSQLServerOLAPService` (Analysis Services),
`MSSQLLaunchpad` (ML Services), `SQLWriter` (VSS), and `SQLTELEMETRY` —
none matched or surfaced.

**Fixed.** `SqlServerPolicy.Identify` classifies every SQL service into a
component (`engine`, `agent`, `browser`, `fulltext`, `analysis_services`,
`reporting_services`, `integration_services`, `machine_learning`, `polybase`,
`vss_writer`, `telemetry`) and resolves its instance, including the
named-instance forms (`MSOLAP$X`, `ReportServer$X`, `MSSQLFDLauncher$X`,
`MSSQLLaunchpad$X`) and the host-wide ones (`MsDtsServer<nnn>`,
`SQLServerReportingServices`). Non-engine components are emitted in a new
`windows_agent_sql_server_components` section.

### 4. SQL Agent stopped does not count as a health issue

`health_issues` is `instances_not_running` only. On the test host two of three
SQL Agents were `Stopped` with `start_mode=Manual` and the summary still read
`state=ok health_issues=0`. A stopped SQL Agent means no scheduled backups, no
log backups, no index maintenance, no alerting — silently. That is at least as
severe as a stopped engine, and it is invisible today.

The expectedness pattern already used for Horizon services (`0.6.16` added
"explicit Horizon service expectedness") is the right precedent: treat
Agent-stopped as an issue when `start_mode` is `Auto`, and as informational
when it is `Manual`/`Disabled`.

**Fixed.** `SqlServerPolicy.ClassifyAgent` returns `critical` /
`agent_not_running` / `scheduled_work` when the Agent's start mode is `Auto`
and the paired engine is running; `info` / `agent_not_expected_running` on
`Manual`; `info` / `agent_disabled` on `Disabled`; and `info` /
`engine_not_running` when the engine itself is down, so a stopped engine does
not double-count as two issues. The alertable subset is
`agents_expected_not_running`; the non-alerting remainder is
`agents_not_expected_running`, which stays visible without firing. The same
treatment covers component services and the SQL Browser, and all of it rolls
into a new `health_issues` that is no longer just `instances_not_running`.
`instances_not_running` keeps its old meaning, so the existing alert rule is
unchanged.

### 5. `version` is the binary file version, not the product level

`2017.0140.3456.02` is the `sqlservr.exe` file version. It is usable, but it
is not what a DBA asks for and it does not carry edition, servicing branch, or
CU/GDR identity. The registry `Setup` key per instance carries `Edition`,
`PatchLevel`, `Version`, `SQLPath`, `SQLDataRoot`, and `Collation` directly,
credential-free.

**Fixed.** Each instance row now carries `edition`, `patch_level` and
`product_version` from the registry `Setup` key, plus `login_mode`
(`windows` / `mixed` / `unknown`) from the `MSSQLServer` key. The original
`version` field is retained unchanged so nothing downstream breaks. The app
page shows edition and patch level in place of the raw file version.
`SQLDataRoot` is read but deliberately not emitted — it is only needed to
locate the ERRORLOG for the Tier 2 work below.

## Tier 1 — Performance counters (credential-free, highest value)

This is the single biggest win available and it fits the existing collector
philosophy with no change to the credential posture. SQL Server publishes its
internal health as Windows performance counters readable by **LocalSystem with
no SQL login at all**.

Object prefix is `SQLServer:` for the default instance and `MSSQL$<INSTANCE>:`
for named instances. SQL Agent publishes `SQLAgent:` / `SQLAgent$<INSTANCE>:`.
SSAS publishes `MSAS<n>:` / `MSOLAP$<INSTANCE>:`.

### Implementation note: use PDH, not WMI

`PerformanceDepthCollector` reads `Win32_PerfFormattedData_*` through WMI. For
SQL that is the wrong door: WMI mangles the instance name into the class name
(`MSSQL$FOO:Buffer Manager` becomes a `Win32_PerfRawData_MSSQLFOO_MSSQLFOO*`
class), so the collector would have to guess class names per instance.
`System.Diagnostics.PerformanceCounterCategory` / `PerformanceCounter` takes
the literal object name with no mangling and enumerates categories, which also
gives free instance discovery. Rate counters (`/sec`) need two samples or raw
delta math — budget for a short sampling window.

### Server-health counters worth collecting (per instance)

| Counter | Why it matters |
| --- | --- |
| `Memory Manager\Total Server Memory (KB)` vs `Target Server Memory (KB)` | Total well below Target = still warming or under external memory pressure |
| `Memory Manager\Memory Grants Pending` | Non-zero = queries queued waiting for workspace memory. Sustained non-zero is a real incident |
| `Buffer Manager\Page life expectancy` | Classic buffer-pool pressure signal; a cliff means the working set stopped fitting |
| `Buffer Manager\Buffer cache hit ratio` | Context for PLE |
| `Buffer Manager\Free list stalls/sec`, `Lazy writes/sec`, `Checkpoint pages/sec` | Buffer pool churn |
| `General Statistics\User Connections` | Capacity and connection-leak trending |
| `General Statistics\Processes blocked` | **Highest-value single counter.** Sustained non-zero is user-visible blocking |
| `General Statistics\Logins/sec`, `Logouts/sec` | Connection-pool thrash (logins/sec ≈ batch requests/sec means pooling is broken) |
| `SQL Statistics\Batch Requests/sec` | The workload volume baseline everything else is read against |
| `SQL Statistics\SQL Compilations/sec`, `SQL Re-Compilations/sec` | Compile ratio > ~10% of batches = plan cache problem |
| `Locks(_Total)\Lock Waits/sec`, `Lock Wait Time (ms)`, `Average Wait Time (ms)` | Contention trend |
| `Locks(_Total)\Number of Deadlocks/sec` | Deadlock rate, credential-free |
| `Locks(_Total)\Lock Timeouts/sec` | Timeouts users actually see |
| `Access Methods\Full Scans/sec`, `Page Splits/sec`, `Forwarded Records/sec` | Index-health trend |
| `Latches\Average Latch Wait Time (ms)` | Internal contention |
| `Transactions\Longest Transaction Running Time` | Open-transaction age — blocks log truncation |
| `Transactions\Free Space in tempdb (KB)`, `Version Store Size (KB)` | tempdb pressure |
| `Databases(_Total)\Transactions/sec`, `Log Growths`, `Log Bytes Flushed/sec`, `Log Flush Wait Time` | Write path health |
| `Availability Replica\*`, `Database Replica\*` | AG log send queue / redo queue — only present when AGs exist |

### SQL Agent counters (credential-free job health)

`SQLAgent:Jobs` exposes `Failed jobs`, `Successful jobs`, `Active jobs`,
`Queued jobs`, and `Job success rate`. This gets job-failure visibility with no
SQL login, which is normally a Tier 3 item. Caveat: these are counted from
Agent process start, so they reset on restart — they are "failures since Agent
started", not "failures today". Treat as a delta/rate, not a level.

The `SQLAgent:` / `SQLAgent$<INSTANCE>:` counter categories stay registered
while the Agent service is stopped — the on-host probe listed them for two
instances whose Agents were stopped. Category existence therefore cannot stand
in for "the Agent is running". The collector reads Agent counters only for
instances whose Agent service is `Running` (`SqlServerCounterPolicy.ShouldRead`)
and reports them as unavailable (`-1`) otherwise.

### Per-database counters and cardinality

`SQLServer:Databases(<dbname>)` has a full counter set **per database**:
`Percent Log Used`, `Log File(s) Used Size (KB)`, `Data File(s) Size (KB)`,
`Active Transactions`, `Transactions/sec`, `Log Growths`, `Log Shrinks`,
`Backup/Restore Throughput/sec`.

`Percent Log Used` is genuinely valuable — a log filling toward 100% is a
production outage in progress. But per-database counters are the cardinality
trap this project's collector philosophy warns about: an instance with 200
databases times 6 counters times N instances is not something to push into RRD
blindly.

Recommended shape, matching how Horizon pools are handled in overlay `0.6.30`:

- RRD: `_Total` and a small fixed set of derived aggregates only
  (`databases_total`, `databases_online`, `databases_not_online`,
  `max_percent_log_used`, `databases_log_over_threshold`).
- Application metrics: flatten only databases that cross a threshold, as
  `sql_db_<measure>:<sanitized_db_name>`, so alert rules can target one
  database — the exact mechanism `horizon_pool_<measure>:<key>` already uses.
- Config: `maxDatabases` cap plus include/exclude lists, defaulting to a
  bounded number.

## Tier 2 — Other credential-free local sources

| Source | What it yields |
| --- | --- |
| Registry `Instance Names\SQL` | Authoritative instance list and `MSSQL<n>.<INSTANCE>` key mapping — better discovery than service-name pattern matching |
| Registry `<key>\Setup` | `Edition`, `PatchLevel`, `Version`, `Collation`, `SQLPath`, `SQLDataRoot` — real product identity, patch currency |
| Registry `<key>\MSSQLServer\SuperSocketNetLib\Tcp\IPAll` | Correct per-instance `TcpPort` / `TcpDynamicPorts`, and whether TCP is enabled at all |
| Registry `<key>\MSSQLServer\Parameters` (`SQLArg*`) | Startup parameters: `-e` errorlog path, `-d`/`-l` master paths, trace flags (`-T`) |
| Registry `<key>\MSSQLServer` | `LoginMode` (Windows-only vs Mixed), `AuditLevel`, default data/log/backup directories |
| SQL Browser SSRP (UDP 1434, localhost) | Instance list with resolved TCP port, version, clustered flag — **verified working remotely against the test host**; the same query to loopback is a one-packet local check |
| `Win32_Service` + `Win32_Process` | Instance↔PID↔service-account mapping, per-instance CPU/memory/handles/threads |
| `Get-NetTCPConnection` / `GetActiveTcpListeners` filtered by sqlservr PID | Actual listening endpoints per instance, no port allowlist |
| ERRORLOG file (path from `-e`) | Severity 16–25 events, `823`/`824`/`825` IO errors, `9002` log full, `1105` filegroup full, deadlock notices, "I/O requests taking longer than 15 seconds", backup completion lines, login failures. Bounded tail + pattern counts, never raw log shipping |
| Application event log, providers `MSSQL*` / `SQLSERVERAGENT` / `SQLBrowser` | Counts by source/ID/severity. SQLSERVERAGENT event 208 is job-failure |
| Data/log/backup volume free space | Cross-referenced from the registry default paths against the existing disk collector |

Everything in this table is readable by the service account the agent already
runs under, needs no SQL login, and matches the stated local-only posture.

## Tier 3 — DMV queries (needs a SQL connection)

This is where the metrics a DBA actually asks for live. It needs a design
decision first, because it needs a connection.

### What becomes available

| Query area | Metric |
| --- | --- |
| `msdb.dbo.backupset` | **Last full / differential / log backup age per database.** The most-requested SQL monitoring metric there is, and it is unreachable in every tier above |
| `msdb.dbo.sysjobs` + `sysjobhistory` + `sysjobactivity` | Per-job last outcome, duration, failure reason, jobs currently running past expected duration, jobs not run when scheduled |
| `sys.databases` | `state_desc` (ONLINE / SUSPECT / RECOVERY_PENDING / OFFLINE), `recovery_model_desc`, `log_reuse_wait_desc` (**tells you *why* a log will not truncate**), `is_read_only`, `user_access_desc`, `compatibility_level` |
| `DBCC DBINFO` → `dbi_dbccLastKnownGood` | **Days since last clean CHECKDB per database.** Corruption you have not looked for |
| `sys.dm_io_virtual_file_stats` | Per-file read/write latency (`io_stall_read_ms` / `num_of_reads`) — real storage latency as SQL experiences it, far better than the host disk counters |
| `sys.dm_os_wait_stats` | Top wait types — the standard "what is this server waiting on" answer |
| `sys.dm_exec_requests` + `sys.dm_os_waiting_tasks` | Blocking chain head, blocked session count, longest block duration |
| `sys.dm_tran_active_transactions` | Oldest open transaction age |
| `sys.dm_hadr_database_replica_states` | AG `synchronization_health_desc`, log send queue, redo queue size, estimated failover data loss |
| `sys.configurations` | `max server memory`, `MAXDOP`, `cost threshold for parallelism`, `optimize for ad hoc workloads` — config drift |
| `sys.dm_os_sys_info` | `sqlserver_start_time` — real engine uptime, distinct from Windows uptime |
| `sys.dm_os_volume_stats` | Free space on each volume holding a database file |
| `system_health` extended event session | Deadlock graphs and long-IO events with no trace flags, via `sys.fn_xe_file_target_read_file` |

### The credential question

Two routes, and the first may cost nothing:

**Route A — integrated auth as the agent's own identity.** The agent runs as
LocalSystem. SQL Server Setup provisions `NT AUTHORITY\SYSTEM` as a sysadmin
login on the Database Engine, so a local shared-memory connection
(`Server=lpc:<host>[\<instance>];Integrated Security=SSPI`) may succeed with
no stored credential whatsoever. **This must be verified per host, not
assumed** — the login can be removed by hardening, and Route A is not
available if the agent is ever reconfigured to run as a non-SYSTEM account.
Section 12 of `probe-sql-sources.ps1` tests exactly this; it only answers the
question when the script is run as SYSTEM.

If Route A works, sysadmin is also more rights than this should take. The
collector should connect, then operate under the assumption it may be
restricted later, and should never require more than:
`CONNECT SQL`, `VIEW SERVER STATE`, `VIEW ANY DEFINITION`, and read on
`msdb` (`SQLAgentReaderRole` + `db_datareader`).

**Route B — explicit least-privilege login.** Follow the Horizon API precedent
exactly: `mode: "disabled"` by default, a DPAPI `LocalMachine`-scoped
credential file written by a provisioning command, and a documented
`CREATE LOGIN` / `GRANT VIEW SERVER STATE` snippet in the docs.
`HorizonApiCredentialStore.cs` and `HorizonApiConfig` are the templates.

Either way this tier should be **opt-in and off by default**, exactly like
`HorizonApiConfig.Mode = "disabled"`, so the default install keeps today's
credential-free posture.

### Guardrails for Tier 3

- Read-only queries only; no `DBCC` that writes, no `sp_` that changes state.
  (`DBCC DBINFO WITH TABLERESULTS` is read-only but needs sysadmin — gate it
  separately and degrade gracefully when permission is absent.)
- Per-query timeout well under the collector timeout; one slow DMV must not
  stall the agent.
- Never emit query text, parameter values, login names, or object names beyond
  database names — query text can contain data.
- `sys.dm_os_wait_stats` and `sys.dm_io_virtual_file_stats` are cumulative
  since restart; emit deltas or they are meaningless after week one.
- Cap database enumeration the same way Tier 1 caps per-database counters.

## Suggested implementation order

1. ~~**Fix the existing collector** (defects 1–5).~~ **Done.** Per-instance
   ports from the registry, instance↔PID mapping, SSAS/SSRS/Full-Text as their
   own rows, SQL Agent expectedness, registry edition/patch level. No new
   credential surface and no new dependency.
2. ~~**Tier 1 server-level counters** via PDH into a new
   `windows_agent_sql_server_performance` section.~~ **Done.** One row per
   instance, `_Total` where the object is instanced, read through
   `PerformanceCounterCategory` / `PerformanceCounter`. New
   `windows-agent-sql-server-performance` RRD family and graph, per-instance
   `sql_perf_<measure>:<key>` application metrics for alert targeting, and
   non-alerting by default. A counter or object that is not present is reported
   as `-1`, never as a measured zero. **Counter availability is still unverified
   on a real SQL host** — the list below is product knowledge, and the collector
   is written to degrade rather than to assume it.
3. **Tier 2 ERRORLOG and event-log evidence** into
   `windows_agent_sql_server_evidence` — bounded pattern counts, no raw log.
4. **Tier 1 per-database counters**, gated and capped, with the
   `sql_db_<measure>:<key>` application-metric flattening for alert targeting.
5. **Tier 3**, opt-in, starting with backup age and job outcome, because those
   are the two things operators ask for first and neither is reachable without
   a connection.

## On-host probe results

`probe-sql-sources.ps1` ran once on the multi-instance SQL Server 2017
Standard test host (one default instance and two named instances), elevated,
as an administrator account. Observed:

- **Registry.** `Instance Names\SQL` listed all three instances. `Setup`
  carried edition, patch level and version; `Tcp\IPAll` gave a static
  `TcpPort` for the default instance and only `TcpDynamicPorts` for the two
  named instances. The SQL Browser (SSRP) response and the sockets actually
  owned by each `sqlservr.exe` PID matched those registry values exactly. This
  confirms the inputs the defect-1 port fix reads.
- **Process mapping.** Each engine service's PID matched a `sqlservr.exe
  -s<INSTANCE>` process. The one unowned `sqlservr.exe` was a per-user LocalDB
  instance (see defect 2).
- **Performance counters.** Every counter object Tier 1 reads exists for all
  three instances under the literal `SQLServer:` and `MSSQL$<INSTANCE>:` names,
  plus `SQLAgent:` / `SQLAgent$<INSTANCE>:Jobs`. 19 of the Tier 1 counters were
  sampled by exact name through PDH (`Get-Counter`) and returned values on all
  three instances. The remaining Tier 1 counters were not sampled; their
  objects exist, but their counter names are still unconfirmed.
- **Agent counter categories outlive the Agent.** See "SQL Agent counters".
- **Memory used vs target.** Two idle instances sat at roughly 5–8% of their
  target server memory with no other sign of pressure. That is why
  `memory_used_percent` is reported for context only and never raises
  `memory_pressure`.
- **Per-database cardinality.** 10, 11 and 30 database counter instances
  (including `_Total`) across the three instances — manageable here, but it
  confirms per-database counters need the cap described above.
- **ERRORLOG.** The `-e` startup parameter located every instance's
  ERRORLOG, on different drives per instance, and all were readable. The
  default instance's ERRORLOG was about 300 MB, so the Tier 2 reader must tail
  from the end of the file rather than scan it. The log carried backup
  completion and login-failure lines. On two instances, the newest backup line
  was several months old while their SQL Agents were stopped — the exact gap
  that backup age (Tier 3) and Agent expectedness exist to surface. This is an
  observation about one host, not a product fault.
- **Event log.** SQL providers logged only informational backup events in the
  last 24 hours. No `SQLSERVERAGENT` events in 7 days, which does not mean no
  job failures: the Agent writes to the event log only when a job is
  configured to.
- **LocalSystem SQL access (section 12): not answered.** The probe ran as an
  administrator, whose login the instances rejected. Whether
  `NT AUTHORITY\SYSTEM` can connect still needs a run as LocalSystem.

## Verification status

| Claim | How checked |
| --- | --- |
| Current collector is service-inventory only | Read `SqlServerCollector.cs` in full |
| Overlay has 2 RRD datasets and 1 alert for SQL | Read `windows_agent.inc.php` RRD def and `docs/suggested-alerts.md` |
| `listener_ports` is identical across instances and wrong for named ones | Live agent payload from the test host compared against an SSRP query to its SQL Browser |
| Named instances use dynamic ports (56217 / 50757) | SSRP response from the test host |
| Four `sqlservr.exe` processes vs three engine services | Live agent payload (`windows_agent_performance_processes` and `windows_agent_services`) |
| SQL Agent stopped does not raise `health_issues` | Live payload: two Agents `Stopped`, summary `state=ok health_issues=0` |
| SSAS / Launchpad / Full-Text present but absent from instance rows | Live payload service list vs `windows_agent_sql_server_instances` |
| Registry contents (instances, edition, patch level, TCP ports) | On-host probe, cross-checked against the SSRP response and the per-PID listening sockets |
| Counter objects exist under the literal PDH names | On-host probe: category listing for all three instances |
| 19 Tier 1 counters return values by exact name | On-host probe: `Get-Counter` samples on all three instances |
| `SQLAgent` counter categories exist while the Agent is stopped | On-host probe: categories listed for two instances whose Agent services were stopped |
| ERRORLOG located and readable for every instance | On-host probe: path from the `-e` startup parameter, pattern matches returned |
| LocalSystem can connect to SQL with integrated auth | **Not verified.** The probe ran as an administrator, not as LocalSystem |
| The Tier 1 counter collector builds, passes unit tests, and passes overlay fixtures | `dotnet build` 0 warnings / 0 errors; 91 agent tests pass (exit 0); `run-parser-fixtures`, `run-app-page-fixtures`, `run-horizon-central-tests` all exit 0; 71/71 overlay PHP files lint clean |
| The Tier 1 counter collector reads a real counter | **Partly verified.** The probe sampled 19 of the Tier 1 counters through PDH by the names the collector uses. The collector's own `SqlServerCounterReader` has not run on a host, and the remaining counter names are unconfirmed |
| SQL Agent counters are gated on the Agent service state | `SqlServerCounterPolicy.ShouldRead` unit test; 92 agent tests pass (exit 0) |
| The defect fixes build, pass unit tests, and pass overlay fixtures | `dotnet build` 0 warnings / 0 errors; 77 agent tests pass (exit 0); `run-parser-fixtures`, `run-app-page-fixtures`, `run-horizon-central-tests` all exit 0; 70/70 overlay PHP files lint clean |
| The defect fixes produce correct output on a real SQL host | **Inputs verified, collector not run.** The probe confirmed the registry values, SSRP ports and PID mapping the collector reads. The collector itself has not been built into an MSI or run on a host |

The DMV list and the unsampled counter names above are still from product
knowledge. Treat Tier 2 and Tier 3 as unconfirmed until something reads them on
a host.
