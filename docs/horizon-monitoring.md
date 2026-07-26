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

The LibreNMS operational view presents collector-confirmed health and next
action first, followed by six compact metrics. Complete service, process,
listener, and certificate rows remain available under `Inventory and raw
diagnostics`. Optional listeners do not become health issues merely because
they are not active on a particular server.

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
Overlay release 0.6.17 provides the cluster-safe centralized successor:

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
9. Default pool health is warning at 50% unready spares and critical at 90%.
   Zero ready spares is always critical, and zero unused capacity is warning.
   Truncated machine/session inventory is `incomplete`, never healthy.
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

The existing FactoryTalk runtime sampler was generalized into a shared role
process sampler rather than duplicated.

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

Percentage scoring begins at `poolMinimumSpareSample`, but a non-empty spare
set with zero ready machines is always critical. An enabled pool with no
unused machines is warning for capacity exhaustion. Alert rules should require
the state to persist for at least two polls so normal instant-clone replacement
waves do not page the team from one sample.

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
