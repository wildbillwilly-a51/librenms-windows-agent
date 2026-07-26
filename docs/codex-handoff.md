# Codex Handoff

- Objective: Release generic overlay 0.6.16 with cluster-safe Horizon poll
  triggers, central collection, fallback scheduling, pod discovery, and a
  capability contract; stop before any live deployment.
- Current state: The release is implemented, packaged, documented, and covered
  by generic fixtures. Normal and explicit application polls share a
  credential-free Redis trigger path. A designated collector worker validates
  local pod registrations, owns central RRD writes, deduplicates collection
  with distributed locks/cooldowns, retains last-good snapshots, and runs from
  both triggers and an independent five-minute fallback. Discovery is
  preview-first, add-only, strict-TLS, identity-validated, and preserves
  existing choices.
- Relevant decisions: Installation alone remains inert. Pollers never receive
  Horizon credentials, protected pod files, or private CA trust. The display
  device is a stable UI/data anchor rather than an availability gate. The
  Windows agent/MSI remains 0.6.14 because endpoint behavior did not change.
- Validation completed: All 59 agent tests, ten parser fixtures, ten app-page
  fixtures, expanded central trigger/worker/discovery fixtures, PHP lint, Bash
  syntax, ShellCheck, PowerShell parsing, overlay release build, package
  contents, checksum verification, and Git whitespace checks passed. Overlay
  SHA256:
  `677f40a7a03c1547f5c27ee32bb6a44126e83252d474ac6d4d67300652cd5285`.
- Validation remaining: Live installation, worker enablement, pod
  reconciliation, device polling, Redis/DB mutation, authenticated API
  collection, failover, fallback, and publication validation remain gated on a
  fresh explicit deployment approval.
- Next action: Wait at the explicit deployment approval checkpoint; do not
  change live systems without fresh authorization.
