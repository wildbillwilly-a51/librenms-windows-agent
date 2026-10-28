# SQL qualified metrics and page controls

Status: implemented in unreleased overlay source, using existing agent data.
Agent collection, existing `sql_perf_*` metrics, RRD schemas and alert rules are
unchanged. Deeper database/log/job/backup collection remains in
[the separate design](sql-server-alerting-and-details-design.md).

## Page controls

The SQL Server tab has native Instance and Show selectors, Apply and Clear
filters. Show can include all instances or only issues/unknown. The header,
tiles, attention and collection coverage remain global; the result line reports
how many instance conditions are outside the filter. Trends and inventory are
also explicitly scoped to the whole host.

Each instance expands into Performance, Services and identity, and Collection.
Attention links open the relevant instance and section. Stable identity-based
anchors survive changes in issue ordering. URL scope persists across reloads;
GET submission and server-side disclosure opening work without JavaScript.
JavaScript adds immediate filtering and attention navigation.

Collection identifies unavailable evidence and shows the metric instance key.
Database state, log capacity, backup/integrity history and named job outcomes
remain labeled as not collected. More detail on the page does not create new
agent observations or per-instance historical graphs.

## Additive metric contract

The same SQL evaluation drives page interpretation and these new numeric fields.
Legacy `sql_perf_*` values retain their published meaning, including sampled
job deltas on reset. Existing history is not rewritten.

Global `sql_q_*` fields are present for every Windows Agent application, including
hosts without SQL; non-detection is state -1. Observation/deadline fields update
with new payloads. Like other metrics, their `value_prev` is a previous changed
value, not proof of a previous poll. Evaluation reads the current clock per
save/render, including in a long-lived process.

The instance key is the first 32 hexadecimal characters of SHA-256 over the
ASCII-case-normalized protocol instance name. Its mapping is retained in
`applications.data.sql_server_qualification.instances` and shown in Collection.
Names which collide under legacy punctuation substitution have different new
keys. Ambiguous identities invalidate every affected qualified value rather
than overwriting one another. Opaque keys do not anonymize the name mapping.

| Metric | Meaning at the last application update |
| --- | --- |
| `sql_q_observed` | Parsed payload observation epoch, or zero when invalid |
| `sql_q_expires` | Absolute payload validity deadline, or zero when unknown |
| `sql_q_state` | SQL service/listener/readable-counter scope: 0 OK, 1 info, 2 warning, 3 critical, -1 not detected, -2 unknown/unqualified |
| `sql_q_unknown` | Number of reported gaps in the implemented observation scope |
| `sql_q_instances` | Discovered/reported instance count; inventory context |
| `sql_q_counters` | Instances with qualified engine-counter evidence at that update |
| `sql_q_readable:<key>` | 1 if engine counters qualified at that update; otherwise 0 |
| `sql_q_expires:<key>` | Same payload deadline for that instance |
| `sql_q_state:<key>` | Instance verdict using the same ordinal; a known engine outage can remain critical while counters are unavailable |
| `sql_q_blocked:<key>` | Current blocked-process sample, or -1 unavailable |
| `sql_q_grants:<key>` | Current pending-memory-grant sample, or -1 unavailable |
| `sql_q_ple:<key>` | Page life expectancy in seconds, or -1 unavailable |
| `sql_q_pressure:<key>` | Existing agent pressure-condition count, or -1 unavailable |
| `sql_q_jobs_valid:<key>` | 1 if the sampled Agent-job delta qualified independently; otherwise 0 |
| `sql_q_jobs:<key>` | Qualified failed-job delta, or -1 unavailable/reset/awaiting baseline |

Qualification requires a valid, explicit timestamp with timezone, successful
applicable SQL collection, and relevant running/readability evidence. Job
qualification additionally requires a readable delta and no reported reset;
it is independent of whether engine PDH counters are readable. A job reset is
incomplete evidence, not an all-clear. Individual missing/negative/nonfinite
values remain unavailable even when other counters on the instance qualify.
Known bad evidence is not hidden by an unrelated coverage gap.
The page can retain last-reported contextual values when a counter row is
unqualified; its coverage/qualification labels apply to those observations.

The interim deadline is 15 minutes after the payload observation, capped from
the polling time if the agent is slightly ahead. More than 120 seconds ahead,
missing/invalid time or expired evidence cannot qualify. Repeated cached
observations retain their first accepted deadline, including after clock skew
catches up. The page re-evaluates freshness when rendered.

This is payload-level freshness. It does not prove a new SQL-family sample,
continuous pressure, consecutive poll measurements or complete event delivery.
Job deltas remain sampled between agent counter collections.

## Future rule pilot

No rules are created or enabled by this implementation. Before a pilot, confirm
the installed core and database schema, including double precision for epoch
values and sufficient metric-key length. The reference upstream schema uses
double values and a 64-character metric name; the deployed schema was not
changed or certified by this batch.

Every value rule needs the matching application, instance key, appropriate
readability guard and an expiry comparison against **the alert evaluator's
current time**. Stored flags and ages freeze when polling stops. Low-value rules
also need `value >= 0`, since -1 would otherwise satisfy a low PLE threshold.
Use `sql_q_jobs_valid` for jobs; engine-counter readability cannot substitute
for that job-specific guard.

Illustrative custom SQL for an existing agent pressure condition follows. It
is not an enabled rule or a sustained-pressure policy. Replace the same key in
all three metric names and validate against the installed LibreNMS rule engine:

```sql
SELECT devices.device_id, applications.app_id, pressure.metric, pressure.value
FROM devices
JOIN applications ON applications.device_id = devices.device_id
JOIN application_metrics AS pressure
  ON pressure.app_id = applications.app_id
 AND pressure.metric = 'sql_q_pressure:<instance-key>'
JOIN application_metrics AS readable
  ON readable.app_id = applications.app_id
 AND readable.metric = 'sql_q_readable:<instance-key>'
JOIN application_metrics AS expiry
  ON expiry.app_id = applications.app_id
 AND expiry.metric = 'sql_q_expires:<instance-key>'
WHERE devices.device_id = ?
  AND devices.status = 1 AND devices.disabled = 0 AND devices.ignore = 0
  AND applications.app_type = 'windows-agent'
  AND readable.value = 1 AND expiry.value > UNIX_TIMESTAMP()
  AND pressure.value > 0;
```

A rule losing its match can recover in LibreNMS even when evidence became
unknown. Separate monitoring-gap rules must cover expiry, unavailable relevant
checks and incomplete scope. Recovery wording/notification policy must not
claim healthy recovery during a gap. Keeping an original incident open until
fresh good evidence requires the separate held-incident design; it is not added
here. Family counts also cannot identify a different failing object when the
count stays constant. No independent per-database/job acknowledgements or
complete job-history delivery are promised.

## Save consistency and validation boundary

The poller re-reads the application row under the same lock order as the central
Horizon publisher. It merges current siblings, preserves authoritative central
data and re-projects the complete central scalar and existing per-pool metric
set before the core's replacement update. Central-only health/vendor metrics
now survive a device poll; removed pool names are not retained by that poll.
Agent-only Horizon retains its previous metric set. The central collector's
scalar projection delegates to the shared helper; it does not start publishing
per-pool fields in this batch.

Fixture interleaving checks model the upstream float cast, previous-changed-value
and omitted-metric deletion behavior. They verify JSON, numeric values,
`value_prev`, removed pools, sibling retention and lock boundaries. These are
deterministic regression checks, not proof of real database lock/deadlock
behavior. RRD work remains outside the transaction. A source-mode change during
RRD work still uses the earlier source flag; transition testing remains a rollout
check. Production theme, installed-core/schema and actual alert lifecycle remain
pilot/rollout checks.
