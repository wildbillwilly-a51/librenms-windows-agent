# Codex Handoff

- Objective: Publish overlay 0.6.18, then use the approved management updater
  to deploy it only after every public and private preflight passes.
- Current state: Overlay 0.6.18 is implemented and packaged locally. It adds
  bounded service/machine evidence, collector observability, count-based pool
  policy, the problems-first Horizon Operations page, expandable pools,
  selectable issue-machine detail, 30-day-default trends, and additive graph
  families. The Windows agent/MSI remains 0.6.14. No live node has changed yet.
- Relevant decisions: One unavailable spare is informational while another is
  ready; two or more is warning; zero ready or zero placement capacity is
  critical. All visibility remains non-alerting. Installation remains inert,
  and pollers never receive Horizon credentials, protected pod files, or
  private CA trust.
- Validation completed: 59 .NET tests, ten parser fixtures, ten rendered-page
  fixtures, 20 central tests, full WSL PHP source/test lint, 68-file packaged
  PHP lint, Bash syntax, ShellCheck, JSON, whitespace, archive/manifest/hash
  checks, and browser interaction/visual QA passed. Overlay SHA-256:
  `6eb69b7c560b6958a596a117c38298c246bb720ad90700342c31608b1058e829`.
- Next action: Commit and public-safety scan the exact release snapshot, push
  it, then run the private management updater's read-only preflight before the
  conditionally approved deployment.
