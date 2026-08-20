# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md` phase by phase. Phase 0, Horizon
  machine state and pool capacity correctness, is published as overlay 0.6.25.
- Current state: Overlay 0.6.25 is built, published on GitHub `main`, and is the
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
- Phase 0 status: complete. Overlay 0.6.25 is deployed on all five application
  nodes and the collector runs clean. The operator confirmed on the live pages that
  cew-RDMS is a genuine critical (0 ready, faulted spares) rather than saturation,
  the capacity scope now varies with the environment, disconnected machines read as
  unavailable in both the pool counts and the machine list, every inventory filter
  agrees with its counter, and machine flagging is correct: ALREADY_USED is flagged
  as a fault while a maintenance machine shows as unavailable but unflagged because
  it is intentional.
- Next action: begin Phase 1 of docs/app-page-ux-plan.md, the uniform tab contract
  and shared summary renderer. Carry one Horizon finding into the design: an
  intentionally-withheld machine (maintenance, disabled) needs a distinct non-alarm
  visual state so "unavailable on purpose" is legible at a glance, separate from
  both the yellow issue flag and a plain row. Also pull the Phase 3 instrumentation
  gap forward: the parser's windows-agent-horizon-pool-health RRD write never fires,
  so pool spare counts are not queryable from metrics, and several defects in this
  phase were visible only by operator inspection of the page.
- Inspection notes for whoever continues: confirm collector liveness from the
  systemd journal for the Horizon worker, never from RRD file timestamps, because
  rrdcached's write delay makes a healthy collector look stopped. Two Horizon pods
  are collected, each with its own display device and application id.
