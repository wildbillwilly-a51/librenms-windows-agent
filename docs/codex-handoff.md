# Codex Handoff

- Objective: Execute `docs/app-page-ux-plan.md`. Phase 0 (Horizon machine-state and
  pool-capacity correctness) and Phase 1 (uniform tab contract and shared summary
  renderer) are complete. The most recent work is a field-driven fix to how Horizon
  conditions explain themselves, published as overlay 0.6.28.
- Current state: Overlay 0.6.28 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes are
  untouched, so this is overlay-only. Horizon "Conditions requiring attention" rows
  no longer ship a hardcoded evidence sentence. Connection Server (member), standalone
  gateway, and directory conditions now carry evidence built from the affected
  object's own fields, so an invalid Connection Server certificate reads
  `active certificate invalid · Horizon status=OK · role=… · v…` instead of the
  generic "health or redundancy is degraded." The Connection Server drawer renders an
  invalid certificate in red with a certificate-specific next action, and the member
  and gateway reason codes now have curated labels.
- Relevant decisions: the fix was diagnosed and confirmed against the live collector
  snapshot before any change, because the flagged host turned out to be a Connection
  Server with an embedded gateway role (not a standalone gateway, so the gateway path
  was the wrong target). Evidence is built from fields the collector already had, so
  no new API calls. No RRD schema, protocol, or application identity change.
- Validation completed: 33 central collector tests (added a member-certificate
  evidence assertion and a gateway evidence assertion), 11 parser fixtures, 11
  app-page fixtures, full overlay PHP lint, and `bash -n install.sh`, all exit 0 with
  stderr inspected. The exact post-deploy evidence string was verified by running the
  real affected member row through the updated collector method. The packaged
  `capabilities.json` reports overlay 0.6.28 and the new
  `horizon_condition_reason_evidence` capability, and `SHA256SUMS` matches an
  independent hash of the artifact.
- Field note: the confirmed certificate condition is real but non-impairing and will
  keep reporting critical until the certificate on that Connection Server is renewed
  or rebound; the operator owns that remediation. Rollout of 0.6.28 to overlay nodes
  is the operator's to schedule; publishing changes no deployed node.
- Deferred: the parser's `windows-agent-horizon-pool-health` RRD write never fires, so
  pool spare counts are not queryable from metrics; per-pool by-name alerting is a
  separate, approval-gated feature.
- Inspection notes for whoever continues: confirm collector liveness from the systemd
  journal for the Horizon worker, never from RRD file timestamps, because rrdcached's
  write delay makes a healthy collector look stopped. Two Horizon pods are collected,
  each with its own display device and application id.
