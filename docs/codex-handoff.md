# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md`. Phase 0 (Horizon machine-state and
  pool-capacity correctness) and Phase 1 (uniform tab contract and shared summary
  renderer) are complete. The most recent work makes the central Horizon pod view
  visible on every pod member, published as overlay 0.6.29.
- Current state: Overlay 0.6.29 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes are
  untouched, so this is overlay-only. The central collector now fans the pod-wide
  snapshot (status, pools, members, gateways, conditions) out to every Connection
  Server in the pod that runs the agent, in addition to the display device, so any
  member shows the identical pod view with its own local host evidence alongside it.
  Members are best-effort (one without the agent app is skipped). Both data and metrics
  are written per member; each member's own poll preserves the data because the parser
  keys on the collector-stamped central snapshot source. Fan-out is on by default with a
  `publish_to_members: false` per-pod opt-out. The tab freshness line also names the
  reporting Connection Server ("Collected … • via …").
- Relevant decisions: fan-out at publish time (the collector owns pod topology) keeps
  the page and parser unchanged — the page already renders the pod view from each
  device's own app data, and the parser already preserves central keys across a
  device's own polls. Member hostnames resolve from the reported member name plus the
  pod DNS suffix. A pure `HorizonCentralRuntime::publishTargets()` (global namespace,
  unit-tested) computes the target set; the DB write stays in `publish()`.
- Validation completed: 34 central collector tests (added a fan-out target-resolution
  test; refreshed the capability-manifest version pin that had gone stale at 0.6.27),
  11 parser fixtures, 11 app-page fixtures, full overlay PHP lint, and
  `bash -n install.sh`, all exit 0. `publishTargets` was verified against real pod
  topology (it produced every member hostname). The packaged `capabilities.json`
  reports 0.6.29 and the new `horizon_pod_view_on_all_members` capability, and
  `SHA256SUMS` matches an independent hash of the artifact.
- Validation remaining: the DB fan-out to member devices runs only inside a live
  collection, so first deployment should confirm a non-display member actually receives
  the pod data after the next collection.
- Inspection notes: confirm collector liveness from the systemd journal for the Horizon
  worker, never from RRD file timestamps (rrdcached write delay). The Horizon central
  collector caches its per-site snapshot on the management node under
  `storage/app/windows-agent-horizon/<site>.json` (mode 0600). Two Horizon pods are
  collected, each with its own display device and application id.
