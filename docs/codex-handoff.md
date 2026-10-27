# Codex Handoff

- Objective: Research a dedicated auto-detected SQL Server role tab with Claude,
  selecting existing forefront signals and valuable additional agent collection.
- Current State: Proposal and SQL-scoped stored-versus-rendered audit are in
  `docs/sql-server-role-tab-research.md`; the application-page and collector
  roadmaps link the revised priorities. No runtime, config, version, installer,
  checksum or artifact changes. Published baseline remains recorded in
  `CURRENT-STATE.md`; agent 0.6.17 and overlay 0.6.31 are the research baseline.
- Relevant Decisions: Five current tiles: engines, local listeners, scheduled
  work, blocking and workspace grants; coverage beside the verdict. Use engine
  evidence for tab detection, one issue/verdict model and unit-separated trends
  over existing RRD data. Agent proposals start with capability results and
  cadence-safe cumulative observations, followed by optional local integrated
  database/log/backup/job evidence. No automatic grants, new alert rules or
  assumed backup policies. Broad per-database counters and ERRORLOG parsing are
  not prerequisites; a narrow PDH log-capacity alternative remains useful.
- Validation Completed: Source audit, advisory-report review, Microsoft and
  LibreNMS primary-source verification, Redgate comparison, two Claude review
  exchanges, reconciled documentation corrections, Markdown/local-link and
  scoped public-safety checks. No runtime tests needed for this docs-only change.
- Validation Remaining: Proposed collection needs restricted-permission, reset/cadence,
  cardinality and overhead testing during an implementation task; no such
  runtime capability is claimed here.
