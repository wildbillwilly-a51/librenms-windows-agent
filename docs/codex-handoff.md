# Codex Handoff

- Objective: Publish overlay 0.6.19 with the corrected Horizon Operations UI,
  then stop for explicit approval before any production deployment.
- Current state: Overlay 0.6.19 is implemented, packaged, and validated as the
  public release snapshot. It adds bounded all-machine inventory, useful pool filters,
  selectable detail for every machine, in-place Connection Server detail, and
  LibreNMS-native light/dark responsive styling. The Windows agent/MSI remains
  0.6.14. Production remains on overlay 0.6.18.
- Relevant decisions: One unavailable spare is informational while another is
  ready; two or more is warning; zero ready or zero placement capacity is
  critical. All visibility remains non-alerting. Installation remains inert,
  and pollers never receive Horizon credentials, protected pod files, or
  private CA trust.
- Validation completed: 59 .NET tests, ten parser fixtures, ten rendered-page
  fixtures, 20 central tests, full WSL source/test PHP lint, 68-file packaged
  PHP lint, Bash syntax, ShellCheck, JSON, whitespace, archive/manifest/version
  checks, and desktop dark/light plus mobile dark interaction/visual QA passed.
  Overlay SHA-256:
  `b1ebee731985199c0c6661b536a76aa5516b952d351d2b4d83be8977df93644b`.
- Next action: Obtain explicit approval before deploying 0.6.19 to production,
  then perform the private updater's read-only preflight and bounded deployment.
