# Horizon Monitoring Design

This document defines the supported Horizon monitoring scope and the safest
path for extending it. The Windows agent must remain useful without Horizon
credentials, while authenticated pod-level visibility must be explicit,
read-only, and independently configurable.

## Current Coverage

The credential-free Horizon collector discovers local VMware/Omnissa Horizon
evidence and reports:

- service inventory, startup mode, state, and classified role;
- process inventory and executable path;
- configured local TCP listeners;
- matching local-machine host certificates and expiration state;
- bounded per-process CPU, working set, private bytes, handles, threads,
  read/write throughput, and uptime through the shared role-process sampler;
- collector-scored health for automatic services, required TCP 443, expired
  certificates, and certificates inside the critical expiration window.

The LibreNMS operational view presents conditions and next actions first,
followed by expandable pool capacity, 30-day demand/headroom trends by default,
platform health, collector reliability, and complete lower diagnostics. Issue
machines open a read-only evidence drawer without losing pool context. Local
service, process, listener, and certificate evidence remains available even
when central API collection is not configured.

## Supported Monitoring Sources

| Source | Realistic data | Authentication | Recommendation |
| --- | --- | --- | --- |
| Local Windows inventory | Services, processes, listeners, certificates | None beyond the agent service | Implemented |
| Local Windows process performance | CPU, memory, handles, threads, I/O, uptime, restart evidence | None beyond the agent service | Implemented |
| Local Horizon logs | Bounded warning/error counts, component, last occurrence | Local file read | Add only as sanitized aggregates; do not ship raw logs by default |
| Horizon Server REST/View API | Connection Servers, sessions, machines, clone pools, gateways, domains, and component health | Dedicated Horizon read-only identity/token | Central bounded implementation complete; live contract validation pending |
| Horizon Event Database or Syslog | Failures, user/session lifecycle, administrative changes, and statistical events | Database read identity or configured Syslog | Prefer centralized ingestion rather than querying from every Connection Server |
| Horizon Cloud/Intelligence | Cloud-connected pod, session, and user-experience metrics | Cloud API/OAuth and subscription | Separate optional integration |

Omnissa describes the Horizon Server API as providing status for Horizon
components and resources, with a dedicated Monitor API category. Horizon
Console itself shows Connection Servers, the event database, gateways,
datastores, vCenter instances, domains, machines, and sessions. The event
database can record system failures, end-user actions, administrator actions,
and statistical samples, and Horizon can also emit those events as Syslog.

## Development Status

Release 0.6.14 implements local process telemetry and the initial disabled
Windows-side API prototype without changing the existing Horizon RRD schema.
Overlay release 0.6.20 provides the cluster-safe centralized successor and
operational UI:

1. `windows_agent_horizon_runtime_summary` reports state, process count, CPU,
   working set, private bytes, handles, threads, read/write bytes per second,
   oldest uptime, and a bounded reason code.
2. `windows_agent_horizon_runtime_processes` reports the same per-process
   fields plus PID and classified role.
3. An additive `windows-agent-horizon-runtime` RRD family supplies CPU, memory,
   process/handle/thread, and I/O graphs. Do not change the existing Horizon
   RRD schema.
4. The operational view presents CPU and memory while complete runtime rows
   remain collapsed and informational.
5. A separate, disabled-by-default `horizon_api` collector authenticates at
   `/rest/login`; local Horizon data is no longer delayed or lost when the API
   target is unavailable.
6. Read-only GET requests collect the local pod identity, Connection Server
   monitor/config data, Horizon domain-access data, gateways, sessions,
   instant/linked-clone pools, and machine inventory.
7. Horizon configuration replication (AD LDS) and Horizon broker-to-Microsoft
   AD access use separate `windows_agent_horizon_*` sections. They never feed
   the Windows `windows_agent_ad_*` collector or its health score.
8. Pool health removes machines with a current session from the spare set.
   Every remaining machine that is not `AVAILABLE` is an unready spare;
   maintenance machines remain visible and count as unavailable capacity.
9. Central pool health is count based: one unavailable spare is informational
   while another spare remains ready, two or more unavailable spares is
   warning, zero ready spares is critical, and a non-empty pool with every
   machine in session is critical for zero placement capacity. Truncated
   machine/session inventory is `incomplete`, never healthy.
10. Additive `windows-agent-horizon-api` and
    `windows-agent-horizon-platform` RRD families supply API/session, pod, and
    aggregate clone-pool graphs.
11. Every normal or explicit `windows-agent` application poll performs one
    bounded shared-Redis registration lookup. Only a configured display-device
    registration can enqueue its site; the poller never reads pod
    configuration, credentials, or CA material and never contacts Horizon.
12. Bootstrap priority is always `<site>-vcs1.<suffix>`, then
    `<site>-vcs2.<suffix>`. API-discovered Connection Servers become later
    candidates only after suffix and expected-pod validation. Gateways never
    become API candidates.
13. The central snapshot is merged into the existing `windows-agent`
    application on a configured display device and takes precedence over the
    Windows-side prototype. The display device is a UI anchor, not the API
    preference or a single point of collection failure.
14. A credential-bearing management-node worker consumes deduplicated site
    triggers. A distributed per-site lock and cooldown make simultaneous
    cluster/manual polls one effective API cycle.
15. An independent five-minute systemd timer runs the same collector path when
    no device poll produces a trigger. Display-device status, skipped
    Applications modules, and lost wake-up hints therefore do not stop
    collection.
16. Failed cycles retain the last good values, mark them stale with source and
    timestamps, and write unknown RRD samples instead of false zeroes. The
    centralized collector is the sole writer for central Horizon RRD families.
17. `capabilities.json` exposes overlay version, configuration schema, producer,
    worker, discovery, fallback, and private-integration API compatibility.
18. Bounded unhealthy-service and issue-machine evidence, explicit truncation,
    and collector duration/endpoint/request/page/row/outcome metadata support
    root-cause drill-down without retaining user or client identity.
19. An additive collector-health RRD and combined sessions/headroom graph add
    reliability and demand trends without changing existing RRD schemas.
20. A bounded, sanitized all-machine inventory supports real in-place pool
    filtering by issue, in-session, ready, and unavailable state. Connection
    Server targets open local detail, and the workspace follows LibreNMS light
    and dark themes.

The existing FactoryTalk runtime sampler was generalized into a shared role
process sampler rather than duplicated.

## Health Contract In Overlay 0.6.20

Overlay 0.6.20 replaces equal-weight health scoring with explicit scope,
severity, stable reason code, impact, and redundancy semantics:

- platform, dependency, capacity, collector, and overall states are independent;
- disabled members are excluded from Connection Server redundancy;
- one failed member with two healthy peers is warning, while only one healthy
  enabled member is critical;
- `RESTART_REQUIRED` is warning, `UNKNOWN` is incomplete, and
  `ERROR`/`NOT_RESPONDING` are critical at member scope;
- CRL Prefetch is optional and grouped as a pod-wide informational
  observation; gateway monitor/service health is scored only when configured;
- directory access and service-account failures affect dependency health;
- pool and collector states cannot contaminate platform health;
- collector staleness becomes warning at ten minutes and critical at thirty;
- bounded condition history retains stable IDs, first/last seen, consecutive
  samples, and recovery timestamps;
- optional vendor health/system metrics fail soft and expose warning, error,
  unknown, problem-machine, and derived mismatch totals;
- a new additive Horizon health RRD records all independent scope states
  without altering existing RRD schemas.

The UI consumes collector-provided severity, places informational observations
outside actionable conditions, shows all independent states, and explicitly
distinguishes embedded gateway roles from the absence of standalone gateways.

## Implemented In Overlay 0.6.19

Overlay 0.6.19 implements the approved collector and UI requirements:

1. Retain a bounded, sanitized list of unhealthy service names and statuses
   for each Connection Server instead of publishing only a count.
2. Add collector self-observability: collection duration, attempted and
   selected endpoints, request/page counts, returned session/machine counts,
   truncation, and the final outcome/reason.
3. Replace central percentage scoring with the approved observed-count policy
   while retaining percentage fields only for compatibility and context.
4. Fall back from an empty API `pod_name` to the non-empty cluster/pod identity
   in the operational display.
5. Keep all new collector, pool, and runtime visibility non-alerting until
   observed baselines and alert semantics are separately approved.

The UI is a first-class part of this work. The implemented redesign:

- lead with current actionable conditions, their affected objects, reasons,
  and next actions rather than a generic aggregate issue count;
- make session demand, pool capacity/headroom, unhealthy members/services,
  collection freshness, and collection reliability visible without opening
  disclosures;
- make pool health the primary operational workspace, with one row per pool,
  explicit policy versus observed capacity, machine-state distribution, and
  useful trend context;
- allow every pool row to expand in place into its machine inventory, with
  issue machines sorted first and visibly distinguished from healthy,
  in-session, maintenance, and unavailable machines;
- make each machine selectable so an operator can inspect its state,
  pool, maintenance and session context, issue reason, collection time, and
  next action without losing the expanded pool context;
- make pool counts direct filters for the real all-machine inventory and show
  unavailable machines as explicit rows whenever present;
- open Connection Server conditions and platform members as in-place detail
  instead of routing operators to a generic device dashboard;
- use theme-native light/dark surfaces, readable contrast, and explicit mobile
  pool metric labels;
- preserve complete pod, directory, gateway, replication, protocol, and raw
  diagnostics below the operational summary without crowding the first
  viewport;
- distinguish API availability, Horizon platform health, pool-capacity risk,
  and collector freshness instead of collapsing them into one red/green state;
- remain consistent with LibreNMS navigation, accessibility, and responsive
  behavior while substantially improving hierarchy and readability.

The approved information hierarchy leads with actionable conditions and then
the pool-capacity workspace in the first viewport. It does not lead with a
generic score or an equal-weight metric-card grid. The primary order is:

1. current conditions requiring attention, with affected object, reason, and
   next action;
2. pool capacity and headroom;
3. session demand and 24-hour, 7-day, and 30-day trends;
4. Connection Server and platform health;
5. collector freshness and reliability;
6. directory, gateway, replication, protocol, machine-state, and raw
   diagnostics.

The central snapshot therefore includes a bounded, sanitized per-machine
inventory suitable for the expanded pool view. Each machine row carries only
the stable machine identifier/name, pool association, current state,
maintenance flag, session-presence flag, normalized issue reason, and
collection timestamp needed by the UI. The separate issue inventory remains
available for issue-first evidence and compatibility. Both inventories are
bounded and truncation is explicit.

Pool health uses observed ready and unavailable spare counts rather than a
minimum sample-size warning:

- zero unavailable spares is healthy;
- one unavailable spare is informational and non-alerting when at least one
  other spare is ready;
- two or more unavailable spares is a warning while at least one ready spare
  remains;
- zero ready spares is critical when the spare set is non-empty, including the
  case where the pool's only spare is unavailable.

This health classification does not itself enable LibreNMS alert
notifications. Alert rules remain separately gated. A non-empty pool with no
unused machines because every machine is in session is a critical
capacity-exhaustion condition: the machines are healthy, but the pool cannot
place another session. This remains distinct from unavailable-spare health.

## Central API Safety and Credential Provisioning

Central collection is opt-in. Installing the overlay alone does not query a
pod. The client requires HTTPS, verifies the operating system trust store and
hostname, refuses redirects and non-HTTPS protocols, uses bounded connection
and request timeouts, limits responses and pagination, and marks truncated
session/machine inventories incomplete. Authentication at `/rest/login` and a
best-effort logout are the only POST requests; telemetry calls are GET-only.
There are no configuration, session, machine, entitlement, or action calls.

Use a dedicated Horizon identity with read access to Connection Server
configuration/monitoring, pool and machine inventory, sessions, domains, and
gateways. The helper prompts for the username, login domain, and password. It
does not accept them as command-line values. Values are written atomically to
the existing protected LibreNMS `.env`; temporary rollback material is mode
`0600` and removed after validation. Pod configuration contains no secret and
is written to `.horizon-pods.json` by the same helper.

Generic discovery-first setup:

```bash
cd /opt/librenms
sudo -u librenms php windows-agent-overlay/horizon-central-config.php credential set
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod discover \
  --dns-suffix example.test
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod discover \
  --dns-suffix example.test --apply
sudo -u librenms php windows-agent-overlay/horizon-central-config.php config validate
sudo -u librenms php windows-agent-overlay/horizon-central-config.php config status
sudo -u librenms php windows-agent-overlay/horizon-central-config.php pod list
sudo -u librenms php windows-agent-overlay/horizon-central-config.php test network --site abc
```

Preview is the default and changes nothing. Discovery considers the generic
`<site>-vcs<number>.<dns-suffix>` device contract, enabled/down/disabled state,
the `windows-agent` application, and Horizon-detected metrics. One ready seed
bootstraps authenticated API validation. The API must return a non-empty pod
identity and validated members that remain inside the expected site and suffix.
Apply is add-only and idempotent: existing pod/display choices are retained;
ambiguous, unauthorized, TLS-invalid, unreachable, disabled, and
waiting-for-agent candidates are skipped with bounded reasons.

`config status` and `pod list` report only credential presence and non-secret
pod topology. `test network` performs DNS and strict TLS checks without
authenticating. Manual diagnostics use the same distributed lock. `--force`
bypasses only the cooldown, never the lock:

```bash
sudo -u librenms php windows-agent-overlay/horizon-central-config.php test api --site abc
sudo -u librenms php windows-agent-overlay/horizon-central-collector.php --site abc --force
```

After the manual result and UI are accepted, enable the trigger worker and its
independent five-minute fallback:

```bash
sudo php /opt/librenms/windows-agent-overlay/horizon-central-config.php worker enable \
  --librenms-root /opt/librenms
```

Use `credential rotate`, `credential remove`, `pod enable`, `pod disable`,
`pod remove`, `worker status`, or `worker disable` for lifecycle operations;
no file editing is required. `schedule enable|disable|status` remains a
compatibility alias. Overlay install/reapply/rollback never replaces `.env`,
`.horizon-pods.json`, or last-good state. A rollback that removes the central
worker disables and removes only its signature-verified managed units (and the
legacy marker-verified cron if present) while retaining credentials, pod
definitions, and last-good state.

The non-secret shared registration maps only a LibreNMS device ID/hostname to
one site. Worker startup and fallback reconcile it from protected local pod
configuration. A deleted display device or application blocks that site with
`display_device_not_found` or `windows_agent_application_not_found`; an
offline display device remains valid and does not block API collection.

The 0.6.14 Windows-side DPAPI prototype remains disabled for compatibility. It
is not the mass-deployment path. If both sources exist, central data wins.

## Directory and Server-Role Semantics

Three directory concepts must remain distinct:

- Windows/Microsoft AD health comes only from the local Windows AD collector.
- Horizon configuration replication comes from Connection Server
  `cs_replications` and represents the replicated Horizon configuration
  directory (AD LDS).
- Horizon domain access comes from the AD-domain monitor and represents each
  Connection Server's access/trust relationship to Microsoft AD.

Connection Servers are replicated peers; the supported REST model does not
expose a durable primary/secondary leader role. The collector therefore emits
the local API target, enabled state, version, health, and whether tunnel,
PCoIP, or Blast embedded-gateway paths are configured. UAG/Security Gateway
records are reported separately by the gateway monitor.

## Clone-Pool Health Semantics

Only `INSTANT_CLONE` and `LINKED_CLONE` pools are scored. The pool denominator
is the set of machines with no current Horizon session. `AVAILABLE` is the
only ready state; provisioning, customization, validation, agent-error,
maintenance, unreachable, disabled, and other states remain visible as state
counts and are treated as not ready. This intentionally answers whether the
currently unused capacity can accept a connection now.

One unavailable spare is informational while another spare is ready. Two or
more unavailable spares is warning while ready capacity remains. A non-empty
spare set with zero ready machines is critical, and an enabled non-empty pool
with every machine in session is critical for capacity exhaustion. These
states are visibility only; no alert rules are installed or enabled.

## Live Validation and Later Scope

Release validation covers generic fixtures, while site deployment remains a
separate approval gate. A live rollout should validate service-account
authorization, TLS trust, trigger consumption, cooldown/lock behavior,
five-minute fallback, discovered-member names, pool state mix, the chosen
display anchor, offline-display continuity, and VCS1-to-VCS2 failover without
stopping Horizon services or changing firewall/DNS.

Later optional scope may include vCenter/event-database health,
image-push/provisioning-task state, expected spare targets, farm/RDS health,
and bounded event counts without messages or identities. API-version fallback
should be added only when evidence from a supported target shows a selected
v3/v2 endpoint is unavailable.

## Privacy and Alerting Boundaries

- Do not emit usernames, client IP addresses, desktop names, entitlement
  membership, or raw event messages by default.
- Prefer counts, states, percentiles, and sanitized reason codes.
- Keep runtime metrics non-alerting until normal baselines are observed.
- Only documented Horizon health states and explicitly configured thresholds
  should create issues. Optional ports and inventory-only evidence remain
  informational.
- Do not scrape Horizon Console HTML or parse unbounded debug logs during every
  LibreNMS poll.

## Official References

- [Horizon Server API documentation](https://developer.omnissa.com/horizon-apis/horizon-server/)
- [Connection Server monitor V3](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/monitor/v3/connection-servers/get/)
- [Horizon AD-domain monitor V3](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/monitor/v3/ad-domains/get/)
- [Connection Server configuration V2](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/config/v2/connection-servers/get/)
- [Desktop-pool inventory](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/inventory/v1/desktop-pools/get/)
- [Machine inventory and states](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/inventory/v1/machines/get/)
- [Gateway monitor V3](https://developer.broadcom.com/xapis/vmware-horizon-server-api/latest/rest/monitor/v3/gateways/get/)
- [Horizon 8 monitoring and event capabilities](https://techzone.omnissa.com/resource/horizon-8-frequently-asked-questions-faqs)
- [Monitoring Horizon components](https://techzone.omnissa.com/resource/evaluation-guide-horizon-8)
- [Horizon network ports](https://techzone.omnissa.com/resource/network-ports-horizon-8)
- [Connection Server log location guidance](https://techzone.omnissa.com/resource/antivirus-considerations-horizon-environment)
- [Horizon PowerCLI and View API connection model](https://developer.omnissa.com/horizon-powercli/)
