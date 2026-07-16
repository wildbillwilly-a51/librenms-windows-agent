# LibreNMS Windows Agent Project Rules

This repository is the canonical universal development and public distribution
project for the LibreNMS Windows Agent and LibreNMS overlay.

## Scope

Work in this repository is scoped to:

- Windows agent source under `src/`
- agent and overlay tests under `tests/`
- Windows MSI source under `installer/`
- the generic LibreNMS overlay source under `librenms-overlay/`
- native build and maintenance scripts under `scripts/`
- public one-command installers and release artifacts
- checksum, architecture, collector, release, and usage documentation

This repo must stay generic. Do not add lab-specific hostnames, IP addresses,
device IDs, credentials, private keys, tokens, or environment-specific
LibreNMS details.

## Source Of Truth

This repository is the product source of truth. Do not develop universal agent
or overlay behavior in a private sibling repository and copy it here through
identifier conversion. Build the generic MSI and overlay directly from this
source tree.

The local Git repository is the primary project record. GitHub is the sanitized
public source and distribution mirror. After a scoped commit is created and the
complete snapshot passes public-safety checks, push it as part of task
completion.

## Safety Rules

- Preserve the public one-command installer contract unless the user approves a
  breaking change.
- Keep installer and overlay naming generic. Do not introduce site-specific
  package names, section names, service names, URLs other than the unavoidable
  GitHub owner path, or documentation text.
- Preserve the stable `windows_agent` / `windows_agent_*` protocol and
  `windows-agent` LibreNMS application identity unless the user approves a
  breaking change.
- Add new RRD graph families instead of changing existing RRD schemas.
- Keep new visibility non-alerting by default unless alerts are explicitly
  approved.
- Do not publish secrets, private infrastructure facts, customer names, private
  hostnames, private IP inventories, SSH keys, tokens, cookies, certificates, or
  live LibreNMS credentials.
- Treat `artifacts/librenms-windows-agent-overlay-*.tar.gz` as a release
  payload. Rebuild `SHA256SUMS` whenever the tarball changes.

## Validation

Use the smallest relevant validation first:

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
bash -n ./install.sh
.\scripts\build-overlay-package.ps1 -ArtifactsDir <temporary-output-directory>
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.11.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.11.tar.gz
```

For release work, run `scripts/build-release.ps1`. When PHP is available, also
run the overlay fixture tests and lint packaged PHP files. Always scan the
complete public snapshot for credentials, private infrastructure, machine-user
paths, and legacy site-specific branding before publishing.

### Default work tracking

For any Codex task that changes files, Codex should treat work tracking as part
of task completion unless the user explicitly says not to commit or not to
update logs.

Default completion steps:

- Review `git status --short`.
- Update `README.md` whenever current public links, artifact names, command
  examples, scripts, files, install behavior, upgrade behavior, or rollback
  instructions change. A promotion is incomplete if `README.md` still points
  at the previous current version.
- Update `docs/work-log.md` with a short dated entry covering the work,
  validation, and any skipped validation.
- Update `CHANGELOG.md` with a concise sanitized summary when the scoped local
  commit changes public-facing project behavior, docs, setup, or maintenance
  history.
- Run the smallest validation appropriate to the requested validation tier.
- Commit the completed scoped changes locally with a clear one-line message.
- Push sanitized public distribution content to GitHub after verifying the
  complete committed snapshot is public-safe. A commit in this installer repo is
  authorization to sync the public mirror unless the user explicitly says not
  to push.
- Leave unrelated pre-existing changes uncommitted unless the user explicitly
  asks to include them.

The user should not need to remember Git or PowerShell commands for normal
Codex-driven work. If committing is unsafe because another session has
overlapping uncommitted changes, report that clearly and leave the work
uncommitted instead of mixing unrelated changes.

Dirty or untracked files are local-only by default. They do not block pushing a
verified committed public snapshot, but they must not be included in GitHub
unless reviewed, scanned, and intentionally added.

If GitHub push is unavailable, keep the local commit and report push as skipped
or pending. Do not rewrite history to repair a failed push.

<!-- new-project-setup:v6:start -->
### New project setup invocation

A bare or primary `$new-project-setup` invocation runs install/sync. Use the
invoked installed apply helper for a normal target; in this skill's source use
the source helper, then sync runtime. Never only load; questions are
consultation-only.

### Adaptive efficient execution

Infer durability, operational risk, and effort independently. State them
briefly and continue:

- Lasting work preserves revisions and memory. Exploration is disposable only
  for clear learning or feasibility; `quick`, `prototype`, and `MVP` do not
  imply it. Promote reused or retained work; never demote. Delete only current
  uncommitted Codex-created artifacts confirmed unused, never pre-existing,
  shared, or lasting output.
- Risk controls authorization, not routine local implementation authority.
- Effort controls context and evidence, not authority: focused checks direct
  effects; standard covers primary workflows and distinct risks;
  release-critical gathers broad deduplicated evidence.

Ask one preservation question only for ambiguous durability. Do not ask for
routine implementation, context expansion, or validation transitions. Bounded
local work authorizes architecture, a reasonable initial stack for an empty project,
dependencies, tests, demo data, and empty-DB schemas.

### Progressive context and evidence

Start file changes with Git status and relevant files; durable work adds
`docs/codex-handoff.md`. Read logs only when useful. Expand for dependencies,
failures, or risk; exclude unrelated roots and artifacts. Rebuild stale
handoffs from Git and evidence; ask only if the objective remains unsafe.

Keep a compact ledger of acceptance criteria, material risks, boundaries,
evidence, invalidators, and completion conditions. Claim
completion only when every criterion passes, every material risk or protected
boundary has distinct evidence, no unresolved high-risk failure remains, and
durable records are current. Evidence is distinct only for a materially
different risk or protected boundary; code-path or presentation variation
alone is equivalent evidence.

Reuse valid evidence and batch failures by cause. After targeted checks pass,
run one effort-appropriate final matrix. On failure, preserve passing evidence,
retest only failed or invalidated checks, and do not restart a broad matrix.
Non-improving cycles require a different strategy, then a minimal reproducer;
they do not stop productive debugging. Stop unresolved
only when the latest strategy made no material progress and no credible bounded
probe remains. Preserve diagnostics and report the blocker.

### Proportional durable memory

Preserve every lasting change in Git. Log useful decisions, failures, validation,
or lessons; refresh the concise handoff at state boundaries with
valid and remaining evidence; update the changelog for notable behavior. Keep
private details in ignored `*.local.md` and recheck branch, HEAD, and scope.
Prepare the final handoff before its containing commit and record sync relative
to it; a matching push needs no bookkeeping-only commit.

After a safe commit, run `scripts/github-sync.ps1` for a complete audit and
private fast-forward push. Never force-push or change visibility. If blocked,
keep the commit and ask whether to use isolated `scripts/github-backup.ps1` or
remain local-only.

### Autonomous local work

Complete bounded objectives end-to-end through appropriate validation without
routine checkpoints.
Ask before deployment; credentials or live/paid services; auth/security changes;
global or native tool installation; framework or platform replacement;
consequential licensing changes; changes to existing, shared, or production
data; destructive operations; material product-direction expansion beyond the
request; or unrelated conflicting work. Internal refactoring, routine local
dependencies, and isolated local construction need no checkpoint.
Protected boundaries override implied authority. Deployment requires
confirmation immediately before the action unless the current request explicitly
names the target and effect and waives that checkpoint; that explicit waiver is
the confirmation. Merely asking to deploy is not a waiver. One confirmation may
cover several protected effects only when it names them all.
<!-- new-project-setup:v6:end -->
