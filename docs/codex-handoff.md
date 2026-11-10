# Codex Handoff

## Horizon pool alert reporter (overlay 0.6.34)

- Objective: one alerting home per Horizon pod for the per-pool
  `horizon_pool_*` metrics, with failover, without per-pool rule machinery.
- Current State: Released in overlay 0.6.34
  (`includes/windows-agent/horizon-pool-reporter.inc.php`, gate in
  `ApplicationSave`/`HorizonProjection::pollerMetrics`, list stamping and
  cleanup queue in the collector, `pod reporters` and one `{site}` template in
  the helper). Nothing changes until reporters are configured.
- Relevant Decisions: strict priority order; "up" equals `macros.device_up`
  plus an active windows-agent app. Native `update_application` deletes omitted
  metrics, which removes a non-reporter's pool rows at its next poll.
- Validation Completed: see the 2026-11-10 work-log entry.
- Validation Remaining: deployment and live handover timing are the operator's.

