# Codex Handoff

- Objective: Publish/deploy approved corrective overlay 0.6.33 and complete
  SQL tab verification with Claude, keeping the agent release separate.
- Current State: 0.6.33 packages reviewed correction `ba763ee`, preserving
  native graph HTML and core lazy loading. It supersedes deployed 0.6.32,
  whose live SQL tooltip markup broke tab parentage. Installer/capability
  defaults and checksum target the new immutable payload. Agent 0.6.17 and
  previous overlay artifacts remain byte-identical. No rules are enabled.
- Validation Completed: 93 portable checks, four extracted-package PHP suites,
  82 packaged PHP lints, 83 payload/source comparisons and preserved RRD
  definitions. Native golden regression catches immutable 0.6.32; correction
  passes 39 page fixtures and ten source-bound generic browser runs with native
  graph shape, tooltip script and verified raw production styles. Claude
  source/four-image review found no blocker. Installed-core/schema and normal
  canary metrics/eight graph types were verified during the 0.6.32 rollout.
- Relevant Decisions: New release/deploy explicitly approved; no .32 recut.
  Agent rollout, optional query collection and alerts remain separate. SQL
  qualification is not dwell/held-incident state. Actual alert lifecycle and
  sustained contention remain pilot work.
- Next Action: Finish public snapshot/publication checks, deploy exact 0.6.33
  through the management updater and repeat live tab/tooltip/graph acceptance.
