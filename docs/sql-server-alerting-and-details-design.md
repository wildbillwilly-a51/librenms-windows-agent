# SQL Server alerting and application-page details

Date: 2026-10-28. Status: **investigation and proposed implementation order**.
Baseline: existing-data SQL tab at `27c824e`; published versions remain in
`CURRENT-STATE.md`. This document adds no collectors, permissions or alert rules.
It extends [the role-tab research](sql-server-role-tab-research.md).

Implementation update: the overlay-only foundation and existing-data selectors/
disclosures are now implemented in overlay `0.6.32`. See
[qualified metrics and controls](sql-qualified-metrics.md) for the actual
contract and validation boundary. The source findings below describe the
research baseline; the locked saver and shared projection address its data/
metric overwrite path. Distinct SQL sample history, new query capabilities,
monitoring policy/digest configuration, held incidents and alert activation
remain future batches.

## Recommendation

Add database availability, log capacity, actual job outcomes and backup evidence,
in that order for alert readiness. Collect backup evidence early, but enable
backup evaluation only after its ownership and per-database policy are known.
First reconcile the existing page/metric interpretation and establish collection
validity. Keep performance tuning indicators as context unless an operator
chooses a workload-specific policy. This ordering is design judgment, not a
vendor-prescribed monitoring standard.

Dropdowns can expose substantially more information without adding top-level
tabs. Use an instance selector and issue/all filter, with expandable evidence
sections. Filtering only changes the view: it cannot affect collection, alert
evaluation, or the global attention list. More disclosure cannot supply database,
backup or job evidence the current agent does not collect.

## What belongs where

| Information | App-page surface | Alert possibility and prerequisite | Collection |
| --- | --- | --- | --- |
| Expected engine/Agent stopped; enabled TCP lacks local listener | Visible attention and service evidence | Opt-in availability condition, expectedness/maintenance qualified; avoid duplicate Agent faults while engine is down | Existing services/registry/listeners; local listener is not a query test |
| Blocked processes and pending grants | Keep current decision tiles, named instances and sample time | Sustained sampled condition after validity/deduplication; a short sample does not prove continuous blocking | Existing PDH; optional bounded request evidence later |
| Database state and access | Database exceptions tile; database rows show state, expected state, recovery/read-only/standby context | Unexpected state of an explicitly monitored database; unavailable visibility is unknown | New optional local query capability, `sys.databases` [1] |
| Transaction-log occupancy and growth constraints | Capacity exceptions; used/allocated bytes, percentage, growth settings, backing-volume headroom and reuse reason | Per-database configured occupancy/headroom policy; reuse reason explains it, not an alert by itself | New query capability; narrow PDH capacity alternative [2], [3], [4] |
| Actual job result, active runtime, overdue execution | Scheduled-work tile and named job rows | Selected jobs: whole-job failed/canceled result; runtime/overdue only with explicit expectation | New bounded `msdb` history/activity capability [5], [6], [7] |
| Last recorded full/differential/log backup | Backup evidence/policy tile and per-database evidence | Age exceeds that database's configured policy, with source ownership, recovery model and grace period known | New bounded `msdb.backupset` capability [8] |
| I/O latency and throughput | Performance details and justified future trends | Baseline-specific sustained latency with sufficient I/O; not a universal millisecond threshold | Later reset-aware file deltas [9] |
| tempdb data/log consumption and version store | Capacity/performance evidence; promote qualified capacity exceptions | Requires a meaningful denominator and growth/headroom context | Existing absolute values are context; new file-space/log observations add the denominator [2], [10] |
| Recorded integrity-check time | Backup/integrity detail, with unsupported/no recorded run distinguished | Optional recorded-check recency policy; never infer corruption from missing time | Later `LastGoodCheckDbTime`; never run CHECKDB as a poll [11] |
| HA synchronization/queues/role | Conditional HA section; exceptions enter global attention | Only for configured HA topology/expected role; failover grace and local/cached visibility must be established | Separate later capability, not part of the first query batch |
| Edition/patch, ports, connections, batches, PLE, memory/target, compilation | Expandable identity/performance and existing unit-specific trends | Normally context; operator-defined baselines may justify rules | Already available; no universal PLE or cache-use health conclusion |

Database state is valuable first, but it still needs expectedness: restoring,
standby, read-only or offline can be deliberate. Restricted accounts can fail
to see offline databases. Explicit watched-object expectations and capability
coverage are required; an absent row is not proof of deletion or health. Do not
grant database-changing rights merely to make inventory appear complete. [1]

Log percent describes occupancy of allocated log, not remaining disk capacity.
Growth may be disabled, capped or constrained by a shared volume. Do not add
the same volume's free space once per file, or count shared headroom as reserved
for every database. Keep occupancy, growth constraints and headroom separate;
an exact usable-growth forecast is deferred. [2], [3], [4]

Backup policy must distinguish full/differential/log and copy-only, recovery
model, new-database grace, replica/external backup ownership and visible-history
coverage. SIMPLE has no log backups. A copy-only full cannot be a differential
base. Some VSS copy-only backups do not update SQL backup history. Recorded
successful backup evidence is not proof of media availability, a valid restore
chain or a tested restore. Missing local history with external ownership is
unverified coverage, not automatically an overdue backup. [8], [12], [13], [14]

For jobs, read whole-job outcomes (`step_id=0`) separately from current activity
in the current Agent session. Do not count a failed/retried step as another
failed whole job. Disabled, on-demand and event-driven jobs need explicit
expectations before an overdue rule is meaningful. Last outcome alone can miss
a failure followed by a success between polls; retain bounded occurrence
identity/count evidence if that alert behavior is required. [5], [6], [7]

## Research-baseline alert path: findings that affect implementation

The existing parser passes numeric fields to `update_application`; it already
exports `sql_perf_state`, blocked/grants/PLE/connections/batches/jobs/pressure
keys per sanitized instance name. Rich SQL rows live in application data.
Neither displaying a field nor graphing it makes a separate selectable metric.
See the [parser](../librenms-overlay/includes/polling/unix-agent/windows_agent.inc.php).

Confirmed source findings, not fixes in this investigation:

- The [page model](../librenms-overlay/includes/html/pages/device/apps/windows-agent/sql-server-model.inc.php)
  requires running/readable evidence and rejects reset job deltas. The legacy
  metric projection applies fewer guards, and pressure is clamped to zero on an
  unreadable row. Zero pressure alone cannot establish an all-clear.
- [ResolveDelta](../src/LibreNMS.WindowsAgent.Core/SqlServerCounterPolicy.cs)
  deliberately returns `Available=true`, `Reset=true`, value=current when a
  cumulative counter decreases. [PerformanceLine](../src/LibreNMS.WindowsAgent.Service/Collectors/SqlServerCollector.cs)
  reports reset if either success or failure counters reset. Thus reset and
  available can coexist; page/metric disagreement is real. Preserve the existing
  metric contract and add explicitly qualified fields rather than silently
  changing the published semantics.
- The page has a 15-minute freshness qualification, but these metrics have no
  equivalent observation-expiry field. Current sample intervals/cache refreshes
  are not the LibreNMS polling interval. Repeated cached observations must not
  count as multiple sustained-condition samples or new failures.
- Negative sentinels are below zero. A rule such as `PLE < limit` needs a
  measured/nonnegative guard; `pressure = 0` also needs readability/freshness.
  A warning state and a coverage gap are independent dimensions.
- Sanitizing punctuation can collide for future database/job names. Do not
  extend the legacy name scheme to those objects. Preserve old keys; introduce
  versioned collision-resistant identities and display-name mapping.

Upstream LibreNMS was inspected at commit
`337606a75465a8a8518b36a31e5fe44bd800087c` on 2026-10-28. This is a reference
implementation, not verification of the deployed installation:

- `update_application` sets its timestamp when called, casts metric values to
  float, updates `value_prev` only when a value changes, and deletes omitted
  metrics on a nonempty update. An empty metric update skips that work. Its
  app-state mapping does not evaluate SQL health. [15]
- The overlay's application-poller wrapper returns early without a cached
  Windows Agent section. Therefore old stored metric values can survive a
  missing payload; the application timestamp also cannot distinguish freshly
  collected data from a repeatedly served cached snapshot. [Local wrapper](../librenms-overlay/includes/polling/applications/windows-agent.inc.php)
- Application metrics have no per-row timestamps in the inspected model.
  `value_prev` is not a dependable previous-poll sample or event cursor. [15], [16]
- Rules can use custom SQL; notification operations control timing/delivery.
  Delaying a notification does not establish that new source samples were
  measured throughout that interval. [17], [18]
- The inspected alert evaluator recovers faults absent from the next matching
  result. A freshness predicate alone therefore can turn loss of evidence into
  a recovery. Entity handling also depends on returned identity columns and
  the installed core. Prove behavior before promising separate database/job
  acknowledgements. [19], [20]

## Proposed collection and policy ownership

Keep the Windows agent's service/PDH path usable without SQL access. Add an
independently disabled-by-default local query capability, using fixed bounded
queries through the framework provider rather than launching `sqlcmd` or loading
SQL PowerShell modules. Prototype `System.Data.SqlClient` with the existing
.NET Framework target. Use integrated Windows identity and local instance
endpoints; shared memory (`lpc:`) is a supported candidate. Do not depend on
Browser/remote TCP discovery or silently try remote targets. Validate provider,
protocol, encryption and certificate behavior on supported systems. [21]

Do not add credentials or grants automatically. The agent's service account
is not universally SQL sysadmin. For log/I/O/volume DMVs, documented permissions
change from `VIEW SERVER STATE` through SQL 2019 to `VIEW SERVER PERFORMANCE
STATE` on SQL 2022+. These grants are not sufficient proof of every other
capability. Inventory and `msdb` require their own visibility tests. [2], [4], [9]

Prefer reviewed narrow SELECT rights or a DBA-owned constrained read-only
module for the selected outputs. A signed-module design is a candidate to test,
not a validated provisioning recipe. `SQLAgentReaderRole` is not strictly
read-only: it inherits owned-job operations. Do not prescribe it or blanket
`msdb.db_datareader`/sysadmin as the monitoring default. [22]

The agent owns observation, limits and capability results. The overlay owns new
monitoring expectations and derives one evaluation shared by page and metrics.
Keep its operator-owned policy outside public source: per-object selection,
expected states, backup ownership/age/grace, job runtime/recency expectations,
thresholds and policy revision. Preserve existing agent pressure flags/thresholds
as their existing policy source. LibreNMS owns rule assignment, maintenance,
delivery and acknowledgements. Do not maintain conflicting alert thresholds in
the page, a second agent evaluator and manually copied rule constants.

Proposed policy storage is an operator-managed JSON file, initially
`/etc/librenms/windows-agent-sql-policy.json`, with a configurable path. Keep it
outside the staged overlay payload so reapply/upgrade cannot overwrite it.
Use a schema version, defaults and per-device/instance/object overrides keyed
to local monitoring identities. Validate types, bounds, duplicate identities and
unknown keys before activation. Read and validate one complete file per poll;
there is no implicit in-process or persisted last-good-policy fallback. An
unreadable/invalid policy stops new policy evaluation and surfaces a policy gap,
not a healthy result. Observation-only evidence remains available.

For distributed pollers, require the same file digest on every eligible node
against an operator-published expected digest in shared LibreNMS configuration.
This is a proposed new setting, not an existing feature. Missing/mismatched
nodes report a policy gap. Persist the accepted digest in `sql_monitoring` and
show its source/revision on the page. An authorized revision change resets
affected objects' policy streaks/evaluation baselines. Publish/distribute the
file before advancing the shared expected digest; test rollback and partial
rollout. This single-digest design deliberately accepts a transient policy gap
during rollout; use an explicit maintenance window or a configured gap-alert
dwell. It does not promise a gap-free handover or accept an implicit old revision.
Label existing pressure limits as agent policy. Rules consume qualified
outputs plus validity, without copying thresholds. Finalize this config schema
and validate reload/error/cross-node behavior before the alert pilot.

Each instance/capability needs a versioned observation envelope:

- Collision-resistant object identity plus display name/optional alias. Validate
  database rename, restore/recreation and ID reuse; use job GUID in its instance
  scope. Do not assume a database ID or name alone is durable identity.
- Sample/generation ID, observation UTC, source timezone/clock uncertainty,
  elapsed window, duration, engine/Agent start identity and reset/baseline state.
- Result/reason: measured, not configured, disabled, not applicable, unsupported,
  denied, timeout/error, partial/truncated, stale or awaiting baseline.
- Selected/expected, visible, assessed, returned and omitted counts. Unknown
  totals remain unknown; a restricted query cannot report inventory completeness.
- Fixed per-query, per-instance, whole-refresh, row and byte budgets. Bound work
  as well as output; a TOP limit does not guarantee a bounded history-table scan.
  Cancellation/timeout must release resources without blocking other collectors.
- No SQL text, job commands, raw errors, client/login names, backup destinations
  or file paths in telemetry. Selected object names remain optional/filterable.

For event-like counters, preserve additive raw cumulative values and generation
identity, deduplicate snapshots and detect resets. History readers need bounded
overlap/cursors, purge/gap detection and deduplication across restarts. The current
read-only polling protocol has no event acknowledgement; bounded retention
cannot guarantee delivery through an unlimited outage. Report gaps explicitly.

Cross-poll state has an explicit owner: the normal LibreNMS poller persists a
bounded SQL evaluation subdocument in `applications.data.sql_monitoring`.
It retains last accepted sample/generation, per-watched-object baseline,
first/last distinct breach, streak and consumed occurrence IDs. This is new
persistence work, not something today's SQL parser already does. Serialize its
read/merge/write and preserve unrelated application-data siblings, including
Horizon. Re-evaluate the cheap state transition against the generation/sample
and policy digest re-read under that lock, or perform a compare-and-set on
them; merging a previously computed streak is insufficient. Repeated/out-of-order
polls must not advance state or roll it backward.
The page is read-only. A gap/reset interrupts a consecutive-sample streak.
Agent history-reader cursors/retention remain the agent's responsibility, with
generation/gap information reported to this poller state. Restart/failover and
two polling paths observing the same snapshot need idempotence tests. The
optional held-incident latch below is additional state with a different purpose.

The research-baseline save path was not suitable for that persistence unchanged. The
[parser](../librenms-overlay/includes/polling/unix-agent/windows_agent.inc.php)
reads application data near its start, rebuilds a fixed key list and saves the
whole model after RRD work. It carries central Horizon data from that early
snapshot. The [central publisher](../librenms-overlay/tools/horizon-central-collector.php)
instead locks and merges its own keys in a transaction. A publish between the
poller's read and save could therefore be overwritten by the older poller copy.
The interleaving is a source-demonstrated possibility; live data loss has not
been observed in this investigation.

Before adding state, explicitly retain the `sql_monitoring` subdocument and
replace the early-snapshot save with a short, locked **re-read at save time**,
merging only writer-owned keys. Include central Horizon and SQL ownership;
do not merely lock a stale model or carry the new key through the old whitelist.
The writers currently overlap on Horizon metric names as well as data. For the
proposed fix, treat centrally collected Horizon as the authoritative source and
re-project its overlapping scalar/object metrics from the locked latest data,
using one shared mapping before the poller's full metric replacement. Do not
simply omit central metrics: core's omitted-metric deletion could remove them.
Make the ownership map and complete projection explicit, including legacy mode.
Save data and numeric metrics in the same short transaction. Assert both JSON
and metric value/value-prev behavior in the forced interleaving test.

Keep slow queries/RRD I/O outside the lock. The parser already suppresses
Horizon API/platform RRD writes when its central-source flag is set; do not
describe steady-state RRD duplication as demonstrated. Source-mode transitions
and out-of-order writes remain test cases. A forced central-publish-between-
read-and-save test is a batch-2 entry gate, alongside duplicate poll, restart
and failover checks. This is a future correctness fix, not changed here.

## Metrics, history and alert lifecycle

Use three outputs from the same qualified evaluation:

| Output | Purpose | Boundaries |
| --- | --- | --- |
| Bounded `applications.data` | Named issues, current database/job rows, reasons, policy source and capability details for the page | No unbounded history or sensitive raw payloads; stable anchors |
| Curated numeric `application_metrics` | Family exception counts and validity/coverage; object metrics only for explicitly monitored objects | Stable versioned keys; preserve current keys; caps and omissions visible |
| New RRD families when justified | Capacity or interval performance trends with units and coverage | Keep old RRD schemas; no per-job/per-file RRD explosion; no fabricated instance history |

Verify metric-key length before choosing the ID encoding. The pinned schema
snapshot uses `varchar(64)`; its historical create migration used 32. Keep new
keys short, detect collisions explicitly and reject rather than truncate them;
check the actual deployed schema without changing it. Opaque IDs are not a
privacy guarantee for the associated display-name mapping. [23], [24]

Provisional metric families: database unexpected-state count, log-capacity
exception count, monitored-job failure/overdue counts and backup-policy overdue
count. Each needs assessed/expected/unknown counts, policy-configured state and
an absolute `valid_until_epoch` bound to its contributing observations. Names
and numeric enums are to be finalized with fixtures, not a new protocol here.
Known fresh bad evidence may raise attention despite unknown objects; zero
exceptions with incomplete assessment must not become a complete healthy claim.

Family counts mean "some exception exists". If database A recovers while B
fails and the count stays one, a count-only alert cannot identify a new incident
or guarantee another notification. Use object-qualified rows for explicitly
watched objects when independent notifications/acknowledgements are required.
The inspected upstream evaluator can mark a replacement of fault identities as
changed, but a scalar count alone supplies no such identity change. [19]

A stored `fresh=1` or age value freezes when polling stops. Compute expiration
against the alert evaluator's current time, using an absolute valid-until value
and explicit sample/generation context. Polling the same cache cannot extend
validity or first-breach duration. Reject unreasonable future timestamps, and
bind predicates to the same application, capability and object; otherwise a
readable instance could qualify another instance's bad/unavailable value.
Use tested custom SQL/self-joins or qualified derived metrics, not incompatible
conditions against different metric names on a single row. No ready-to-enable
SQL rule is supplied before the validity contract exists. [17]

Alert lifecycle must cover **bad -> unknown -> good**, not merely bad -> zero:

1. For a first implementation, keep observation-qualified condition rules and
   separate monitoring-gap rules. Gaps include expiry, denied/error capability,
   unknown watched objects, assessed below expected and truncation; not just
   failure of the whole payload. If A becomes invisible while B stays readable,
   A is unverified even when the family still has fresh observations.
   Templates must say that a condition ceased to
   be observed or became unverified; never claim recovery during a gap. Either
   disable condition recovery notifications, or use a tested qualified template.
   The LibreNMS UI may still clear that condition under native match semantics.
2. If an operational requirement is to keep the original incident open until
   fresh good evidence, a separate explicit last-known-incident latch/persistence
   design is required. It must identify unverified held incidents, not replay
   old measurements as current. Validate expiry, restart, removal, maintenance
   and recovery before enabling it. This persistence is not implemented today.

Do not use a global all-capabilities gate that hides a known bad database when
an unrelated job query fails. Suppress dependent duplicate faults deliberately,
such as query failures under a known engine outage, while preserving coverage.
Start with opt-in family alerts and named evidence; per-object independent
incident/acknowledgement behavior is an integration acceptance test. [19], [20]

Thresholds, dwell, clear conditions and maintenance are policy decisions. For
sampled blocking/grants, count distinct source observations and describe the
result as repeated samples, not continuous duration. For backups, compare
policy-qualified age; for job failure, choose and document either current last
failed outcome or newly retained failure occurrence semantics. Notification
delay/repetition is configured separately in LibreNMS. [18]

## Dropdowns and expandable details

Use native labeled `<select>` controls for scope and `<details>` disclosures for
evidence, following the current page's keyboard-friendly instance cards. This
is a proposed layout, not a rendered or production-theme test:

```text
SQL Server: global verdict, next check, freshness and assessed coverage
Up to six tiles + full named attention list (always global)

Instance [All instances v]   Show [Issues and unknown v]
Showing X of Y; N issues outside the selected scope; Clear filters

v Instance label | verdict | first issue + N more | evidence age
    > Performance           readable/expected; sample window
    > Databases and logs    exceptions + unknown / assessed / expected
    > Backups and integrity policy exceptions; external/unverified coverage
    > Jobs                  failures/overdue; current activity separate
    > Services and identity engine, Agent, edition, ports, components
    > Collection            capability status, reason, limits, policy source

> Host trends              explicitly labeled host aggregates
> Shared component inventory
```

Open an attention-linked instance and its relevant evidence section server-side
from validated URL scope variables; a fragment alone is insufficient without
JavaScript. Show
exception and unknown counts in collapsed summaries. One section level inside
an instance is enough. Preserve stable identity-based anchors across issue
sorting; the current positional instance IDs cannot support durable deep links.
Keep unavailable/not-configured sections present with their reason instead of
removing them or showing an empty table. Do not automatically expand hundreds
of objects; bound displayed rows and disclose omitted counts.

Persist filters in supported page URL variables, with validation and a clear
reset; avoid accidental navigation to another application. Use per-section
search for large database/job sets. On mobile stack controls and use compact
rows with explicit units. Keyboard labels/focus, disclosure Enter/Space behavior,
no-JavaScript access to all evidence, escaping and light/dark themes need tests.

The instance filter only scopes current evidence. Existing historical plots
remain host aggregates; an instance dropdown cannot reconstruct unstored
per-instance history. Keep their host scope visible or outside the filter.
Collapsed graphs can remain lazy; expanding a section must never trigger an
ad hoc database query from the page.

## Implementation batches and acceptance gates

1. **Overlay readiness:** shared evaluation for page and new qualified metrics;
   stable anchors, instance/filter disclosures using existing rows. Cover legacy
   compatibility, negative/reset/partial/stale inputs, cache repeats and actual
   application-metric retention. No new agent permissions or collection needed.
   Existing `collected` gives payload-level age only; expiry here is a qualified
   interim bound, not proof of a new SQL-family sample or distinct-sample dwell.
2. **Separate agent observation contract:** capability envelopes, raw cumulative
   observation/reset identity and limits. Pair it with the separate overlay
   persisted evaluator above. Test before claiming durable deltas or distinct
   source-sample dwell; batch 1 alone cannot support those claims. The save-time
   locked merge/ownership tests are an entry gate, not an optional refinement.
3. **Separate optional query batch:** database state and log capacity first;
   bounded selected job/backup evidence next. Keep narrow credential-free PDH
   log capacity as a reduced-coverage alternative. Record actual duration/rows/
   bytes and validate denied/partial behavior per family.
4. **Policy and alert pilot:** explicit watched objects, one policy source,
   named UI evidence, disabled-by-default rule examples and lifecycle templates.
   Prove fault identity, expiry while polling stops, unknown/recovery behavior,
   distinct-sample dwell, maintenance and notification grouping on the installed
   LibreNMS core before opt-in activation. Check the deployed metric value type
   retains epoch precision and compare agent, poller and database clocks; a
   single-precision float would round epoch seconds by minutes. The pinned
   upstream migration defines double, but deployed schema still needs checking. [23]
5. **Later diagnostics:** interval I/O, tempdb denominator, integrity and
   conditional HA. Use `delta(stall_ms)/delta(operations)` for I/O latency;
   restart/reset invalidates the baseline, zero operations is not zero latency. [9]

Test database rename/recreate/ID reuse; restricted/offline visibility; intentional
restore/standby; growth disabled/maxed and shared volume pressure; SIMPLE and
copy-only/external/replica backups; history purge and clock/DST ambiguity;
current/old Agent sessions, failed step vs whole job, disabled/on-demand jobs;
timeouts/cancellation/truncation; engine/Agent restarts; missing/malformed/older
payloads and cached duplicate samples. Include an outage with bad evidence,
then no polling, then fresh healthy evidence in the alert lifecycle tests.

Initial compatibility targets are the existing legacy Windows/.NET target and
SQL 2017, plus SQL 2019/2022+ permission differences. Do not claim every SQL
version/edition or protocol supported until capability tests pass. Database/job
names in fixtures must be synthetic. Live-query tests, grants, overhead
measurement, alert activation, release and deployment are outside this research.

Claude reviewed the current source and navigation proposal as a read-only
partner. It identified the page/metric guard difference and positional anchor
limit, and challenged persistence ownership, partial-scope gaps and count-only
incident identity. The reconciled design assigns that state and qualifies the
first overlay batch. It also records the current multi-writer save window and
distributed policy-revision gate. Codex verified those save paths, reset
semantics and the pinned upstream behavior above.
Peer review is design/source evidence, not runtime or visual validation.
The final explicit metric-ownership, locked-transition and policy-rollout
clarifications were checked by Codex after Claude's last response; no live
concurrency or alert tests were performed.

## Primary sources

[1]: https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/sys-databases-transact-sql?view=sql-server-ver17
[2]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-views/sys-dm-db-log-space-usage-transact-sql?view=sql-server-ver17
[3]: https://learn.microsoft.com/en-us/sql/relational-databases/system-catalog-views/sys-master-files-transact-sql?view=sql-server-ver17
[4]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-os-volume-stats-transact-sql
[5]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/dbo-sysjobhistory-transact-sql?view=sql-server-ver17
[6]: https://learn.microsoft.com/en-us/sql/relational-databases/system-stored-procedures/sp-help-jobhistory-transact-sql?view=sql-server-ver17
[7]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/dbo-sysjobactivity-transact-sql?view=sql-server-ver17
[8]: https://learn.microsoft.com/en-us/sql/relational-databases/system-tables/backupset-transact-sql?view=sql-server-ver17
[9]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-io-virtual-file-stats-transact-sql?view=sql-server-ver17
[10]: https://learn.microsoft.com/en-us/sql/relational-databases/system-dynamic-management-objects/sys-dm-db-file-space-usage-transact-sql?view=sql-server-ver17
[11]: https://learn.microsoft.com/en-us/sql/t-sql/functions/databasepropertyex-transact-sql?view=sql-server-ver17
[12]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/copy-only-backups-sql-server?view=sql-server-ver17
[13]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/recovery-models-sql-server?view=sql-server-ver17
[14]: https://learn.microsoft.com/en-us/sql/relational-databases/backup-restore/sql-server-vss-writer-backup-guide?view=sql-server-ver17
[15]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/includes/polling/functions.inc.php
[16]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/app/Models/ApplicationMetric.php
[17]: https://docs.librenms.org/Alerting/Rules/
[18]: https://docs.librenms.org/Alerting/Operations/
[19]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/LibreNMS/Alert/AlertRules.php
[20]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/LibreNMS/Alert/AlertUtil.php
[21]: https://learn.microsoft.com/en-us/troubleshoot/sql/connect/use-server-name-parameter-connection-string
[22]: https://learn.microsoft.com/en-us/ssms/agent/sql-server-agent-fixed-database-roles
[23]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/database/migrations/2018_07_03_091314_create_application_metrics_table.php
[24]: https://github.com/librenms/librenms/blob/337606a75465a8a8518b36a31e5fe44bd800087c/database/schema/mysql-schema.sql
