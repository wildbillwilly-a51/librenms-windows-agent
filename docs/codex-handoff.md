# Codex Handoff

## Horizon pool alert reporter

- Objective: one alerting home per Horizon pod for the per-pool
  `horizon_pool_*` metrics, with failover, without per-pool rule machinery.
- Current State: Implemented locally on branch `horizon-pool-reporter`
  (`includes/windows-agent/horizon-pool-reporter.inc.php`, gate in
  `ApplicationSave`/`HorizonProjection::pollerMetrics`, list stamping and
  cleanup queue in the collector, `pod reporters` in the helper, one
  `{site}` template for all pods). Default off.
- Relevant Decisions: strict priority order; "up" equals `macros.device_up`
  plus an active windows-agent app; supersedes enabling the 2026-11-04
  `pool_alerts` observations for the same pod (validation rejects both).
- Validation Completed: see the 2026-11-10 work-log entry.
- Validation Remaining: confirm LibreNMS core deletes omitted application
  metrics; staging or live handover timing; release and rollout.

- Objective: Publish/deploy overlay and verify SQL tab with Claude while
  preserving the agent release. Completed with approved corrective 0.6.33.
- Current State: Public/private main release `f5176d2`; overlay installer default
  0.6.33, agent 0.6.17 unchanged. Native graph HTML/lazy loading is preserved.
  This supersedes 0.6.32, whose live tooltip markup disrupted tab layout.
  Prior overlay and agent artifacts remain immutable. No alert rules enabled.
- Validation Completed: 93 portable checks, four extracted-package PHP suites,
  82 packaged PHP lints, 83 source/payload comparisons, preserved agent/RRD
  definitions and a clean full public scan. All 22 public URL checks match.
  Installed/staged deployment files and services pass; updater is current.
  Normal polling retains 33 qualified metrics, correct expiry/missing-zero
  semantics, eight rendered SQL graph types and three fresh SQL RRD families.
  Live tab/tooltip/filter/disclosure/Overview/deep-link, native icon, console
  and mobile checks pass. Native golden regression catches immutable 0.6.32;
  ten source-bound generic browser runs pass. Claude reviewed correction source
  and four generic images with no blocking issue; lead verified actual UI.
- Relevant Decisions: Agent release/rollout and optional SQL query collection
  stay separate. Qualification is observation validity, not dwell, event
  history or held incident state. Sustained DB contention and actual alert
  lifecycle remain optional pilot work; these do not block this overlay release.
