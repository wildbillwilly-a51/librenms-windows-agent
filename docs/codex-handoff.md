# Codex Handoff

- Objective: Release overlay-only patch 0.6.17 without changing the Windows
  agent/MSI.
- Current state: The patch removes a redundant Eloquent cast-property mutation
  that emitted PHP warnings during a production Windows-agent poll. The
  fixture-backed merge path already preserves central Horizon data, so the
  removal does not change the resulting application data. Normal and explicit
  application polls continue to share a
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
- Validation completed: All 59 agent tests, ten parser fixtures, central
  trigger/worker/discovery tests, source PHP lint, Bash syntax, ShellCheck,
  overlay-only release build, package contents, checksum verification, and Git
  whitespace checks passed. Overlay SHA-256:
  `3909e53f592e148282d6b4ff092a07b43a8184dd1f8f8ef1ed3fee087f7dd187`.
- Validation remaining: Post-install production poll validation.
- Next action: Publish 0.6.17, deploy it through the already approved
  rollback-protected private workflow, and repeat the production poll
  validation.
