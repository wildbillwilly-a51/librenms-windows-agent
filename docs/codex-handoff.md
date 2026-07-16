# Codex Handoff

- Current objective: Maintain the generic LibreNMS Windows Agent and overlay
  release while keeping manual MSI installation straightforward and safe.
- Current state: Release 0.6.13 enables the complete bounded FactoryTalk feature
  set on normal MSI installs and upgrades, including localhost Counter Monitor
  snapshots. `ENABLE_FACTORYTALK_NATIVE_COUNTERS=0` is the explicit opt-out.
- Next action: Install the 0.6.13 MSI on an authorized FactoryTalk pilot and
  update the overlay on each applicable LibreNMS node.
- Blockers: None for local development. No deployment to a Windows endpoint or
  LibreNMS node has been authorized by this setup task.
- Important decisions: Keep the repository generic and public-safe; preserve
  existing RRD schemas; keep native Counter Monitor localhost-only, bounded,
  non-alerting, and explicitly disableable; require explicit authorization
  before endpoint deployment.
- Branch/commit/sync: `main`; release 0.6.13 is the current public distribution.
- Validation complete: Release build, tests, package inspection, script parsing,
  checksum verification, and public-safety scanning for 0.6.13.
- Validation remaining: Pilot endpoint install and normal post-install
  observation are operational steps outside repository release validation.
