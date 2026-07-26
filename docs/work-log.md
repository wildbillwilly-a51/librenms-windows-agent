# Work Log

## 2026-07-26

- Implemented and packaged overlay 0.6.20 around an explicit Horizon health
  contract. Platform, dependency, capacity, collector, and overall health are
  independent; CRL Prefetch is a grouped informational observation; disabled
  members are excluded; redundancy is warning with two healthy peers and
  critical with only one; collector freshness, gateways, domains, service
  accounts, replication, machines, pools, and active certificates use stable
  reason codes and impact. Added bounded condition persistence/recovery,
  fail-soft vendor metrics, and an additive health RRD. The UI consumes the
  emitted severity and keeps observations outside actionable conditions.
  Shared PHP/C# fixtures, a sanitized three-member acceptance case, condition
  recovery, vendor mismatch, and UI contract tests passed. A dedicated rendered
  sanitized three-member page fixture now locks in five healthy scopes, zero actionable
  conditions, one grouped CRL Prefetch observation, and no standalone gateway
  wording. Browser QA in dark mode passed for pool expansion, session and
  unavailable filters (including the empty state and a real unavailable row),
  machine drawers, Connection Server drawers, condition-to-server drilldown,
  page-width containment, and clean browser diagnostics. Overlay SHA-256:
  `a9971b42f6dd4cc97811ea1694298a0ebf96b036f860de974a5acbfa5e3d6718`.
- Built the separately versioned Windows agent/MSI 0.6.16 health-policy
  checkpoint. The local Horizon collector now distinguishes required,
  conditional, and optional services by stable component identity, scores
  stopped Manual core services, keeps unused gateway and optional services
  informational, and limits health to the active Horizon certificate.
  Existing sections remain compatible and add explicit expectedness,
  severity, reason, impact, and certificate scope. Portable tests and
  administrative MSI extraction passed. MSI SHA-256:
  `5a40c9965a44179b09c57e4e3951e55982b983bfd1fd83b4e93cbeaaf5811732`;
  config SHA-256:
  `94fd8b56e0ac2ca15f50dd0ffff1d3f9167032b4717aeecd5b091f336fbe404b`.
- Built Windows agent/MSI 0.6.15 as an installer reliability release. The
  public bootstrap no longer removes registered packages before replacement;
  it verifies the MSI and matching versioned config, checks x64 Windows, .NET
  Framework 4.6.2, listener syntax, and port ownership, prepares the effective
  configuration before the MSI starts the service, preserves/restores config
  around failure, and retains a verbose MSI log with nearby failure evidence.
  The MSI now has a native .NET launch condition, conditional service startup,
  and non-fatal firewall registration so local firewall policy cannot roll
  back an otherwise valid service installation.
- Added installer source/table assertions, administrative extraction and
  packaged-config validation, an agent-only release path, and an elevated
  clean-host acceptance suite. The exact promoted MSI passed a real 0.6.14 to
  0.6.15 upgrade with configuration preservation, fresh installation,
  same-version repair, occupied-port refusal before MSI changes, reinstall,
  live TCP payload validation, three clean uninstalls, and final residue
  checks. Every MSI log returned zero with no `Return value 3` marker. The 59
  portable .NET tests also passed. MSI SHA-256:
  `80cd00920000c108d0bbe7a73b96289aca40e07231dda88ecd90312e4d622b20`.
  Versioned config SHA-256:
  `79f37b860b2aab30a373fecc7af604b48f0a6d8e416d7057f184544da0816294`.
- Raw-publication verification detected Git line-ending normalization of the
  first versioned config commit before endpoint use. Added a release-artifact
  attribute that preserves the Windows-generated config bytes, retained the
  accepted checksum, moved the bootstrap to a new `-win.json` release URL that
  had never been cached with normalized bytes, and required raw GitHub
  MSI/config/manifest verification after the corrective push.
- Implemented the overlay 0.6.19 Horizon UI correction. The centralized
  snapshot now carries a bounded sanitized all-machine inventory, and expanded
  pools filter actual rows by all, issues, in-session, ready, or unavailable.
  Every machine row opens focused detail, while Connection Server conditions
  and platform members open in-place server evidence instead of a generic
  device dashboard.
- Reworked the Horizon workspace around LibreNMS light/dark theme tokens and
  added explicit responsive pool metric labels. The approved count-based pool
  policy, 30-day default, non-alerting behavior, RRD schemas, and Windows
  agent/MSI 0.6.14 remain unchanged.
- Validation passed: all 59 .NET tests; ten parser fixtures; ten rendered-page
  fixtures; 20 central collector/trigger/worker/discovery cases; all 71
  source/test PHP files and 68 packaged PHP files linted through WSL; Bash
  syntax; ShellCheck; JSON and whitespace checks; checksum, archive, package
  manifest, version, and required-file verification. Desktop dark/light and
  mobile dark browser QA passed for real pool filters, unavailable-machine
  rows, machine and Connection Server drawers, stable in-page navigation,
  30-day default, responsive labels, and page-width containment. The in-app
  browser exposed stale tab handles, so the documented Playwright fallback
  used Microsoft Edge. Overlay SHA-256:
  `b1ebee731985199c0c6661b536a76aa5516b952d351d2b4d83be8977df93644b`.
- Confirmed that the published 0.6.19 artifact was accepted by a downstream
  management updater, deployed from the exact published bytes, and reported
  current/no-op afterward. No public source change was required.

## 2026-07-25

- Implemented and packaged overlay 0.6.18 with the approved Horizon collector
  and UI iteration. The collector now publishes bounded unhealthy service and
  issue-machine evidence, explicit truncation, pod-name fallback, and
  attempt/snapshot observability. Central pool scoring now treats one
  unavailable spare as informational while capacity remains, two or more as
  warning, and zero ready or zero placement capacity as critical.
- Rebuilt the Horizon page around conditions, expandable pools, issue-only
  filtering, a read-only machine drawer, 30-day-default demand/headroom
  trends, platform health, collector reliability, and complete diagnostics.
  Local-only Horizon evidence remains available without central API
  configuration. LibreNMS notifications remain disabled.
- Validation passed: all 59 .NET tests; ten parser fixtures; ten rendered-page
  fixtures; 20 central collector/trigger/worker/discovery cases; 68 packaged
  PHP files plus all source/test PHP linted through WSL; Bash syntax;
  ShellCheck; JSON and whitespace checks; checksum, archive, package manifest,
  and required-file verification; and browser interaction/visual QA for pool
  expansion, issue filtering, machine drawer, Escape close, and trend ranges.
  The release helper's Windows-only PHP probe was unavailable, but the broader
  WSL PHP source and packaged-payload lint passed. Overlay SHA-256:
  `6eb69b7c560b6958a596a117c38298c246bb720ad90700342c31608b1058e829`.
  The Windows agent/MSI remains 0.6.14 with unchanged checksum
  `e2dc68edd5b0aaa1f21828e8292d37b7412dcb0353dcf11db0f458859d759b89`.

- Locked the next Horizon collector requirements without changing code or live
  collection: bounded per-member unhealthy service details, collector
  self-observability, explicit per-pool capacity policy, correct empty
  pod-name fallback, and non-alerting defaults. Made UI information
  architecture and an approved visual concept mandatory before implementation;
  the redesign must prioritize actionable issues, pool headroom, demand,
  platform causes, freshness, and reliability over equal-weight summary cards
  and buried disclosures.
- Approved the UI hierarchy: actionable conditions and pool capacity occupy
  the first viewport, followed by demand trends, platform health, collector
  reliability, and diagnostics. Approved pool semantics make one unavailable
  spare informational when another remains ready, two unavailable spares a
  warning, and zero ready spares critical when the spare set is non-empty.
  Added 24-hour, 7-day, and 30-day trend requirements. Notification rules
  remain separately gated. Approved a critical capacity-exhaustion state for a
  non-empty pool when every machine is in session and no machine remains
  available for another placement; this is distinct from unavailable-spare
  health.
- Added the pool drill-down interaction requirement: every pool expands in
  place, issue machines sort first, and an issue machine opens focused
  evidence and next-action detail without discarding pool context. This
  requires a bounded sanitized per-machine issue inventory with explicit
  truncation rather than only machine-state aggregates.

- Released overlay-only patch 0.6.17 after production validation of 0.6.16
  exposed a redundant Eloquent cast-property mutation. The existing
  fixture-backed central snapshot merge already preserved the correct data;
  removing the redundant post-merge loop eliminates recurring `Indirect
  modification of overloaded property` warnings during normal Windows-agent
  polls. The Windows agent/MSI remains 0.6.14.
- All 59 agent tests, ten parser fixtures including central-data precedence,
  central trigger/worker/discovery tests, source PHP lint, Bash syntax,
  ShellCheck, the overlay-only release build, archive inspection, checksums,
  and public-safety checks passed. Overlay SHA-256:
  `3909e53f592e148282d6b4ff092a07b43a8184dd1f8f8ef1ed3fee087f7dd187`.
- Downstream validation completed a full Windows-agent poll on 0.6.17 with
  zero PHP errors and zero indirect-modification warnings while retaining the
  central Horizon snapshot.

- Released generic overlay 0.6.16 with a poll-safe, credential-free Redis
  trigger producer shared by normal and explicit application polling; a
  collector-only worker with per-site distributed locks/cooldowns; an
  independent five-minute fallback; and single-writer central Horizon RRD
  publication with last-good stale preservation.
- Added preview-first, add-only pod discovery with application readiness,
  strict DNS/TLS/authentication/pod-identity validation, API-member failover,
  ambiguity handling, existing-choice preservation, and non-secret display
  registration. Added the generic schema-2 capability/private-integration
  contract and overlay-only release build support.
- Expanded generic tests for trigger scoping/deduplication/failure isolation,
  worker/fallback locking, offline and deleted display boundaries, discovery
  lifecycle and validation failures, explicit/normal poll path sharing,
  capability compatibility, and managed worker rollback. All 59 agent tests,
  ten parser fixtures, ten app-page fixtures, central fixtures, source and
  packaged PHP lint, Bash syntax, ShellCheck, PowerShell parsing, package
  inspection, checksums, and safety checks passed. Overlay SHA256:
  `677f40a7a03c1547f5c27ee32bb6a44126e83252d474ac6d4d67300652cd5285`.
- Kept the Windows agent/MSI unchanged at 0.6.14. Read-only private-site
  inspection informed the separate private consumer package only; no private
  values entered this repository and no live deployment or poll occurred.

- Migrated the managed project workflow from initial V6 to V7 with the installed
  exact-cohort planner. Check-only revalidation reports current format-3/V7
  state; the private compatibility export is ignored, legacy helper files still
  match tracked `HEAD`, and no product build, remote, or publication action ran.
- Audited the four local Horizon release commits against the fetched public
  `origin/main`, confirmed a clean and strictly fast-forwardable branch, and
  corrected the stale read-first current-state record to distinguish overlay
  0.6.15 from Windows agent/MSI 0.6.14.
- Re-ran all 59 portable agent tests, all ten parser fixtures, all ten app-page
  fixtures, centralized security/failover/lifecycle tests, complete source and
  packaged PHP lint under WSL PHP 8.3, Bash and PowerShell parsing, JSON/XML
  parsing, Git whitespace checks, native release builds, package listing, and
  checksum verification.
- Rebuilt overlay 0.6.15 into temporary output and verified that all 72
  extracted files matched the tracked release payload by path, size, and
  SHA256. The gzip container hash changed only because the build records
  timestamps. The rebuilt 0.6.14 MSI passed service output, 23-collector,
  upgrade/rollback, config-preservation, and firewall-table assertions; its
  generated ProductCode makes the MSI container intentionally non-reproducible.

## 2026-07-22

- Added the opt-in centralized Horizon REST collector and configuration helper
  for a single bounded query per site pod from LibreNMS. Bootstrap order is
  `<site>-vcs1` then `<site>-vcs2`; API-discovered healthy Connection Servers
  extend failover without making gateways eligible, and pod identity is pinned
  to prevent collecting the wrong site.
- Kept Windows agents credential-free and retained their local Horizon
  service/process/listener/certificate telemetry. Central snapshots contain
  aggregate operational data only, take precedence in the existing
  `windows-agent` application, preserve the last good result when collection
  fails, and write unknown rather than false-zero Horizon API RRD samples while
  stale.
- Added secure prompt-only `.env` credential management, atomic pod
  configuration, credential rotation/removal, DNS/TLS and explicitly invoked
  read-only API tests, status output with no secrets, and optional five-minute
  cron management. Overlay packaging does not overwrite credentials, pod
  configuration, or collector state.
- Exercised the no-edit pod lifecycle through the configuration helper and
  corrected rollback so removing the central collector also removes only its
  marker-verified managed cron entry. Credentials, pod definitions, and
  last-good state remain untouched.
- Validated all 59 agent tests, ten parser fixtures, ten app-page fixtures,
  centralized collector/helper security and failover tests, PHP lint, shell
  syntax, package contents, and checksum generation. A local browser render
  also exposed and verified a corrected Horizon status-badge defect with no PHP
  or browser-console warnings. The temporary `0.6.15-candidate` overlay SHA256
  was `3bf8e1374f0d5a1fc6948a47954ddfe68a313b30ed4edf14d63a27f8b8f828f8`.
  No MSI was needed because the Windows agent did not change, and no live
  deployment or Horizon authentication was performed.
- Promoted the centralized implementation as overlay-only release 0.6.15 so
  the existing one-command LibreNMS installer remains unchanged. The Windows
  MSI remains 0.6.14 because no endpoint code changed. Final overlay SHA256:
  `295949ee3e3b19a062837d928b9658fbafb05c429fc9e6a6a5b884f20b9cf074`.

## 2026-07-17

- Split authenticated Horizon REST collection from local Horizon collection so
  an unavailable API cannot suppress local service/process evidence. Added
  bounded read-only collection for Connection Server monitor/config data,
  environment identity, Horizon domain access, gateways, sessions, clone
  pools, and machines.
- Kept three directory scopes explicit: Windows/Microsoft AD remains in the
  Windows AD collector; Connection Server `cs_replications` is labeled Horizon
  configuration replication (AD LDS); and AD-domain monitor data is labeled
  Horizon domain access by pod member.
- Added instant/linked-clone spare-capacity scoring with 50% warning and 90%
  critical defaults, an unconditional critical state when zero spares are
  ready, warning for zero unused capacity, and incomplete state when machine or
  session pagination is unavailable/truncated. Added five focused unit tests.
- Added compact pool/pod/directory/gateway disclosures and an additive Horizon
  platform RRD family. Local service build and 59 core tests pass; fixture JSON
  and sample config validate. PHP lint and rendered UI validation remain
  unavailable locally. No live deployment was performed.
- Promoted release 0.6.14, updated both one-command installer defaults and all
  current-version documentation, and built the native MSI and overlay. Overlay
  SHA256: `151b8389fada2f833d2374e844af83497c75cb44dd7aeefbe15f70b632af08c8`.
  MSI SHA256: `e2dc68edd5b0aaa1f21828e8292d37b7412dcb0353dcf11db0f458859d759b89`.

## 2026-07-16

- Reworked the Horizon app-page presentation without changing the agent
  protocol, RRD schema, or alert configuration. The operational view now leads
  with collector-confirmed health and next action, six compact
  service/process/listener/certificate metrics, issue-specific actions, and a
  nested inventory/raw-diagnostics disclosure. FactoryTalk and Horizon now use
  the same generic role-dashboard styles.
- Added healthy and three-condition Horizon fixtures and validated the
  presentation at 1440-pixel desktop and 390-pixel mobile widths. Both renders
  had six metrics, no horizontal overflow, no yellow alert blocks, and raw
  inventory collapsed by default. Nine parser fixtures and nine app-page
  fixtures passed.
- Researched official Omnissa monitoring interfaces and documented the next
  collection phases in `docs/horizon-monitoring.md`. Credential-free Horizon
  process runtime telemetry is the recommended next addition. Pod component,
  session, machine, pool, farm, gateway, and event visibility should use a
  separately configured read-only Horizon API identity or centralized Event
  Database/Syslog path.
- Rebuilt the in-place 0.6.13 overlay package with SHA256
  `4637ce0531b9294856fb811ca35c66e94bca19ca20318350999ae9329c46d1ea`.
  The Windows MSI remains unchanged at SHA256
  `f80710d424b963da856396eb1e6643a98563e657b666b08ab88e7f571655bda6`.
- Corrected the FactoryTalk page after field feedback showed speculative UI
  heuristics producing a large false-positive issue count. Only the collector's
  `health_issues` result and stopped core services now affect health. Non-core
  services, optional listeners, runtime/native availability, cumulative send
  failures, and transaction utilization remain informational. Replaced the
  large colored alert and repeated issue cards with a compact status row and
  concise health list.
- Rebuilt the corrected 0.6.13 overlay package with SHA256
  `a3a21f87e3dc98731b6b4caf5fe271867a9b4224e3cb06a2501040b84ca0c8d7`.
  The deliberately noisy informational fixture reports zero health issues,
  while the stopped-core-service fixture reports exactly one. All 53 portable
  agent tests, complete PHP lint, eight parser fixtures, eight app-page
  fixtures, shell syntax, and healthy/issue headless renders passed. The MSI
  remains unchanged.
- Reworked the FactoryTalk app-page presentation without changing collection,
  alerts, or RRD schemas. The operational view now leads with status, next
  action, six key metrics, actionable conditions, and the top five runtime
  processes; complete inventory/counter rows and secondary graphs remain in
  nested disclosures. Added healthy, stopped-service/port, missing-data, and
  unavailable-native-snapshot fixture coverage, and corrected the app-page
  fixture runner's stale source filename and duplicated legacy application-name
  expectations.
- Rebuilt the in-place 0.6.13 overlay package. Overlay SHA256:
  `44268bddfdaa44f8702e0fed77aca2722c97b703073ccd93134a32aa69374b36`.
  The MSI was intentionally unchanged at SHA256
  `f80710d424b963da856396eb1e6643a98563e657b666b08ab88e7f571655bda6`.
  JSON parsing, Git whitespace checks, 53 portable agent tests, complete source
  and packaged PHP lint, all eight parser fixtures, all eight app-page fixtures,
  package build, tar listing, manifest/payload inspection, checksum
  verification, and headless healthy-desktop/warning-mobile rendering passed.
  The public-readiness sanitizer passed with 162 included and 29 excluded
  files; its generic history policy still rejects this repository's
  intentionally tracked release artifacts and work log. No LibreNMS deployment
  was performed.
- Removed both agent PowerShell custom actions and their packaged scripts from
  0.6.13. The MSI now installs a permanent never-overwrite `agent.json`, starts
  the automatic service through the standard `ServiceControl` table, and uses
  WiX Firewall extension tables for program-scoped domain/private TCP 6556
  rules. Listener binding is checked synchronously during service startup.
- Rebuilt release 0.6.13 in place. Overlay SHA256:
  `5ce97913b75bc579c6f9f70b0e4f98650d55381b80d4b3e8e0279a68a16a1b65`.
  MSI SHA256:
  `f80710d424b963da856396eb1e6643a98563e657b666b08ab88e7f571655bda6`.
  All portable tests, .NET Framework/WiX builds, native MSI table assertions,
  decompiled payload/config inspection, extracted collector execution, and a
  real TCP listener response passed. PHP remained unavailable, so unchanged
  overlay PHP lint was skipped. No endpoint deployment was performed.
- Diagnosed the follow-up 0.6.13 failure as Windows native command-line parsing:
  quoted MSI directory values ended in a backslash, which consumed the closing
  quote and corrupted later PowerShell arguments. Private endpoint evidence
  remains outside this repository.
- Corrected 0.6.13 in place by deriving the install directory from the installed
  script and the data directory from the MSI registry value, with a safe common
  application-data fallback. Added MSI build assertions that reject explicit
  directory arguments and oversized custom-action commands.
- Rebuilt release 0.6.13 in place. Overlay SHA256:
  `384c77cfb3825dcf45d2528c2d61ad9e4454aa0db77f5b2468d9ed2c01cf631d`.
  MSI SHA256:
  `a866f5feb96095a28b3eacd5e47c5f0b9e5a50d67a258fab2db7419e89964784`.
  The Windows PowerShell 5.1 space-containing-path test, all portable .NET
  tests, .NET Framework/WiX builds, MSI metadata and command assertions, and
  checksum generation passed. PHP remained unavailable, so overlay PHP lint
  was skipped; overlay source behavior is unchanged.
- Diagnosed a generic 0.6.13 major-upgrade failure from private non-production
  evidence. Windows Installer removed the prior package, then the deferred
  configuration action failed while launching the installed agent executable;
  the external deployment wrapper incorrectly reported success despite the MSI
  recording error 1603. Private host evidence remains outside this repository.
- Repaired 0.6.13 in place. The MSI no longer launches the agent executable as
  a PowerShell child merely to revalidate an already parsed configuration;
  instead it verifies the installed file and requires the service to reach
  `Running`. Same-version upgrades are allowed, and `RemoveExistingProducts`
  now runs after `InstallInitialize` so rollback can restore the prior package.
- Preserved older configurations now receive every complete-set FactoryTalk
  property when the MSI feature is enabled, including runtime metrics, native
  interval/timeout defaults, and localhost native-counter mode.
- Rebuilt release 0.6.13 in place. Overlay SHA256:
  `761953ce7db1a376898a55b3184f2356c397d52c874dbbccc7d33bd4b50c162e`.
  MSI SHA256:
  `e5a861ccb0d86a635a6c589306ea1298b5eb62befc4e408ed0803ceff6c2dd87`.
  Validation passed for the exact preserved pilot configuration under Windows
  PowerShell 5.1, all portable .NET tests, warning-free .NET Framework/WiX
  builds, same-version/rollback MSI table assertions, package contents, and
  checksums. PHP remained unavailable; overlay source behavior is unchanged.
- Added the `ENABLE_FACTORYTALK_NATIVE_COUNTERS` MSI property, defaulting to
  enabled. The installer applies `nativeCountersMode=local` to both fresh and
  preserved configurations; setting the property to `0` provides a direct
  rollback without disabling Windows-native FactoryTalk runtime metrics.
- Initially built release 0.6.13 and refreshed the MSI, overlay, current installer
  defaults, documentation, and checksum manifest. Overlay SHA256:
  `e948079cd045fc08bd3d6b6bdef6434e93832bc1393218ca4d6150ca9e7768ab`.
  MSI SHA256:
  `f762c5c137261a8b6d47a4bb5fc167379e8623f51be3514938ecd2c3e302c66e`.
- Validation passed for all portable .NET tests, the .NET Framework service and
  WiX builds, MSI metadata, PowerShell/Bash parsing, package contents, and
  checksums. A non-privileged temporary install-layout test also confirmed the
  enabled and disabled property paths update a preserved configuration and pass
  agent validation. PHP was unavailable locally, so overlay PHP lint was
  skipped; the 0.6.13 overlay source is unchanged from the previously linted
  0.6.12 release.
- Implemented FactoryTalk Windows-native runtime metrics and a safe opt-in
  FactoryTalk Diagnostics Counter Monitor snapshot path. The snapshot runner is
  localhost-only, verifies Rockwell Authenticode trust, skips concurrent/manual
  instances, bounds runtime and XML size, parses an explicit counter allowlist,
  caches sanitized last-good results, and removes temporary XML.
- Extended the overlay with additive FactoryTalk runtime/native protocol
  parsing, five new RRD families, conditional graphs, and runtime/native detail
  views. The established FactoryTalk health RRD and alert behavior are
  unchanged.
- Added portable parser security/allowlist tests and a fabricated overlay
  fixture. .NET tests and the .NET Framework service build passed using the
  installed SDK with supported runtime roll-forward; PHP fixture/lint execution
  was unavailable on this workstation and remains a release-validation item.
- Authenticode interop was validated against a trusted embedded-signature
  control, and the Rockwell signer allowlist correctly rejected the trusted
  non-Rockwell control.
- Built release 0.6.12. Overlay SHA256:
  `1e65f17d76750e0690afef82a806d33670ae60423648dec27209c2a11f899b8d`.
  MSI SHA256:
  `925456f75a8d56c0eeb73af3fc610de4f4379f50a80cb3443e0933c8d8f40582`.
- Added committed sanitized-backup policy that excludes versioned release
  binaries from the source fallback. Release binaries remain checksum-verified
  public distribution artifacts in the canonical repository; the isolated
  backup preserves the audited source snapshot.

## 2026-07-15

- Audited the canonical and legacy repositories before consolidation. All 55
  agent source files matched after the intentional generic namespace, service,
  and protocol renames; the 50 C# test names also matched, and the newer legacy
  commits changed only private project-export context.
- Quarantined the legacy local repository as
  `librenms-windows-agent-legacy`, preserving its modified README, untracked
  export, Git history, and ignored internal artifacts.
- Updated the canonical project name, public installer repository defaults,
  and README URLs from `librenms-windows-agent-installer` to
  `librenms-windows-agent`.
- Renamed the private GitHub repository to `librenms-windows-agent-legacy`,
  renamed the public canonical repository to `librenms-windows-agent`, updated
  both local remotes, and published the canonical commits. The final local
  canonical directory rename is pending because the active Codex workspace
  holds the directory open.
- Validation: installer PowerShell parsing, Bash syntax, `git diff --check`,
  active old-name reference scanning, and release checksum verification passed.
  The isolated backup scanner reported only the intentionally tracked MSI and
  overlay release binaries and no text, credential, or infrastructure finding.

## 2026-07-14

- Migrated the current universal agent core, Windows service collectors,
  portable tests, MSI source, generic sample configuration, LibreNMS overlay
  source, parser/app fixtures, architecture notes, and collector roadmap into
  this repository.
- Added native `build-msi.ps1`, `build-overlay-package.ps1`, and
  `build-release.ps1` workflows; retired the sibling-repository promotion
  script and updated the project boundary, current-state handoff, README,
  release runbook, and Codex guide.
- Preserved the published 0.6.11 artifacts and checksums. Source and staging
  scans found no private hostnames, IPs, usernames, device IDs, or legacy
  branding. C# tests and MSI rebuilding were skipped because no .NET SDK is
  installed; remaining validation is recorded below.
- Validation: native overlay packaging passed. The generated package differs
  from published 0.6.11 only in explicit ordinal manifest ordering and
  scanner-friendly construction of the environment-supplied web login form
  key. PowerShell parsing, JSON/XML structure, project-reference checks,
  portable-Git Bash syntax checks, published artifact checksum verification,
  and imported-source privacy scans passed. PHP lint/fixtures and Python syntax
  compilation were skipped because PHP and Python are unavailable.
- Publication: the local transition commit was created, but the public push is
  pending because non-interactive Git credentials are unavailable in the
  current shell.
- Synchronized the installed new-project setup workflow in the public installer
  repository while preserving project-specific release, validation, and
  public-safety rules.
- Added the versioned workflow marker, managed ignore and line-ending policy,
  autonomous/prototype/portable-resume guidance, and the isolated sanitized
  GitHub backup helper.
- Validation: managed setup check, PowerShell helper parse, and Git diff checks
  passed. The isolated-backup scan inspected committed `HEAD` and blocked only
  on the intentionally published MSI and overlay archives under the
  `unreviewed-binary` rule; no blanket allowances were added. The GitHub CLI is
  also not installed, so isolated GitHub backup remains pending. The existing
  public distribution push is handled by this repository's normal
  verified-snapshot workflow.

## 2026-07-10

- Added the missing primary runbook step to enable the `Windows Agent` application on each LibreNMS device under the device `Applications` tab after installing the Windows agent.
- Clarified that per-device module overrides are separate from the per-device `Windows Agent` application enablement.
- Validation: documentation-only update; `git diff --check` passed.

## 2026-07-07

- Corrected the public README after the 0.6.8 promotion so the direct MSI link, specific overlay version example, `msiexec` install commands, and silent uninstall command point at `librenms-windows-agent-0.6.8.msi`.
- Validation: current README/reference scan and `git diff --check` passed; raw URL checks for the 0.6.8 MSI and overlay were already verified during promotion.

## 2026-07-06

- Promoted overlay package 0.6.6 and Windows MSI from development commit b1b869c with checksums 51850d31f413840ecd455bc6e0aff214a3bc1f911bada8c54ac4b054c947ac89 and 2cab3b4c1609cf1acd9c0f82d042227b9afa7030af096bf8eb1709e1cb15ddce. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, raw URL verification, and legacy-branding scans passed; PHP lint depends on local PHP availability.
- Corrected the public README after the 0.6.6 promotion so direct MSI examples point at `librenms-windows-agent-0.6.6.msi` and diagnostics expect `collectors_run=22`.
- Validation: README stale-reference scan and `git diff --check` passed.

## 2026-07-05

- Added a public README performance and scaling section with observed Windows-agent poller worker-time cost, capacity math for 100-150 Windows devices, and rollout checks using LibreNMS Poller Cluster Health.
- Validation: documentation diff review and `git diff --check` passed for README, work log, and changelog.

## 2026-07-04

- Reworked the public README into a step-by-step primary runbook: confirm SNMP-backed LibreNMS device discovery, enable `Applications` and `Unix Agent` globally, install the overlay on every LibreNMS node/poller, install or update the Windows agent, and poll/verify. Optional per-device overrides, overlay options, MSI properties, rollback, collector expectations, and diagnostics are now in an addendum.
- Fixed stale `install.sh --help` version wording so it no longer names an old default release.
- Validation: README/current-version scan, shell syntax check, PowerShell installer parse, and `git diff --check` passed.

- Promoted overlay package 0.6.5 and Windows MSI from development commit 51180e3 with checksums 2b70bc3b01d3f481930b07246e2bdea46cc457c83c3345ccccd57df71d267575 and 15f5c0f83a38cd2a0fb4f9f1f952cbb35ee38b53833d37a0831dd5fb57172e60. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.4 and Windows MSI from development commit c9e48c3 with checksums 92b04928d69ab3bec8f5f89e5c4cfbe0fca11e6453456dde9e80ec7262c1ac67 and 1a515ccaa735c0eede0eeca6dff64891b498df90fdcffdb6d95f87a08f7bfbfb. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Updated current public instructions and installer docs to reference the promoted 0.6.3 MSI and overlay artifacts.
- Validation: `bash -n`, PowerShell parse, 0.6.3 tar listing, SHA256 checks, current-reference scan, `git diff --check`, and raw URL checks passed.

- Promoted overlay package 0.6.3 and Windows MSI from development commit 6d595f2 with checksums d78bb063ecc6b18900dfb37f42c62074b1d96cd65389e505a5df34d0ce36930a and 05bbf6851568da4bc72096bd4c65c719093c1652a20cff3bf9095aa869124d33. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Updated current public instructions and installer docs to reference the promoted x64 0.6.2 MSI and overlay artifacts.
- Validation: `bash -n`, PowerShell parse, 0.6.2 tar listing, SHA256 checks, current-reference scan, `git diff --check`, and raw URL checks passed.

- Promoted overlay package 0.6.2 and Windows MSI from development commit db0126b with checksums b5418bb1863316bedde423cb3a0c4e43fecf5e28ea3b71eb35cf3ec6c521d212 and 60858d312631ecc4206d8a02dc0ce986eff18d5022238c4d11abc7727f134b47. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Hardened `install-agent.ps1` so it removes prior LibreNMS Windows Agent MSI packages before install, accepts MSI reboot-required success, verifies the installed service executable exists, verifies the expected file version, verifies config creation, and prints the actual service executable path.
- Updated the promotion workflow so `install-agent.ps1` receives the promoted version automatically along with `install.sh`.
- Validation: PowerShell parse for `install-agent.ps1` and `scripts/promote-from-dev-overlay.ps1` passed; `git diff --check` passed.

- Updated current public instructions and installer defaults to reference the promoted 0.6.1 MSI and overlay artifacts instead of stale 0.6.0 examples.
- Validation: `bash -n`, PowerShell parse, 0.6.1 tar listing, SHA256 checks, stale current-reference scan, and `git diff --check` passed.

- Promoted overlay package 0.6.1 and Windows MSI from development commit b97c5c5 with checksums c060f5bd155b3782b512ced1ac617b84a299ea25f261cf55ac0c0b0eabc4a173 and 0e048d6640b791db904f68fc2c85027687e0d9a48b255295e8a760acdb5ce896. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 7338e23 with checksums 29d9149b16764b15d7d97f97661d2b75eaa3af4720bae4df3b016a29e6355a4e and eb4a0372106be8e27d91393a8783e9e2a6f1b48d3f49757669542a52babc58ce. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Added README prerequisites for LibreNMS `unix-agent` and `applications`
  poller modules, including global UI, per-device UI, global CLI, per-device
  CLI enablement, and per-device override removal.
- Validation: reviewed upstream LibreNMS module override handling and checked
  the README diff; no installer artifacts changed.

- Promoted overlay package 0.6.0 and Windows MSI from development commit bc23c4b with checksums f83a6a59656681582d4f980ff8d0a4c41fd26f26441c633881701656980fceb2 and 60a0a2fcce8d130cf34e0a6cabdd544e7a69a7156ddd2b17751946a25bfe3d6c. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit d279011 with checksums 894dcbef1c3afaa30dea04c31a0215d16fb6d9d3222ae2880a12ef8830c09336 and efb500c6bc31cdbf31f9ddd92d37a1522a9501dac59651c0286966d20bbe9881. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 9a2a8ea with checksums 34736005d4b758984f10c705721392762303db34e65302df5388448194154cba and b67e10b40cad8e54194ad22a190c7863fddd7a4fc6b44e8a3b6568ee2acb13dc. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 7e6f398 with checksums 0ae0f5da0584ff1a1d2fc465ef263b72a0a1466f1fdf4dfe53ee7e7846d69b41 and 33201aefb038b52b5f106712d42b33821f342f93070bdf22530e50292ddf7841. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 1e6a425 with checksums 79f77739948c9321d2adb53d06e72b81b04c0e6871d8d08ba4d65e4298018a8e and ebf09c889cab95130f6eda82260d6109876733dd7b19c2be46c8f2dee4092ccb. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 1b4b877 with checksums 9448cf920dc5afddc635b3f686f0e4939fc1efc06e5f86ec22ac368a89cab4fc and 2a27b3f2132105bc2b31500cae24af149d4576f8aae128a529f5eb14941104e9. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 and Windows MSI from development commit 1eec530 with checksums be12a173d842d5f0b51be5c11badec2ef89d6d5477beb2585e32f6f71ef9054a and de92c2ab32a0077782c3b40b956632781e4bfcdad8c070dd8b9e4b3c6a8c4ced. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.0 from development commit eedf0df with checksum b45e5be3314964dd62909bc085557ba13b4b4386ffca3318bcc5d65eb96a5234. Validation: generated package tar listing, checksum update, and legacy-branding scan passed; PHP lint depends on local PHP availability.

- Created the public generic LibreNMS Windows Agent installer repository with a
  one-command server-side overlay installer and checksum-pinned release payload.
- Added local-first project workflow files so this local Git repo is the
  primary project record and GitHub remains the sanitized public distribution
  mirror.
- Added full project documentation for opening this folder as its own Codex
  project, including current state, read order, release runbook, and upstream
  sync model.
- Added `scripts/promote-from-dev-overlay.ps1` as the official interim
  promotion path from the private development overlay package to the public
  generic installer package.
- Updated the installer workflow so promotion into this repo is the review
  boundary and successful local installer commits automatically push to GitHub.
- Validation: installer syntax, tarball listing, checksum generation, raw
  GitHub URL checks, and legacy-branding scan were run during initial
  publication. PHP lint was skipped because PHP is not installed locally.

## 2026-07-05

- Promoted overlay package 0.6.5 and Windows MSI from development commit 17acd26 with checksums 2d1f8417e4887e5258cb7e9f4e1ac7f33aa1f7c5909e8505cda5e87072e66f9a and 22ba5d2f727056124389369892332d6e411a0c50d97222c1ead485d3aaa6043a. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

## 2026-07-06

- Promoted overlay package 0.6.6 and Windows MSI from development commit b1b869c with checksums 51850d31f413840ecd455bc6e0aff214a3bc1f911bada8c54ac4b054c947ac89 and 2cab3b4c1609cf1acd9c0f82d042227b9afa7030af096bf8eb1709e1cb15ddce. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.5 and Windows MSI from development commit 3a85b98 with checksums c0a097ca28293a38f184e53a1c6fa4465fecd2b12347bb2d60a357b74f949854 and b8c9828a8ad1ff816bf0e357f99702cfde97f6cda807fa3019c903631ba79666. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

## 2026-07-07

- Promoted overlay package 0.6.8 and Windows MSI from development commit 9c9626a with checksums b23c43f08d35e10c08c80275d63c8bc74a6790d0bbf927dd1463314adfe2f2d5 and 830a395e5d88e5ea83dc9b03a0d56ce2f02bf9867c5784b17d5c27cc544d8a77. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Promoted overlay package 0.6.7 and Windows MSI from development commit d8eb934 with checksums e7bcef6025c75d701fa935aadd2c8241fdbf464c2579b735e00691b610dd0ad7 and 9856c35ce6b78312b1696e6c5ae18a0cd8b3d8cbdee6c2ed6e2d9a8cf2613658. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

## 2026-07-08

- Promoted overlay package 0.6.10 and Windows MSI from development commit fca2b3f with checksums 4bd45ecbc2b8ed6c84746add38483846de9ade85457260ad71804e9ee8c34f51 and eb01960ba5a7e37543538bb460604e3de799744e887a5e020f38c0dbf0ed8f70. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

- Corrected the public README and current project docs after the 0.6.9 promotion so direct MSI links, specific overlay version examples, `msiexec` install commands, and silent uninstall commands point at `librenms-windows-agent-0.6.9.msi`.
- Hardened `scripts/promote-from-dev-overlay.ps1` so future promotions update README/current-version docs and fail before commit if current public docs still contain stale promoted-version references.
- Updated `AGENTS.md`, `docs/release-runbook.md`, and `docs/codex-project-guide.md` to make README current-link/script/artifact updates an explicit release requirement.
- Validation: README/current-version scan, promotion-script PowerShell parse, raw URL checks for the 0.6.9 MSI and overlay, and `git diff --check` passed.
- Promoted overlay package 0.6.9 and Windows MSI from development commit b212262 with checksums 702f6ead433c2d7e80864f5d264ec0c4e0af8f81913388a6639201ace5189c29 and 3b56e59c09668e39e61d600eff629e0accd2b75c9b7810d8354f26d244952492. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

## 2026-07-09

- Promoted overlay package 0.6.11 and Windows MSI from development commit 751f167 with checksums d6d5045ec8c4b717261a11f63abd821a4fd9b54741e2f3bbd6265520c297d50f and 9c70201e5ba89cc84c7c827f8ae44de67c18d783c6d447334df6855cf53192f8. Validation: generated package tar listing, MSI build, checksum update, public agent --once check, and legacy-branding scans passed; PHP lint depends on local PHP availability.

## 2026-07-16

- Began uncommitted Horizon process telemetry and read-only API work. Added a
  shared bounded process sampler, local Horizon runtime sections, an opt-in
  HTTPS REST client limited to login plus Connection Server/session GETs,
  machine-protected credential provisioning, additive LibreNMS RRDs and graphs,
  and sanitized UI/fixtures. No release artifact, deployment, commit, or push
  was performed. Service/test builds, 54 core tests, configuration and local
  section checks, fixture JSON parsing, diff checks, public-safety scan, and
  temporary overlay/MSI package builds passed. PHP was unavailable, so PHP
  lint, parser/app-page fixtures, and rendered UI review remain.
