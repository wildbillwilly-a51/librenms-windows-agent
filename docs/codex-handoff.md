# Codex Handoff

- Objective: Define and approve the next Horizon collector and operational UI
  iteration before implementation.
- Current state: Overlay 0.6.17 is released and downstream validation is clean.
  The next collector requirements are locked in
  `docs/horizon-monitoring.md`: bounded unhealthy service details, collector
  self-observability, per-pool capacity policy, pod-name fallback, and
  non-alerting defaults. Implementation is paused at the UI design gate.
- Relevant decisions: Installation alone remains inert. Pollers never receive
  Horizon credentials, protected pod files, or private CA trust. The display
  device is a stable UI/data anchor rather than an availability gate. The
  Windows agent/MSI remains 0.6.14 because endpoint behavior did not change.
- Validation completed: All 59 agent tests, ten parser fixtures, central
  trigger/worker/discovery tests, source PHP lint, Bash syntax, ShellCheck,
  overlay-only release build, package contents, checksum verification, and Git
  whitespace checks passed. Overlay SHA-256:
  `3909e53f592e148282d6b4ff092a07b43a8184dd1f8f8ef1ed3fee087f7dd187`.
- Downstream validation: A full Windows-agent poll on 0.6.17 completed with
  zero PHP errors and zero indirect-modification warnings while preserving the
  central snapshot data.
- Next action: Agree on Horizon operational information architecture and
  visual direction, then create and approve a complete desktop concept before
  changing collector or UI code.
