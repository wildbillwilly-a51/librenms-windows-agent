# Changelog

## 0.6.19 - 2026-07-26

- Made the Horizon Operations workspace native to both LibreNMS light and dark
  themes, with responsive metric labels and consistent surfaces, borders,
  controls, tables, and detail drawers.
- Added a bounded, sanitized all-machine inventory so each expanded pool can
  filter real rows by all, issue, in-session, ready, or unavailable state.
  Every machine row now opens focused in-place detail.
- Replaced generic Connection Server navigation from conditions and platform
  health with in-place server detail, including unhealthy service evidence.
- Preserved the approved pool policy, 30-day default trend, non-alerting
  behavior, existing RRD schemas, and Windows agent/MSI version 0.6.14.

## 0.6.18 - 2026-07-25

- Added bounded unhealthy Connection Server service evidence, bounded
  selectable issue-machine evidence, pod-name fallback, and collector
  duration/endpoint/request/page/inventory/outcome observability.
- Replaced percentage-based central pool scoring with the approved count
  policy: one unavailable spare is informational while ready capacity remains,
  two or more is warning, and zero ready or zero placement capacity is
  critical.
- Rebuilt the Horizon application page as a problems-first operational
  workspace with expandable pools, issue-only filtering, a read-only machine
  evidence drawer, 24-hour/7-day/30-day trends with 30 days as the default,
  platform health, collector reliability, and complete lower diagnostics.
- Added an additive Horizon collector-health RRD family and a combined
  sessions/headroom graph without changing existing RRD schemas.
- Preserved local-only Horizon diagnostics when central API collection is not
  configured. All visibility remains non-alerting by default.
- Kept the Windows agent/MSI unchanged at 0.6.14.

## 0.6.17 - 2026-07-25

- Removed a redundant Eloquent cast-property mutation after central Horizon
  data had already been merged into the application model. Central data
  preservation is unchanged, while normal Windows-agent polls no longer emit
  `Indirect modification of overloaded property` PHP warnings.
- Kept the Windows agent/MSI unchanged at 0.6.14.

## 0.6.16 - 2026-07-25

- Added a credential-free Redis trigger producer to the shared
  `windows-agent` application polling path, so normal distributed polling and
  explicit device polling enqueue only the registered display device's site
  without contacting Horizon or failing the device poll.
- Added a collector-node trigger worker, per-site distributed lock and
  cooldown, and an independent five-minute systemd fallback. Triggered,
  fallback, and forced manual diagnostics now share one collection path.
- Made the centralized collector the sole writer for central Horizon RRD
  families. Application polls preserve the latest central snapshot without
  duplicate writes; failed refreshes retain last-good data and write unknown
  samples.
- Added generic preview-first, add-only pod discovery for
  `<site>-vcs<number>.<dns-suffix>` devices with application readiness,
  DNS/TLS/authentication, pod-identity, API-member, ambiguity, and existing-pod
  safeguards.
- Added the generic `capabilities.json` overlay/configuration/private-
  integration contract, worker lifecycle/status commands, legacy schedule
  aliases, unit-aware rollback, overlay-only release building, and expanded
  generic fixture coverage. Windows agent/MSI remains at 0.6.14.

## Workflow maintenance - 2026-07-25

- Migrated the managed Codex project workflow from the exact initial V6 cohort
  to local-first V7 without changing agent, overlay, installer, or release
  behavior.

## 0.6.15 - 2026-07-22

- Added an opt-in centralized Horizon API collector for one bounded query per
  pod from LibreNMS, with fixed VCS1/VCS2 bootstrap priority, validated dynamic
  Connection Server failover, gateway exclusion, pod-identity protection, and
  last-good stale-state retention.
- Added an atomic configuration helper for protected `.env` credentials,
  non-secret pod definitions, secure prompting, sanitized status, credential-
  free DNS/TLS tests, an explicitly invoked read-only API test, and optional
  five-minute cron management. No secrets are accepted on command lines.
- Made central Horizon snapshots take precedence over the disabled Windows-side
  API prototype while preserving local telemetry from every agent. Added stale
  UI/source timestamps, unknown RRD samples during failures, and fixture tests
  for security, failover, topology discovery, pagination, pool thresholds,
  stale retention, and upgrade-safe configuration behavior.
- Made rollback remove the marker-verified central Horizon cron entry when the
  collector itself is removed, while retaining credentials, pod definitions,
  and last-good state.
- Promoted the centralized Horizon implementation as overlay-only release
  0.6.15. The Windows agent and MSI remain at 0.6.14 because endpoint code did
  not change.
- Corrected the read-first current-state record to distinguish overlay 0.6.15
  from Windows agent/MSI 0.6.14 and to reflect the available WSL PHP validation
  environment.

## 2026-07-17

- Released 0.6.14 with the read-only Horizon integration as an independent
  `horizon_api` collector, local pod/member and gateway health, Horizon AD LDS
  configuration replication, separately named broker-to-Microsoft-AD access,
  and instant/linked-clone pool health.
- Added configurable 50% warning and 90% critical unready-spare thresholds,
  zero-ready-spare critical handling, incomplete-inventory safeguards,
  aggregate pool state visibility, compact UI disclosures, and a new additive
  Horizon platform RRD family. Alert rules remain opt-in and no live deployment
  is performed by the release build.

## 2026-07-16

- Began an uncommitted Horizon telemetry implementation: generalized the
  FactoryTalk process sampler for shared local runtime metrics, added additive
  Horizon runtime/API protocol sections and RRD families, and scaffolded a
  disabled-by-default read-only REST integration for Connection Server health
  and sanitized session aggregates using a machine-protected credential file.
- Standardized the Horizon application page on the compact FactoryTalk
  operational pattern with collector-confirmed health, focused service,
  listener, and certificate metrics, concise issue actions, collapsed raw
  inventory, and trend disclosure. Added a documented roadmap for local
  Horizon runtime metrics and an opt-in authenticated pod-level integration.
- Corrected the FactoryTalk operational view so only collector-scored health
  issues and stopped core services affect issue state. Removed speculative
  issue promotion for optional services/listeners, supplemental-data
  availability, cumulative counters, and transaction utilization, and replaced
  the oversized warning panel with a compact neutral status row.
- Reworked the FactoryTalk application page into an issue-first operational
  view with recommended next actions, six key metrics, top-five runtime
  processes, nested inventory/raw diagnostics, and secondary graph disclosure.
  All existing FactoryTalk data and graph families remain available and no
  alert behavior or RRD schema changed.
- Replaced the 0.6.13 agent PowerShell custom-action pipeline with native MSI
  configuration installation, service start, and WiX firewall tables. Existing
  configuration is preserved, the complete FactoryTalk defaults are installed
  directly, and listener binding failures now surface during service startup.
- Corrected the repaired 0.6.13 MSI configuration command so quoted directory
  properties cannot consume later PowerShell arguments. The installed script
  now derives its own install path and reads the MSI data path from the registry.
- Repaired the 0.6.13 MSI in place: same-version upgrades are supported, prior
  packages are removed inside the rollback boundary, preserved configurations
  receive the complete FactoryTalk settings, and service startup is now the
  authoritative installation success check.
- Promoted generic overlay and Windows MSI release 0.6.13. Normal MSI installs
  and upgrades now enable the complete bounded FactoryTalk collection set,
  including localhost Counter Monitor snapshots, with an installer-wrapper and
  configuration-file opt-out.
- Migrated the project-maintenance workflow from version 2 to version 6 with
  adaptive execution, proportional durable memory, and audited Git history.
- Added bounded FactoryTalk Windows-native process runtime metrics and new
  LibreNMS runtime graphs without changing the existing FactoryTalk health RRD.
- Added opt-in, localhost-only FactoryTalk Diagnostics Counter Monitor
  snapshots with Rockwell signature validation, concurrency protection,
  independent throttling, secure allowlist parsing, last-good caching, and raw
  XML cleanup. Native snapshots remain disabled and non-alerting by default.
- Added new FactoryTalk Linx connection, backplane, transaction, and Live Data
  protocol sections, RRD families, application details, and sanitized fixtures.
- Promoted generic overlay and Windows MSI release 0.6.12 with updated public
  installers and checksums.

## 2026-07-15

- Renamed the canonical product repository from
  `librenms-windows-agent-installer` to `librenms-windows-agent` and updated
  public installer defaults and documentation links for the unified agent,
  overlay, MSI, and distribution project.

## 2026-07-14

- Transitioned the repository into the canonical universal development source
  for the Windows agent, collectors, MSI, tests, and LibreNMS overlay.
- Added native generic build and release workflows and retired private-source
  identifier-conversion promotion.
- Synchronized the repository's low-intervention Codex workflow, including
  scoped work tracking, autonomous work packages, prototype and portable-resume
  guidance, and isolated sanitized-backup tooling.

## 2026-07-07

- Corrected the public README current-version examples so the direct MSI link and `msiexec` commands reference the promoted 0.6.8 MSI.

## 2026-07-06

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.6 from validated development commit b1b869c so FactoryTalk/public collector updates install through a real MSI version boundary.
- Corrected the public README to reference the promoted 0.6.6 MSI and the current 22-collector diagnostic output.

## 2026-07-05

- Added public README performance and scaling guidance for Windows-agent poller worker-time cost, rollout batches, and Poller Cluster Health checks.

## 2026-07-04

- Reworked the README into a step-by-step primary install/upgrade runbook with optional module overrides, overlay options, MSI options, rollback, collector expectations, and diagnostics moved into an addendum.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.5 from validated development commit 51180e3.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.4 from validated development commit c9e48c3.

- Updated current public instructions and installer docs to reference the promoted 0.6.3 MSI and overlay artifacts.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.3 from validated development commit 6d595f2.

- Updated current public instructions and installer docs to reference the promoted x64 0.6.2 MSI and overlay artifacts.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.2 from validated development commit db0126b.

- Hardened the Windows agent PowerShell installer to verify the actual installed executable, config, service, and file version before reporting success.
- Updated the promotion workflow so the Windows installer script default version is maintained automatically.

- Updated current public instructions and installer defaults to reference the promoted 0.6.1 MSI and overlay artifacts.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.1 from validated development commit b97c5c5.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 7338e23.

- Added prerequisite documentation for enabling LibreNMS `unix-agent` and
  `applications` poller modules globally or per device.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit bc23c4b.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit d279011.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 9a2a8ea.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 7e6f398.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 1e6a425.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 1b4b877.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.0 from validated development commit 1eec530.

- Promoted generic LibreNMS Windows Agent overlay package 0.6.0 from validated development overlay commit eedf0df.

- Added the initial public generic LibreNMS Windows Agent overlay installer.
- Added local-first project workflow tracking for installer maintenance.
- Added Codex-oriented project documentation and upstream sync guidance.
- Added the interim promotion workflow for converting validated development
  overlay packages into generic public installer releases.
- Updated the promotion workflow so installer repo commits automatically sync
  to GitHub after validation.

## 2026-07-05

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.5 from validated development commit 17acd26.

## 2026-07-06

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.6 from validated development commit b1b869c.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.5 from validated development commit 3a85b98.

## 2026-07-07

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.8 from validated development commit 9c9626a.

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.7 from validated development commit d8eb934.

## 2026-07-08

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.10 from validated development commit fca2b3f.

- Corrected the public README/current docs to reference the promoted 0.6.9 MSI and overlay artifacts, and hardened the promotion workflow so README/current-version references are updated and validated before commit.
- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.9 from validated development commit b212262.

## 2026-07-09

- Promoted generic LibreNMS Windows Agent overlay package and Windows MSI 0.6.11 from validated development commit 751f167.
