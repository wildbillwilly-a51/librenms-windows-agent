# Codex Handoff

- Current objective: Maintain the generic LibreNMS Windows Agent and overlay
  release while keeping manual MSI installation straightforward and safe.
- Current state: Release 0.6.12 is committed and published. The MSI installs and
  starts the agent, while optional FactoryTalk Counter Monitor snapshots remain
  disabled by default. The project workflow has been migrated from v2 to v6.
- Next action: If requested, design a generic MSI installation option that
  explicitly enables the complete FactoryTalk feature set without manual JSON
  editing.
- Blockers: None for local development. No deployment to a Windows endpoint or
  LibreNMS node has been authorized by this setup task.
- Important decisions: Keep the repository generic and public-safe; preserve
  existing RRD schemas; keep native Counter Monitor collection opt-in and
  localhost-only; require explicit authorization before deployment.
- Branch/commit/sync: `main` at public release commit `c0bd725`; this handoff
  belongs to its containing workflow-migration commit, whose synchronization
  result must be recorded in the task report.
- Validation complete: Release 0.6.12 build/test/package evidence from the
  preceding work package and deterministic v6 managed-payload application.
- Validation remaining: v6 check mode, scoped diff review, commit, and GitHub
  synchronization result.
