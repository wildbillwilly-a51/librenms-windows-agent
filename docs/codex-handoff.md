# Codex Handoff

- Current objective: Maintain the generic LibreNMS Windows Agent and overlay
  release while keeping manual MSI installation straightforward and safe.
- Current state: The repaired in-place release 0.6.13 enables the complete
  bounded FactoryTalk feature set, supports same-version upgrades, keeps failed
  upgrades rollback-safe, and no longer uses agent PowerShell custom actions.
  Windows Installer owns default configuration, service startup, and firewall
  registration. The installer wrapper retains the native-counter opt-out.
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
- Validation complete: Release tests/build, MSI upgrade/native-service/config/
  firewall table assertions, decompiled payload inspection, extracted config
  validation, collector execution, listener bind/response, script parsing, and
  checksum verification.
- Validation remaining after containing-commit sync: authorized pilot endpoint
  reinstall and normal post-install observation only.
