# SQL Server Role Tab — Research and Design Proposal

Date: 2026-10-27. Status: **existing-data tab implemented in overlay `0.6.32`; agent additions remain proposals**.
Source baseline: agent 0.6.17 / overlay 0.6.31, commit `3f5e91c`.

The subsequent [qualified-metric/control batch](sql-qualified-metrics.md) adds
existing-data filters and shared observation qualification in overlay `0.6.32`.
Its contract is implemented; optional database-query collection remains proposed.

## Recommendation

Promote SQL Server to an automatically detected first-level tab, following the
Active Directory, FactoryTalk and Horizon contract. Start with the existing
credential-free telemetry. Add an independently optional local SQL-query mode
for database, log, backup and job evidence in a later agent change.

The page should answer: **Which instance needs attention, why, what evidence is
missing, and what should the operator check next?** It should not imply that
Windows service health proves database or application availability.

The collection priorities below remain proposals. The supplied ChatGPT
report was treated as a list of hypotheses. Its private screenshot observations
are omitted. Primary documentation and this repository's code support the
decisions below; collection overhead and restricted-login behavior still need
implementation-time validation.

The follow-on [alerting and details design](sql-server-alerting-and-details-design.md)
investigates numeric-metric validity, policy and incident lifecycle, optional
collection batches and instance selectors/expandable evidence. It remains a
proposal; it does not add agent queries or enable alerting.

Microsoft recommends comparing performance against a measured workload baseline.
As a comparable-product cross-check, Redgate Monitor distinguishes database
unavailability, blocking, backup overdue, job failure and collection failure,
with settings scoped to particular objects. That supports prioritizing these
operator questions and explicit coverage; the proposed ordering is our design
judgment. Alert defaults from another product are not adopted here.
([Microsoft baselines][s-baseline], [Redgate alert reference][s-comparison])

## Proposed page

Use the existing shared summary/disclosure components and detected-role ordering.
Add **SQL Server** after the existing first-level role tabs and before generic
host tabs. Keep a compact role-inventory link in Roles & Workloads; move SQL
details and SQL attention to the new tab to avoid contradictory copies.

```text
SQL Server
  Verdict + next check
  Collected age | instance/counter coverage | database-query coverage
  Five decision tiles using today's data
  Attention: named instance, reason, evidence, next check
  Instances: issue-first compact rows; select an instance for evidence
  Trends: separate units and identify host aggregation
  Details: services/components, performance, inventory, collection diagnostics
```

For example: “One instance has blocked processes; SQL database queries are not
configured.” A clean result is “No issues observed in service/listener and
readable counter checks,” qualified by coverage. Never say “All databases
healthy” from this data. A failed collection is unknown, rather than “Not
detected.” Current SQL app data is replaced each poll: with no remaining engine
evidence, Overview/Role inventory report collection failure and the SQL tab is
omitted. If explicit instance rows remain, show them with incomplete coverage.
Retaining a last-good SQL snapshot would require separate persistence design.

### Forefront selection with the current agent

| Tile | Display and decision | Limits |
| --- | --- | --- |
| Engine services | Running engines out of discovered engines; name any engine down and distinguish expectedness. Open its evidence first. | Windows service state does not establish database availability. |
| Local TCP listeners | Name any running instance with enabled TCP but no configured port listening; show Browser requirement as context. | A host listener is not an authenticated query or remote client connection test. TCP-disabled instances need an explicit not-applicable reason. |
| Scheduled work | Expected SQL Agent services stopped, plus newly observed failed-job counter delta where available. Direct the operator to the affected instance's job evidence. | Manual/disabled/missing Agent is explained, not automatically faulty. No readable delta means unknown, not zero successes or zero failures. |
| Blocking now | Current blocked-process count per affected instance; use the configured condition and name its source. Investigate blocking before tuning blindly. | A point sample; no assertion that blocking was absent throughout the poll interval. |
| Query memory queue | Pending workspace-memory grants per affected instance. Investigate memory-consuming workload and server/OS context. | A sample, not proof of sustained pressure or a recommendation to increase memory. |

Coverage and age sit beside the verdict, rather than consuming a decorative
sixth tile. Existing compile/deadlock/buffer-pressure flags still appear in
named attention when raised. Their omission from tiles must not suppress them.
Configured thresholds are product policy, not Microsoft universal health limits.

### With optional database-query evidence

Keep a maximum of six tiles: **engine/local query status, database exceptions,
log/capacity exceptions, backup policy/evidence, scheduled jobs, blocking/grants**.
Coverage remains separate. Replace inferred scheduled-work details with actual
job outcomes where the query capability succeeds. Show a conditional HA panel
only when detected/configured; HA exceptions also enter the common attention
list. Unsupported or unconfigured capabilities remain explicitly labeled.

| New forefront signal | Operator question and essential context |
| --- | --- |
| Database exceptions | Is an expected database unavailable, suspect, recovery pending, or unexpectedly restricted? Show state, expectedness and collection visibility. Restoring/standby/read-only may be intended. |
| Log/capacity exceptions | Which database is approaching its configured limit or backing-volume constraint? Show used/allocated bytes, ability to grow, volume headroom and log-reuse reason together. |
| Backup evidence | Is the last recorded successful backup within this database's configured policy? Separate full/differential/log, copy-only and external-backup coverage. With no policy, show age and “policy not configured.” |
| Scheduled jobs | Did a monitored job finish successfully; is it running beyond its configured expectation or overdue? Identify the job and last whole-job result, with current activity separately. |

Do not expose a universal “backup health” badge. A native backup record proves a
recorded successful operation, not available media, a valid restore chain or a
tested restore. Copy-only fulls are not differential bases; SIMPLE recovery has
no log backups. Some VSS copy-only operations do not update SQL backup history.
AG/external-backup ownership must be known before classifying absence.
([backupset][s-backup], [copy-only][s-copy], [recovery models][s-recovery],
[SQL Writer][s-vss])

## Existing data: promote, disclose or deliberately defer

The parser retains all four SQL row sets in application data. Most additional
detail needs overlay work only. This is a SQL-scoped audit, not a claim that the
whole application's Phase 3 audit is complete.

| Already collected/stored | Current surface | Proposed treatment |
| --- | --- | --- |
| Instances, edition/patch, engine/Agent state, configured ports, listener state, PID | SQL inventory under Roles & Workloads | Promote exceptions; move inventory to SQL details. Describe registry inventory as inventory. |
| Agent/component severity, expectedness, reason, impact; Browser requirement; LocalDB vs unmapped engines | Some expectedness/reasons visible; other context absent | Name instances/services in attention. Explain stopped manual Agent and conditional Browser. Show LocalDB separately, without treating it as an engine fault. |
| Blocked processes, pending grants, PLE, connections, batches, compilation %, total/target memory %, deadlock rate, failed-job delta | Very wide performance table | Promote blocking/grants and qualified job observations. Keep PLE, throughput, connections, compilation and memory ratio as evidence/trends. |
| `compile_pressure`, `deadlock_pressure`, other pressure flags | Included in pressure count; no specific compile/deadlock attention | Give each active reason a named attention row. Use one effective verdict for header, badge and attention. |
| `agent_counters`, `agent_jobs_delta_available`, `agent_jobs_reset`, read/missing counts | Partial coverage visible; reset/applicability largely hidden | Show baseline/reset/service-stopped reasons and partial coverage. The legacy pressure count of zero cannot establish that an unavailable row is healthy. |
| `longest_transaction_seconds`, `tempdb_free_space_kb`, `version_store_size_kb` | Stored raw; absent from the app-page table | Add bounded instance evidence with scope labels. Microsoft limits this longest-transaction counter to RCSI activity; zero cannot rule out other long transactions. Free tempdb space alone cannot establish fullness without an allocation denominator. ([Transactions object][s-transactions]) |
| Raw total/target memory, cache hit ratio, free-list stalls, lazy writes, checkpoint pages | Stored raw; mostly absent from page | Secondary buffer/memory evidence; no new primary health tiles. Memory acquired for caching is normal behavior. ([memory guidance][s-memory]) |
| Lock/latch wait metrics, lock timeouts, transactions, log growths/flush counters | Stored raw; some host aggregates in the performance graph | Secondary evidence after verifying native counter types. Log Growths is a total, not “growths this poll”; Lock Wait Time is milliseconds accumulated in the last second, not average latency. Log Flush Wait Time must not be substituted for Log Flush Write Time. ([Locks][s-locks], [Databases object][s-dbcounters]) |
| Agent active/queued jobs, successful-job delta, success-rate counter | Stored raw; absent from page table | Active/queued counts in job evidence. Success rate and successful delta are context, not proof every expected job ran. |
| Optional AG send/redo queue counters | Stored raw; absent from page | Conditional evidence, not a complete HA-health verdict. Missing optional counters do not prove AG absence. |
| Existing SQL instance, health and performance RRD families | Graph definitions exist; no inline SQL trends on the app page | Add useful SQL trend views intentionally. Reuse stored DS with separate graph definitions by unit; preserve existing RRD layouts. |

Demote PID, ports, edition, patch, connection count, batch rate, cache hit ratio,
PLE and memory/target percentage from the decision layer. They explain incidents
or workload; most have no universal good/bad range. Do not replace the five
tiles with inventory counts or a single unexplained pressure score.

The current mixed performance graph places seconds, percentages, counts, rates
and coverage on one “Value” axis. Split views into blocked processes, pending
grants, workload rates, PLE seconds and memory context. Label aggregation exactly:
current host series include maximum blocked/grants, minimum PLE and sums of
readable connection/batch values. These are not per-instance historical graphs;
an instance selector cannot reconstruct history that was never stored.
Identify current contributing instances and partial coverage; historical
contributors are not recorded for each old RRD point. Do not label a partial
sum as a complete host total.
Exclude coverage/state series from workload axes. Keep missing values visually
distinct; any future normalization of the legacy `-1` job sentinel must be
deliberate and tested, without rewriting historical data or the RRD schema.

## Recommended agent additions, in order

| Priority | Bounded collection | Why and source |
| --- | --- | --- |
| P0: capability contract | Per-instance/family result, reason, observation time, duration, visible/selected/returned counts, truncation and baseline/reset status | Essential to interpret any deeper metric. Keep service/PDH usable if SQL connection fails. |
| P0: durable counter observations | Raw cumulative Agent failure/success and deadlock/timeout counts with source process-start/reset identity; reset-aware poller deltas and observation deduplication | Fix the cadence gap before calling these incident totals. Preserve existing sampled fields; add a versioned additive contract. No SQL login is required to evaluate a PDH raw-counter approach. Prove raw counter types and resets before implementation. |
| P1a: databases and log capacity | Database state/access/recovery/standby/reuse reason; combined log used/total bytes; file allocation/growth limits and backing-volume free bytes | Finds unavailable databases and capacity risks directly. Do not confuse allocated bytes with used space. ([databases][s-db], [log usage][s-log], [files][s-files], [volumes][s-volumes]) |
| P1b: backup observations | Last successful full/differential/log records, finish time, copy-only/snapshot/damaged flags, policy coverage | High operational value; emit evidence and qualify its meaning as above. Query per selected database/current identity; avoid stale history attributed after rename/recreation. ([backupset][s-backup]) |
| P1c: jobs | Selected jobs' enabled state, last whole-job outcome/time/duration, current session activity, configured overdue/runtime expectations | Retained history covers gaps that PDH deltas cannot. Use `step_id=0` for whole-job result; don't count a retried/handled step failure twice. Select current Agent session for activity. ([job history][s-jobs], [whole-job rows][s-jobsummary], [activity][s-activity]) |
| P1 alternative: credential-free log capacity | Capped selected-database `Percent Log Used`, log allocated/used KB, instance maximum with affected database and assessed count | Useful alongside query-mode design for sites that cannot grant SQL access. This is a narrow PDH subset, not the old broad per-database expansion. It cannot establish database state, reuse reason, growth limits or backup compliance. ([Databases object][s-dbcounters]) |
| P2: I/O, tempdb, transactions | Reset-aware file read/write count, bytes and stall deltas; tempdb current files/allocated/free/user/internal/version-store space; scoped current request/blocking/oldest transaction evidence | Explains slow applications after availability/capacity/protection are visible. ([I/O][s-io], [tempdb][s-tempdb], [requests][s-requests]) |
| P2: integrity timestamp | `DATABASEPROPERTYEX(database, 'LastGoodCheckDbTime')`, where supported and visible | Read recorded successful CHECKDB time; do not execute CHECKDB. Supported in SQL 2016 SP2, SQL 2017 CU9 and later listed releases. Sentinel 1900 is “no recorded run”; NULL is unknown/error/visibility, not corruption. ([property][s-checkdb]) |
| P3: bounded incidents / waits | Allowlisted SQL/Windows event IDs and categories; retained deadlock occurrence counts/times; selected wait-category deltas | Complements snapshots. Event sources wrap; wait stats may be manually cleared. No raw messages, SQL text or deadlock XML in telemetry. ([system_health][s-events], [waits][s-waits]) |
| Conditional: HA | Local replica role, suspension/synchronization, send/redo queues, observation scope | Promote earlier when HA is actually in scope. Local/cached observations are not a global cluster truth or a promise of failover RPO. ([replica states][s-ha]) |

Prioritize P0 correctness, then P1 database/log/backup/job evidence ahead of the older plan's broad
per-database PDH expansion and ERRORLOG-first sequence. That ordering is a design
judgment based on operator decisions, not a vendor requirement. If a site cannot
enable queries, bounded Windows event evidence remains a useful independent
path; it cannot substitute for database state or backup policy evidence. The
narrow PDH log-capacity alternative can proceed independently where appropriate.

Defer Query Store regressions, query text/plans, unbounded top-query lists,
replication/Broker internals and automatic tuning. They add scope, sensitive
content and substantial interpretation beyond this role tab. Discover feature
presence and add focused extensions only when there is an operational need.

## Collection and interpretation contract

- Keep queries off by default. First proposed mode uses local integrated auth
  under the configured service identity, with no stored SQL password, remote
  target scanning or automatic grants. A successful local query is labeled local;
  it does not prove remote application connectivity. Do not assume LocalSystem
  has a SQL login, sysadmin, or the same rights on every instance. Microsoft's
  provisioning guidance distinguishes SQL per-service SIDs from LocalSystem
  grants for particular HA installations; it does not support the older survey's
  universal “SYSTEM is provisioned as sysadmin” assertion. A service SID scoped
  to this agent is a candidate for a later explicitly provisioned grant, subject
  to service-token and restricted-access tests. ([service permissions][s-services])
- Design permissions per capability and SQL version, then validate with a
  restricted principal. Log-space and I/O DMVs require `VIEW SERVER STATE` on
  SQL Server 2019 and earlier, and `VIEW SERVER PERFORMANCE STATE` on 2022+.
  `sys.databases` can hide offline rows from low-privileged users; absence is not
  deletion. Do not grant database-changing rights just to claim complete
  monitoring. Prefer explicit monitored database expectations and honest partial
  visibility. ([log usage][s-log], [I/O][s-io], [databases][s-db])
- Do not prescribe `sysadmin`, blanket `msdb.db_datareader`, or
  `SQLAgentReaderRole` as a universal read-only monitoring grant. ReaderRole
  inherits abilities to create/modify/run owned jobs. Design reviewed narrow
  SELECT permissions or a constrained read-only module and prove behavior on
  supported versions. No provisioning changes in this research.
  ([Agent role permissions][s-jobroles])
- Separate capability state from observed severity. Distinguish measured zero,
  disabled/not configured, not applicable, permission denied, unsupported,
  timeout/error, partial/truncated, stale and awaiting a second sample. Never
  replace missing data with numeric zero or silently omit it from denominators.
- Bound every query, family, instance and whole refresh. Use cancellation and
  strict row/byte/time budgets; fixed queries only. Caps/allowlists must expose
  truncation and retain exception counts for the successfully assessed scope.
  Choose defaults after measurement; no performance budget is validated here.
- Use stable instance + database/job identity, safe encoding and collision-free
  new metric keys. Database ID alone can be reused; names can change. Keep
  bounded current evidence in application data; create historical series only
  for justified metrics. Do not create per-file/per-job RRDs by default.
  Existing application-data storage supports this separation. ([LibreNMS][s-librenms])
- Emit no SQL text, job step commands, raw error messages, login/client names,
  backup destinations or file paths. Names of selected databases/jobs may be
  operationally necessary on the private monitoring system; allow filtering or
  aliases. Never copy live rows into public fixtures/docs.
- Include observation UTC and interval length, source/clock context, engine
  startup/reset identity and first-baseline status. SQL history timestamps may
  be local/offset ambiguous; do not assume UTC or invent precision across DST.
- Current PDH rates use a shared short sample (default 1000 ms); they are not
  averages over the LibreNMS poll period. Current job deltas span agent refreshes,
  not necessarily polls, and can be missed or repeated through cached payloads.
  Durable histories need retention/rollover-aware cursors and deduplication.
- For cumulative DMV data use elapsed-time deltas and invalidate baselines on
  restart, counter decrease, identity change or reset. I/O latency is
  `delta(stall_ms) / delta(operations)`; zero operations means not applicable,
  not zero-ms fast storage. Wait statistics show completed waits, not a current
  blocking chain. DMV counter types/base counters must be honored.
  ([I/O][s-io], [waits][s-waits], [counter types][s-counters])

## Detection, verdict and visual acceptance

The collector attempts discovery when any recognized SQL component service
exists, but its summary sets `detected=1` only when engine instances exist.
Use that explicit engine evidence (and instance rows for compatible older
payloads) for the automatic tab. `RoleDetector` separately recognizes SQL
Browser/ReportServer prefixes; that broad inventory role is not the tab gate.
Keep component-only hosts in generic service/role evidence; consider a separate
explicitly configured component view later. Browser/VSS-writer/telemetry or
LocalDB alone must not create a misleading Database Engine tab. Disabled/error
collection must be distinguished from confirmed absence. Existing SQL rows are
not retained across failed polls; the implemented fallback is an unknown SQL
collection state in Overview/Role inventory, without inventing engine presence
from the broad role flag. Last-good retention remains separate work.

Use one effective SQL verdict derived from fresh service evidence, all existing
pressure reasons, listener/unmapped-process conditions, job observations and capability coverage. Keep severity and
coverage separate: known bad evidence remains attention-worthy despite another
unknown capability; missing evidence prevents an unqualified all-clear. Avoid
double-counting an engine outage as an additional Agent outage. No new alert
rules are created or enabled by the presentation.

Before implementing, cover engine-only, multi-instance, components-only,
supporting-service-only, absent/disabled/error/stale collector, partially readable
counters, stopped/manual/Express Agent, first/reset job sample and colliding
names. For query mode add restricted visibility, intentional restoring/standby,
log growth disabled/full volume, history purge, SIMPLE/copy-only/external backup,
current vs old Agent session and failover/restart fixtures.

Rendered acceptance: consistent header/attention; issue-first instance names and
next checks visible without scrolling a wide table; small-screen stacked key
facts or a clear scroll cue; desktop/mobile and actual LibreNMS light/dark themes;
units and readable graph legends; no hidden SQL problems in generic role totals.
Do not infer production dark-theme behavior from a fixture lacking its CSS.

## Evidence and next implementation boundary

Code inspected: `RoleDetector`, `SqlServerCollector`, `SqlServerCounterReader`,
`SqlServerCounterPolicy`, the Unix-agent SQL parser/storage/RRD definitions,
the application-page SQL/role-tab renderers and SQL graph definitions. Existing
rendered-review findings inform the acceptance criteria. The existing-data tab
now has local fixture rendering and browser/graph checks; live integration with
the production LibreNMS shell remains a rollout check. No SQL query/permission/overhead validation was
performed for this research.

Implemented boundary: an overlay-only SQL tab using the existing payload,
shared verdict and unit-separated trends. The new views read the same RRD DS,
mask negative unavailable/consolidated incomplete intervals, and lazy-load
images. Agent code and published versions are unchanged. Design and measure
the optional query capability separately before adding any collection.
The full cross-role Phase 3 audit remains separate; the SQL-scoped audit above
is sufficient input to select SQL content without claiming that audit finished.

Claude reviewed the source and challenged priorities/semantics as a read-only
partner. The reconciled proposal includes separate engine/listener tiles,
cadence-safe cumulative observations before query expansion, a narrow PDH log
capacity alternative, engine-summary detection and corrected permission/integrity
guidance. Primary-source verification was performed by Codex; peer agreement is
not runtime validation.

[s-db]: https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/sys-databases-transact-sql
[s-log]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-db-log-space-usage-transact-sql
[s-files]: https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/sys-master-files-transact-sql
[s-volumes]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-os-volume-stats-transact-sql
[s-backup]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/backupset-transact-sql
[s-copy]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/copy-only-backups-sql-server
[s-recovery]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/recovery-models-sql-server
[s-vss]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/sql-server-vss-writer-backup-guide
[s-jobs]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/dbo-sysjobhistory-transact-sql
[s-jobsummary]: https://learn.microsoft.com/en-us/sql/relational-databases/system-stored-procedures/sp-help-jobhistory-transact-sql
[s-activity]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/dbo-sysjobactivity-transact-sql
[s-jobroles]: https://learn.microsoft.com/en-us/ssms/agent/sql-server-agent-fixed-database-roles
[s-memory]: https://learn.microsoft.com/en-us/sql/relational-databases/performance-monitor/monitor-memory-usage
[s-io]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-io-virtual-file-stats-transact-sql
[s-tempdb]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-db-file-space-usage-transact-sql
[s-requests]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-exec-requests-transact-sql
[s-checkdb]: https://learn.microsoft.com/en-us/sql/t-sql/functions/databasepropertyex-transact-sql
[s-events]: https://learn.microsoft.com/en-us/sql/relational-databases/extended-events/use-the-system-health-session
[s-waits]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-os-wait-stats-transact-sql
[s-ha]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-hadr-database-replica-states-transact-sql
[s-counters]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-os-performance-counters-transact-sql
[s-librenms]: https://docs.librenms.org/Developing/Application-Notes/
[s-services]: https://learn.microsoft.com/en-us/sql/database-engine/configure-windows/configure-windows-service-accounts-and-permissions
[s-transactions]: https://learn.microsoft.com/en-us/sql/relational-databases/performance-monitor/sql-server-transactions-object
[s-locks]: https://learn.microsoft.com/en-us/sql/relational-databases/performance-monitor/sql-server-locks-object
[s-dbcounters]: https://learn.microsoft.com/en-us/sql/relational-databases/performance-monitor/sql-server-databases-object
[s-baseline]: https://learn.microsoft.com/en-us/sql/relational-databases/performance/establish-a-performance-baseline
[s-comparison]: https://documentation.red-gate.com/monitor/list-of-alerts-239667973.html
