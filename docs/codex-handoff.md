# Codex Handoff

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
