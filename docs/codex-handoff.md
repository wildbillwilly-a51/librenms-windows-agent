# Codex Handoff

- Current objective: Validate and release the centralized, read-only Horizon
  pod collector after a controlled non-production test.
- Current state: The source tree contains a complete central PHP collector,
  configuration helper, package integration, application-data merge, stale
  handling, unknown-on-stale RRD behavior, compact UI, tests, and documentation.
  It queries each configured site pod once from LibreNMS, beginning with
  `<site>-vcs1` and then `<site>-vcs2`, and extends failover with healthy
  API-discovered Connection Servers. Windows agents remain credential-free and
  continue reporting local Horizon telemetry independently.
- Next action: After explicit deployment authorization, install the candidate
  overlay on the required LibreNMS nodes, configure the centralized collector
  only on the designated active node using private site values and the
  read-only credential prompt, run one manual API test against the
  non-production pod, and exercise collector failover without changing Horizon
  services, DNS, firewall, or server configuration. Enable the five-minute cron
  only after the manual result and LibreNMS presentation are accepted; then
  promote the next release.
- Blockers: The local candidate is ready. Live overlay installation, credential
  entry, Horizon authentication, cron enablement, and failover testing require
  the user's explicit deployment authorization. No live action has occurred.
- Important decisions: There is no new service, vault, database, or Windows
  credential distribution. Credentials live only in the protected LibreNMS
  `.env`; pod definitions are non-secret. Windows/Microsoft AD, Horizon AD LDS
  replication, and Horizon member-to-Microsoft-AD access stay separate. Only
  instant/linked-clone unused capacity is scored, and incomplete/stale data
  cannot become a false healthy zero.
- Branch/commit/sync: `main`; the implementation is preserved in the containing
  scoped commit. Use the audited GitHub sync workflow after committing; do not
  bypass a history-audit failure with a raw push.
- Validation complete: PHP lint; centralized security, failover, identity,
  pagination, threshold, stale-retention, absent-config, and atomic-config
  tests; all ten parser and ten app-page fixtures; all 59 agent tests; shell
  syntax; candidate overlay build and contents; Git whitespace checks; and a
  local browser render with no PHP or browser-console warnings. Temporary
  candidate overlay SHA256:
  `f73334cb9fa585e0d8f49a9edb6200889fae201989f68121532d93c3437e1de2`.
- Validation remaining: Live read-only API behavior, failover, scheduled
  polling, and LibreNMS display remain the protected non-production validation
  phase. No MSI is required unless Windows-agent code changes independently.
