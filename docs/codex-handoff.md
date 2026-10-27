# Codex Handoff

- Objective: Publish overlay 0.6.31 while preserving separate agent release work.
- Current State: Overlay 0.6.31 is published on GitHub main and is the installer
  default. Its reviewed package bytes are unchanged, SHA256
  `fb8c88c844bcfdcfcc98a35674befb25327256c9b19c79d4a0a377a486fda34b`.
  Public MSI/config and Windows installer default remain 0.6.16. The next agent
  release remains separate and must retain the 0.6.31 overlay checksum when
  promoted. Publication does not deploy or update any node.
- Relevant Decisions: Preserve protocol/app identity and existing RRD schemas.
  SQL Agent missing/first-sample deltas remain `-1`; totals include readable
  instances only. Job deltas are sampled evidence, not durable event history.
  Claude reviewed both code and publication coordination. The agent collector
  contract was reconciled against current source before publication; separate
  agent bootstrap/version edits are outside this snapshot. No alerts enabled.
- Validation Completed: Agent suite 93/93; parser/app-page 15/15 each; Horizon
  35/35; source/package lint 71/71 each; shell syntax and diff checks; all 72
  manifest entries match source; independent checksums and published agent byte
  preservation; original SQL RRD schema and new graph dataset/name checks.
  Candidate public scan covered 263 files and 2,371 files/archive members with
  no findings. Publication documentation receives an exact-tree safety scan.
- Validation Remaining: Deployed SQL/UI/graph checks require a separately
  authorized rollout; installed-agent verification belongs to its release.
