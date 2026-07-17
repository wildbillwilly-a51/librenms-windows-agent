# Codex Handoff

- Current objective: Make the complete FactoryTalk metric set operationally
  useful in LibreNMS without changing collection, alerts, or RRD schemas.
- Current state: The 0.6.13 agent is polling successfully on the authorized
  FactoryTalk pilot. The overlay source now presents an issue-first FactoryTalk
  operational view with status, next action, key metrics, top processes, nested
  raw diagnostics, and primary/secondary graph disclosure. A follow-up
  correction limits issue state to collector-scored health and stopped core
  services, leaving optional and supplemental signals informational.
- Next action: With explicit confirmation, install the updated overlay on the
  LibreNMS web/poller nodes and verify the FactoryTalk view after a poll.
- Blockers: None for local development or publication. No deployment is
  authorized yet.
- Important decisions: Keep the repository generic and public-safe; preserve
  existing RRD schemas; keep native Counter Monitor localhost-only, bounded,
  non-alerting, and explicitly disableable; require explicit authorization
  before endpoint deployment.
- Branch/commit/sync: `main`; this handoff's containing FactoryTalk usability
  commit is the public 0.6.13 overlay synchronization reference.
- Validation complete: 53 portable agent tests, full source/packaged PHP lint,
  eight parser fixtures, eight app-page fixtures, package build/listing,
  manifest/payload inspection, checksum verification, and healthy-desktop plus
  warning-mobile headless rendering pass. The sanitized public snapshot passes;
  the generic history policy rejects the intentionally tracked release
  artifacts and work log, so the established direct sanitized push is required.
  The false-positive correction also passes a noisy-information zero-issue
  fixture and a stopped-core-service one-issue fixture.
- Validation remaining after containing-commit sync: authorized LibreNMS
  overlay installation and post-poll browser observation only.
