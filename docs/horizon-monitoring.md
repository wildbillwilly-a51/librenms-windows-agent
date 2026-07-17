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
| Horizon Server REST/View API | Connection Servers, sessions, machines, clone pools, gateways, domains, and component health | Dedicated Horizon read-only identity/token | Initial bounded integration implemented; live contract validation pending |
| Horizon Event Database or Syslog | Failures, user/session lifecycle, administrative changes, and statistical events | Database read identity or configured Syslog | Prefer centralized ingestion rather than querying from every Connection Server |
| Horizon Cloud/Intelligence | Cloud-connected pod, session, and user-experience metrics | Cloud API/OAuth and subscription | Separate optional integration |

Omnissa describes the Horizon Server API as providing status for Horizon
components and resources, with a dedicated Monitor API category. Horizon
Console itself shows Connection Servers, the event database, gateways,
datastores, vCenter instances, domains, machines, and sessions. The event
database can record system failures, end-user actions, administrator actions,
and statistical samples, and Horizon can also emit those events as Syslog.

## Development Status

Release 0.6.14 implements the first two additive phases without changing the
existing Horizon RRD schema:

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

The existing FactoryTalk runtime sampler was generalized into a shared role
process sampler rather than duplicated.

## API Safety and Credential Provisioning

API collection remains `disabled` by default. It requires HTTPS, uses a 5-60
second total request budget, limits page size to 1,000 and page count to 20,
and marks session/machine inventory as truncated when the configured bound is
reached. It is a separate collector with its own timeout, so API unavailability
does not change or suppress local Horizon host collection.

The password is not stored in `agent.json`. An administrator provisions it
once on the Windows host with:

```powershell
& 'C:\Program Files\LibreNMS Windows Agent\LibreNMS.WindowsAgent.Service.exe' --store-horizon-credential
```

The command writes a Windows DPAPI LocalMachine-protected file whose ACL grants
access only to Local System and local Administrators. The API can then be
enabled in `collectors.horizon.api`:

```json
{
  "mode": "enabled",
  "baseUrl": "https://horizon-connection-server.example.test",
  "credentialFile": "%ProgramData%\\LibreNMS\\Windows Agent\\horizon-api-credential.bin",
  "timeoutSeconds": 15,
  "pageSize": 500,
  "maxPages": 20,
  "includeConnectionServers": true,
  "includeHorizonDomains": true,
  "includeGateways": true,
  "includeSessions": true,
  "includeClonePools": true,
  "poolWarningUnreadyPercent": 50,
  "poolCriticalUnreadyPercent": 90,
  "poolMinimumSpareSample": 2
}
```

Use a dedicated Horizon identity with the view privileges required for
Connection Server configuration/monitoring, pool and machine inventory,
sessions, domains, and gateways. Authentication is the sole POST request. All
telemetry requests are GET requests; no configuration, session, machine,
entitlement, or action endpoint is present in the client.

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

## Next Collection Phase

Verify the exact endpoint availability, least-privilege role, pool state mix,
and thresholds against a non-production pod. Then consider vCenter/event
database health, image-push/provisioning-task state, expected spare targets,
farm/RDS health, and bounded recent event counts without messages or identities.
API-version fallback should be added only when evidence from the target Horizon
version shows that a selected v3/v2 endpoint is unavailable.

## Authenticated Pod-Level Phase

An authenticated Horizon integration should collect aggregated, non-user
identifying values such as:

- Connection Servers healthy, warning, or unavailable;
- event database, gateway, vCenter, and domain health;
- sessions by state and display protocol;
- machines by available, connected, problem, disabled, and maintenance state;
- pool, farm, and RDS host health, session count, and load index;
- recent warning/error event counts and last occurrence;
- optional logon-timing percentiles when Help Desk data is licensed and
  available.

This integration should run once per pod rather than once on every Connection
Server. It requires API version negotiation, pagination, throttling, strict
timeouts, a least-privilege read-only Horizon role, and a credential reference
that does not place a password or refresh token in public configuration or
agent output. The collector must never call session, machine, entitlement, or
configuration mutation endpoints.

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
