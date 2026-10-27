# Codex Handoff

- Objective: Release Windows agent 0.6.17 (SQL Server collector accuracy and
  credential-free depth) without disturbing the published overlay 0.6.31.
- Current State: Agent 0.6.17 is committed as an agent-only release on top of
  the overlay 0.6.31 publication. `SHA256SUMS` lists overlay 0.6.31 (unchanged,
  `fb8c88c844bcfdcfcc98a35674befb25327256c9b19c79d4a0a377a486fda34b`), config
  0.6.17, and MSI 0.6.17
  (`8e588074498671a3772bb8ca5520f0734cba23d430b66eb04458038a411a96c9`).
  `install-agent.ps1` defaults to 0.6.17 and runs on Windows PowerShell 4.0
  again. The MSI was canary-installed through `install-agent.ps1` on a
  multi-instance SQL Server 2017 host running Windows Server 2012 R2 before
  this commit; those exact bytes are the ones committed. Publication does not
  deploy or update any node.
- Relevant Decisions: Preserve protocol/app identity and existing RRD schemas.
  Agent and overlay version independently; `build-release.ps1` in combined mode
  forces them equal, so agent-only (`-AgentOnly -OverlayVersion 0.6.31`) and
  overlay-only releases are the way to ship differing versions. SQL Agent
  missing/first-sample deltas remain `-1`; totals include readable instances
  only; job deltas are sampled evidence, not durable event history. SQL Agent
  counters are read only while the Agent service runs, because the counter
  category outlives the service. Per-user LocalDB is reported apart from
  unowned engines. No alerts enabled.
- Validation Completed: Agent tests and the installer integration test inside
  `build-release.ps1`; independent checksum match for all three `SHA256SUMS`
  lines; MSI UpgradeCode unchanged with a new ProductCode; canary upgrade from
  0.6.16 with MSI status 0, no `Return value 3`, one registered product,
  service running, configuration semantically unchanged, all four SQL sections
  live with no missing counters, and a production poll served afterwards.
- Validation Remaining: The elevated installer acceptance suite (it uninstalls,
  so it needs a disposable host); configuration preservation on a customized
  config (the canary's config equalled the default template); rendering of the
  new payload by a deployed overlay 0.6.31.
