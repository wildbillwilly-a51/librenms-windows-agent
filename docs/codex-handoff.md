# Codex Handoff

- Current objective: Publish overlay release 0.6.15, then validate the
  centralized read-only Horizon pod collector on the non-production pod.
- Current state: Overlay 0.6.15 contains a complete central PHP collector,
  configuration helper, package integration, application-data merge, stale
  handling, unknown-on-stale RRD behavior, compact UI, tests, and documentation.
  It queries each configured site pod once from LibreNMS, beginning with
  `<site>-vcs1` and then `<site>-vcs2`, and extends failover with healthy
  API-discovered Connection Servers. Windows agents remain credential-free and
  continue reporting local Horizon telemetry independently.
- Next action: Install overlay 0.6.15 through the unchanged one-command
  installer on the required LibreNMS nodes, configure the centralized collector
  only on the designated active node using private site values and the
  read-only credential prompt, run one manual API test against the
  non-production pod, and exercise collector failover without changing Horizon
  services, DNS, firewall, or server configuration. Enable the five-minute cron
  only after the manual result and LibreNMS presentation are accepted.
- Blockers: Publication requires an authenticated GitHub CLI session. Live
  credential entry, Horizon authentication, cron enablement, and failover
  testing remain separate protected actions. No live action has occurred.
- Important decisions: There is no new service, vault, database, or Windows
  credential distribution. Credentials live only in the protected LibreNMS
  `.env`; pod definitions are non-secret. Windows/Microsoft AD, Horizon AD LDS
  replication, and Horizon member-to-Microsoft-AD access stay separate. Only
  instant/linked-clone unused capacity is scored, and incomplete/stale data
  cannot become a false healthy zero.
- Branch/commit/sync: `main`; preserve the 0.6.15 release in the containing
  scoped commit and publish it through the authenticated GitHub workflow.
- Validation complete: PHP lint; centralized security, failover, identity,
  pagination, threshold, stale-retention, absent-config, and atomic-config
  tests; no-edit pod lifecycle coverage; all ten parser and ten app-page
  fixtures; all 59 agent tests; shell syntax; candidate overlay build and
  contents; marker-verified rollback schedule cleanup; Git whitespace checks;
  and a local browser render with no PHP or browser-console warnings. Final
  overlay 0.6.15 SHA256:
  `295949ee3e3b19a062837d928b9658fbafb05c429fc9e6a6a5b884f20b9cf074`.
- Validation remaining: Public installer verification after publication,
  followed by live read-only API behavior, failover, scheduled
  polling, and LibreNMS display remain the protected non-production validation
  phase. No MSI is required unless Windows-agent code changes independently.
