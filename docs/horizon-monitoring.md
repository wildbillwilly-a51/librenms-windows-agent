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
| Local Windows process performance | CPU, memory, handles, threads, I/O, uptime, restart evidence | None beyond the agent service | Next additive collector |
| Local Horizon logs | Bounded warning/error counts, component, last occurrence | Local file read | Add only as sanitized aggregates; do not ship raw logs by default |
| Horizon Server REST/View API | Connection Servers, sessions, machines, pools, farms, gateways, vCenter, domains, events, and component health | Dedicated Horizon read-only identity/token | Best pod-level integration after credential storage is designed |
| Horizon Event Database or Syslog | Failures, user/session lifecycle, administrative changes, and statistical events | Database read identity or configured Syslog | Prefer centralized ingestion rather than querying from every Connection Server |
| Horizon Cloud/Intelligence | Cloud-connected pod, session, and user-experience metrics | Cloud API/OAuth and subscription | Separate optional integration |

Omnissa describes the Horizon Server API as providing status for Horizon
components and resources, with a dedicated Monitor API category. Horizon
Console itself shows Connection Servers, the event database, gateways,
datastores, vCenter instances, domains, machines, and sessions. The event
database can record system failures, end-user actions, administrator actions,
and statistical samples, and Horizon can also emit those events as Syslog.

## Recommended Next Collection Phase

Add credential-free runtime telemetry before adding API authentication:

1. Add `windows_agent_horizon_runtime_summary` with state, process count, CPU,
   working set, private bytes, handles, threads, read/write bytes per second,
   oldest uptime, and a bounded reason code.
2. Add `windows_agent_horizon_runtime_processes` with the same per-process
   fields plus PID and classified role.
3. Add an additive `windows-agent-horizon-runtime` RRD family and CPU, memory,
   process/handle/thread, and I/O graphs. Do not change the existing Horizon
   RRD schema.
4. Present CPU, memory, and the busiest Horizon processes in the operational
   view. Keep runtime availability and utilization informational by default.

The existing FactoryTalk runtime sampler already demonstrates the bounded WMI
query and additive graph pattern. It should be generalized into a shared role
process sampler instead of copied into a second product-specific
implementation.

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
- [Horizon 8 monitoring and event capabilities](https://techzone.omnissa.com/resource/horizon-8-frequently-asked-questions-faqs)
- [Monitoring Horizon components](https://techzone.omnissa.com/resource/evaluation-guide-horizon-8)
- [Horizon network ports](https://techzone.omnissa.com/resource/network-ports-horizon-8)
- [Connection Server log location guidance](https://techzone.omnissa.com/resource/antivirus-considerations-horizon-environment)
- [Horizon PowerCLI and View API connection model](https://developer.omnissa.com/horizon-powercli/)
