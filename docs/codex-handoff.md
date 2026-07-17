# Codex Handoff

- Current objective: Publish release 0.6.14 with Horizon process telemetry and
  bounded read-only pod, directory, gateway, and clone-pool health.
- Current state: Release 0.6.14 adds shared process telemetry, an independent
  `horizon_api` collector, additive Horizon runtime/API/platform sections and
  RRDs, and compact pod/pool disclosures. The API collects Connection Server
  health/configuration, Horizon AD LDS replication, Horizon domain access,
  gateways, sessions, pools, and machines. API credentials use a one-time DPAPI
  LocalMachine-protected file restricted to System and Administrators. The
  versioned MSI and overlay are present under `artifacts/` with `SHA256SUMS`.
- Next action: Test against a non-production
  Horizon Connection Server only after the user supplies the target API URL,
  provisions a least-privilege read identity, and explicitly authorizes that
  live read-only check.
- Blockers: Live API contract/response validation requires a non-production
  Horizon endpoint and a dedicated read-only credential. Local work is not
  blocked. No deployment is authorized.
- Important decisions: Windows/Microsoft AD, Horizon configuration replication
  (AD LDS), and Horizon domain access are distinct data models. Connection
  Servers are reported as peers/local API target rather than invented
  primary/secondary roles. Only instant/linked-clone pools are scored;
  `AVAILABLE` is ready, current-session machines are not spares, and truncated
  inventory cannot be healthy. No endpoint or LibreNMS deployment is authorized.
- Branch/commit/sync: `main`; the containing commit is the scoped 0.6.14 release
  commit and should be published through the audited GitHub sync workflow.
- Validation complete: service builds cleanly; all 59 core tests pass with
  supported runtime roll-forward; sample configuration validates; Horizon
  fixture JSON parses; and the pool evaluator covers 50% warning, 90% critical,
  no-ready-spare critical, and incomplete inventory. Release MSI/overlay builds,
  installer syntax, checksums, package contents, and public-safety scans pass.
- Validation remaining: PHP is unavailable locally, so PHP lint, parser/app-page
  fixture execution, and rendered UI review remain. Live API validation remains
  a separately authorized follow-up.
