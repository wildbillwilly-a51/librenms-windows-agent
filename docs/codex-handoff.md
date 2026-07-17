# Codex Handoff

- Current objective: Standardize Horizon and FactoryTalk operational
  presentation and define the next safe Horizon monitoring phases.
- Current state: The 0.6.13 overlay presents both FactoryTalk and Horizon with
  the same compact status, metric, issue, raw-diagnostics, and trend hierarchy.
  Horizon issues are limited to the collector's automatic-service, required TCP
  443, expired-certificate, and critical-expiry health rules. A public Horizon
  monitoring design records credential-free runtime telemetry as the next
  addition and authenticated API/Event Database collection as a separate
  pod-level phase.
- Next action: Reapply the updated overlay to the LibreNMS web/poller nodes,
  observe the Horizon view after a poll, and then implement the additive local
  Horizon runtime sections and graphs if field data confirms the inventory
  classification.
- Blockers: None for local development or publication. No deployment is
  authorized yet.
- Important decisions: Keep the repository generic and public-safe; preserve
  existing RRD schemas; share bounded local process sampling rather than copy
  product-specific implementations; keep runtime metrics informational; and
  require a separate least-privilege credential design for Horizon pod APIs.
  No endpoint or LibreNMS deployment is authorized by local implementation.
- Branch/commit/sync: `main`; this handoff's containing Horizon usability commit
  is the public 0.6.13 overlay synchronization reference.
- Validation complete: full source and packaged PHP lint, nine parser fixtures,
  nine app-page fixtures, package build/listing, checksum verification, and
  healthy/critical desktop plus healthy mobile headless rendering pass. Visual
  inspection confirms six compact metrics, collapsed raw inventory, no yellow
  alert panels, and no desktop or mobile overflow. FactoryTalk fixtures still
  pass after the shared style refactor.
- Validation remaining after containing-commit sync: authorized overlay
  installation and post-poll browser observation on a Horizon device only.
