# Codex Handoff

- Objective: Implement the existing-data SQL Server tab with Claude design and
  visual review, keeping all agent changes separate. Implementation complete.
- Current State: Unreleased overlay source is on `codex/sql-server-tab`, based on
  research commit `5d62251`. An engine-evidence-gated first-level SQL tab has
  five decision tiles, named attention, explicit coverage/freshness and native
  instance disclosures. One issue model also drives Overview; generic Roles
  keeps a pointer. Five separate-unit performance views use existing RRD DS.
  Agent, protocol, metrics/schema, installer, versions/checksums and published
  payloads are unchanged. No release or deployment performed.
- Relevant Decisions: Preserve AD/FactoryTalk/Horizon/SQL ordering and first-role
  landing behavior. With failed collection and no engine rows, show unknown SQL
  collection in Overview/Role inventory and omit the tab; no last-good persistence
  added. UI freshness uses a documented conservative 15-minute limit. Existing
  pressure flags remain the policy source. Native lazy images and negative/bin
  masking preserve missing evidence. Job deltas remain qualified sampled values.
- Validation Completed: Claude design, source and pixel reviews reconciled; its
  final review found no remaining blocker. Thirty page and thirty parser fixtures,
  35 Horizon checks, 81 PHP syntax results, five real RRD PNG/xport checks and nine
  source/image-bound Edge views passed. Desktop/mobile and light/dark fixture
  checks cover keyboard evidence, tab navigation, lazy graphs and no clipping or
  relevant browser errors. Read-only inspection confirmed existing canary RRD
  MIN/MAX/AVERAGE archives. Temporary package: 80 payload files match source,
  eight new SQL PHP files present and all 79 packaged PHP files linted. Complete
  public-safety and diff checks passed. Local save uses explicit scoped Git because
  the save helper does not support linked worktrees.
- Validation Remaining: Production theme and print-graphrow integration are rollout
  checks. Agent/MSI tests are inapplicable to this overlay-only change. Additional
  agent collection remains a separate proposal; no SQL queries or grants added.
