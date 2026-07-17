# Codex Handoff

- Current objective: Maintain the generic LibreNMS Windows Agent and overlay
  release while keeping manual MSI installation straightforward and safe.
- Current state: The repaired in-place release 0.6.13 enables the complete
  bounded FactoryTalk feature set, supports same-version upgrades, keeps failed
  upgrades rollback-safe, and requires the installed service to reach `Running`.
  `ENABLE_FACTORYTALK_NATIVE_COUNTERS=0` remains the explicit opt-out.
- Next action: Install the repaired 0.6.13 MSI on an authorized FactoryTalk
  pilot and observe service, listener, polling, and native snapshot state.
- Blockers: None for local development. No deployment to a Windows endpoint or
  LibreNMS node has been authorized by this setup task.
- Important decisions: Keep the repository generic and public-safe; preserve
  existing RRD schemas; keep native Counter Monitor localhost-only, bounded,
  non-alerting, and explicitly disableable; require explicit authorization
  before endpoint deployment.
- Branch/commit/sync: `main`; this handoff's containing repair commit is the
  public 0.6.13 synchronization reference.
- Validation complete: Exact preserved-config normalization under Windows
  PowerShell 5.1, release build/tests, warning-free MSI build, MSI upgrade-table
  assertions, package inspection, script parsing, and checksum verification.
- Validation remaining after containing-commit sync: authorized pilot endpoint
  reinstall and normal post-install observation only.
