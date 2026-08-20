# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md` phase by phase. Phase 0, Horizon
  machine state and pool capacity correctness, is published as overlay 0.6.23.
- Current state: Overlay 0.6.23 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes are
  untouched, so this is overlay-only. One state taxonomy decides independently
  whether a machine is placement capacity, how severe its own state is, whether it
  counts as a problem machine, and whether the state was recognized. A disconnected
  session is classified as occupied: unavailable, but neither available nor faulted.
  Full utilisation reports capacity exhaustion rather than a fault. Withheld and
  still-becoming-ready spares no longer score as failures. Only faulted spares with
  no ready capacity remaining can drive a pool critical. An unrecognized state
  reports incomplete and is excluded from scoring. The published state distribution
  carries each state's capacity treatment, severity, and issue flag, and the page
  shows them.
- Relevant decisions: per-machine severity and the aggregate counts derive from the
  same table, so a row can never disagree with the totals. Pool totals reconcile in
  both directions and the tests assert it, because an accounting hole in 0.6.22
  passed the earlier tests. The problem-machine definition follows the vendor's
  documented problem-VM list so the vendor comparison metric can converge. Session
  presence affects placement only and never suppresses a fault. Maintenance is a
  deliberate operator action, so a withheld machine is informational. No RRD schema,
  protocol, or application identity change; the new spare counters are section
  fields only.
- Validation completed: 30 central collector tests, 11 parser fixtures, 11 app-page
  fixtures, 69 overlay PHP files linted, overlay test runners linted, and
  `bash -n install.sh`, all exit 0 with stderr inspected rather than piped through
  `tail`. Packaged-artifact verification confirmed the occupied class, the new
  reason code, and both page labels ship, the capability manifest reports the new
  version, 69 packaged PHP files lint clean, and `manifest.txt` reconciles with its
  70 payload files. Preserved agent artifact hashes were byte-identical and no
  artifact for this version existed beforehand.
- Validation remaining: the Phase 0 field oracle, which has not been read yet.
  Overlay 0.6.22 is deployed on the five application nodes; 0.6.23 is published but
  not applied. The oracle could not be read under 0.6.22 because rrdcached holds
  writes for up to thirty minutes and its socket is not readable by the inspecting
  account, so the on-disk values were still pre-update. Read it after 0.6.23 is
  applied.
- Next action: apply overlay 0.6.23 to overlay nodes, then read the oracle. On the
  affected pod the vendor problem-machine mismatch metric should trend toward zero
  and the capacity health scope should vary instead of holding one value; the second
  pod reads zero throughout and is the no-regression check. A fully utilised healthy
  pool must not report a fault, and a disconnected session must read as unavailable
  rather than available or faulted. Any state the machine inventory lists as
  unrecognized is a taxonomy gap worth reporting. If the mismatch metric does not
  move, treat it as a hard blocker and do not start Phase 1. Phase 1 is the tab
  contract and shared summary renderer.
- Inspection notes for whoever continues: confirm collector liveness from the
  systemd journal for the Horizon worker, never from RRD file timestamps, because
  rrdcached's write delay makes a healthy collector look stopped. Two Horizon pods
  are collected, each with its own display device and application id.
