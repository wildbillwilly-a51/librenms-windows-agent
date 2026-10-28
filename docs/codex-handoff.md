# Codex Handoff

- Objective: Investigate SQL alerting possibilities, useful app-page content and
  dropdown/expandable details with Claude. Investigation complete; retained in
  `docs/sql-server-alerting-and-details-design.md`. No implementation authorized
  by this investigation beyond documentation.
- Current State: `codex/sql-alerting-design` builds on completed, unreleased SQL
  tab commit `27c824e`. The proposal prioritizes alert validity/shared evaluation,
  then separate optional database/log/job/backup collection. Agent source,
  permissions, rules, RRD schemas, versions, installers and release payloads are
  unchanged; no GitHub publication or deployment performed.
- Relevant Decisions: Keep current evidence/legacy metric contracts, add qualified
  fields later, and assign cross-poll sample/dwell state to a bounded persisted
  overlay evaluator. Unknown/partial/expired data must not imply healthy recovery.
  New policy has one operator-owned source; existing pressure limits remain
  labeled agent policy. Dropdowns filter current scope; native disclosures show
  evidence while global attention/coverage stay visible. Existing graphs remain
  host aggregates. Future state saving requires a locked save-time re-read,
  idempotent transitions and a shared projection for overlapping Horizon metrics;
  current stale-snapshot overwrite is a source-identified possibility, not an
  observed production loss. No query or alert is enabled by this proposal.
- Validation Completed: Claude source/design critique and reconciliation;
  Microsoft/LibreNMS primary-source checks; pinned upstream core/storage/alert
  behavior and local reset/metric semantics; documentation links, public-safety
  snapshot and diff checks. Earlier tab validation remains recorded in work log:
  30 page/30 parser fixtures, 35 Horizon checks, 81 PHP lint results, five actual
  RRD renders/exports and nine source-bound browser views.
- Validation Remaining: Future implementation must test installed core/schema,
  clock/expiry, bad/unknown/good lifecycle, fault identity, persistence/idempotence,
  query permissions/limits/overhead and dropdown accessibility/themes. Existing
  tab production theme/print-graphrow integration remains a rollout check.
  No runtime, build or visual tests were added for this documentation-only work.
