# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md`. Phase 0 (Horizon machine-state and
  pool-capacity correctness) and Phase 1 (uniform tab contract and shared summary
  renderer) are complete. The most recent work adds per-pool Horizon availability
  metrics for downstream alerting, published as overlay 0.6.30.
- Current state: Overlay 0.6.30 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes are
  untouched, so this is overlay-only. 0.6.30 flattens each Horizon clone pool's
  availability numbers into `application_metrics` as `horizon_pool_<measure>:<key>` (key
  = pool name sanitized to `[A-Za-z0-9_-]`), seven measures per pool
  (`ready`, `ready_percent`, `machines_total`, `state`, `maintenance`, `spare_total`,
  `unready`), so LibreNMS alert rules — which compare one metric to a constant — can
  target an individual pool. `horizon_pool_state` maps the collector's per-pool
  `health_state` to an ordinal (`ok=0 info=1 warning=2 critical=3`; `disabled=-1`,
  `incomplete=-2` sit below `ok`). Additive to application metrics only; no RRD,
  protocol, or identity change. The prior 0.6.29 pod-view-on-all-members behavior is
  unchanged.
- Relevant decisions: flatten in the overlay poller (the `$horizon_pools` rows are
  already parsed there), keeping the collector as the owner of the per-pool verdict,
  which it emits as `health_state` and the overlay maps to the state ordinal. Percent
  basis is whole-pool `machines_total`; "available" is `spare_ready` (AVAILABLE ready
  spares). Qualifiers keep the trailing colon so `horizon_pool_ready:` does not match
  `horizon_pool_ready_percent:`. "A pool went dark" stays on the estate-wide
  `horizon_pools_incomplete` rollup, not a vanished per-pool row.
- Validation completed: overlay parser fixtures (extended `horizon-detected` with the
  seven per-pool fields for two pools), app-page fixtures, and central collector tests
  all pass on PHP 8.3; the `dotnet` agent test suite passes via the release build; `bash
  -n install.sh` clean. The packaged `capabilities.json` reports 0.6.30 and the new
  `horizon_pool_availability_metrics` capability, and `SHA256SUMS` matches an independent
  hash of the artifact.
- Validation remaining: per-pool rows exist in `application_metrics` only after a live
  poll of a device that reports a Horizon clone pool, so first deployment should confirm
  the `horizon_pool_*:<key>` rows appear on such a device after the next poll.
- Inspection notes: confirm collector liveness from the systemd journal for the Horizon
  worker, never from RRD file timestamps (rrdcached write delay). The Horizon central
  collector caches its per-site snapshot on the management node under
  `storage/app/windows-agent-horizon/<site>.json` (mode 0600). Two Horizon pods are
  collected, each with its own display device and application id.
