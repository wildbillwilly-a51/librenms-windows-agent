# Codex Handoff

- Objective: Implement the overlay foundation and richer existing-data SQL controls
  with Claude review, keeping agent work separate. Complete on
  `codex/sql-overlay-controls`, based on research `7bbfa42` and SQL tab `27c824e`.
- Current State: Native instance/issue filters, stable attention/deep links and
  Performance/Services/Collection disclosures retain global attention/coverage.
  Shared SQL evaluation drives page qualification and additive `sql_q_*` metrics
  with absolute expiry, live clock and explicit missing/reset handling. Contract:
  `docs/sql-qualified-metrics.md`. Existing metrics and RRD schemas are preserved.
  Agent, installer, versions/checksums and published payloads are unchanged.
  No rule activation, GitHub publication or deployment performed.
- Relevant Decisions: The final save re-reads the locked application row, preserves
  sibling data and re-projects all authoritative central Horizon scalar/pool
  fields before metric replacement. This closes the modeled stale-copy overwrite
  and omission path, including central-only health/vendor fields. Core-like metric
  behavior is modeled in deterministic fixtures; real locking is not certified.
  SQL expiry metadata is not distinct-sample dwell, event history or held-incident
  state. Optional database/log/job/backup collection remains separate agent work.
- Validation Completed: Claude plan/source/pixel reviews and correction review;
  32 qualification assertions, 39 parser/39 page fixtures, 35 Horizon checks and
  88 PHP syntax results. Ten source-bound Edge runs/captures cover filters,
  navigation, reload, keyboard, no-JavaScript GET, malformed input, origin guard,
  mobile and scoped dark styles. Temporary package: 83 payload files match source,
  82 packaged PHP lint passes. Unchanged graph math evidence reused with source/PNG
  hash checks and lazy loading verified. Documentation/public-safety/diff checks
  passed. Agent/MSI and release builds are inapplicable to this unreleased batch.
- Validation Remaining: Installed-core/schema and epoch precision, actual database
  locking/deadlocks, production theme/print-graphrow integration and alert lifecycle
  remain rollout/pilot gates. Source-mode changes during RRD work still use the
  earlier flag. No independent database/job incident semantics are claimed.
