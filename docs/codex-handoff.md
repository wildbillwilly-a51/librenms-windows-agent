# Codex Handoff

- Objective: Publish overlay 0.6.32, deploy with operator authorization and
  verify the SQL tab with Claude; keep the agent release separate.
- Current State: Overlay-only package and installer default are 0.6.32. SQL
  role detection, filters/disclosures, separate-unit existing-RRD trends and
  additive qualified metrics are included. Agent 0.6.17, installer and previous
  artifacts are preserved. No rules are enabled. Contract:
  `docs/sql-qualified-metrics.md`.
- Validation Completed: 93 portable checks; extracted-package qualification,
  parser/page and Horizon suites; 82 packaged PHP lint checks; 83 source/payload
  comparisons and unchanged SQL RRD definitions. Earlier source-bound ten-case
  browser/graph evidence remains applicable. Installed-core schema/precision
  and save-seam rollback check passed with application/metric rows unchanged.
  Claude reviewed the implementation and release plan.
- Relevant Decisions: Qualification expiry is observation validity, not dwell
  or held incident state. Optional SQL query collection stays separate agent
  work. Actual alert lifecycle and sustained DB contention remain pilot checks.
- Next Action: Finish public snapshot/peer checks, publish immutable 0.6.32,
  deploy and verify production SQL controls, metrics and graphs.
