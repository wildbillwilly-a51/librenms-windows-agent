# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md` phase by phase. Phase 0, Horizon
  machine state and pool capacity correctness, is published as overlay 0.6.22.
- Current state: Overlay 0.6.22 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes are
  untouched, so this is an overlay-only release. One state taxonomy now decides
  independently whether a machine is placement capacity, how severe its own state
  is, whether it counts as a problem machine, and whether the state was recognized.
  Full utilisation reports capacity exhaustion rather than a fault; withheld and
  still-becoming-ready spares no longer score as failures; only faulted spares with
  no ready capacity remaining can drive a pool critical; an unrecognized state
  reports incomplete and is excluded from scoring. The published state distribution
  carries each state's capacity treatment, severity, and issue flag, and the page
  shows them.
- Relevant decisions: the per-machine severity and the aggregate counts derive from
  the same table so a row can never disagree with the totals. The problem-machine
  definition follows the vendor's documented problem-VM list so the vendor
  comparison metric can converge. Session presence affects placement only and never
  suppresses a fault. Maintenance is a deliberate operator action, so a withheld
  machine is informational rather than a problem. No RRD schema, protocol, or
  application identity change; the new spare breakdown is published as section
  fields only.
- Validation completed: 29 central collector tests, 11 parser fixtures, 11 app-page
  fixtures, 69 overlay PHP files linted, overlay test runners linted, and
  `bash -n install.sh`, all with exit code 0 and stderr inspected rather than piped
  through `tail`. Packaged-artifact verification confirmed the taxonomy and new
  reason codes ship, both dead functions are absent, the capability manifest reports
  the new version and capability with an unchanged private integration range, 69
  packaged PHP files lint clean, the packaged shell scripts parse, and
  `manifest.txt` reconciles with its 70 payload files. Preserved agent artifact
  hashes were byte-identical and no artifact for this version existed beforehand.
- Validation remaining: the Phase 0 field oracle. After 0.6.22 is applied to overlay
  nodes, the collector's vendor problem-machine mismatch metric should trend toward
  zero and the capacity health scope should vary with the environment instead of
  holding one value. A fully utilised healthy pool must no longer report a fault.
  Any state the Horizon machine state inventory lists as unrecognized is a gap in
  the taxonomy worth reporting.
- Next action: apply overlay 0.6.22 to overlay nodes, then read the oracle above. If
  the mismatch metric does not move, that is a hard blocker for the rest of the
  roadmap and Phase 1 should not start until it is understood. Phase 1, the tab
  contract and shared summary renderer, is the next phase otherwise.
