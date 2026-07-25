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

<!-- new-project-setup:v7:start -->
## Project Workflow

1. Orient from Git status, `docs/codex-handoff.md`, and files directly relevant
   to the objective. Expand context only for dependencies, contradictions,
   failures, or material risks. For a new objective, use
   `docs/project-summary.md` before the handoff; when referencing another
   project, read its summary first and do not scan that repository by default.
2. Preserve implementation and retained reusable output. Clearly disposable
   investigation may remain lightweight, but preserve it when it is reused,
   continued, incorporated, or requested for retention.
3. Determine completion conditions and material risks internally. Ask only
   when ambiguity affects preservation, authorization, or the requested result.
4. Run the smallest checks that cover changed material risks. Reuse evidence
   that remains valid and rerun only checks that failed or were invalidated.
5. Do not repeat an equivalent failed check. Change diagnostic strategy and
   create a minimal reproduction when it is likely to clarify the cause.
6. Preserve unrelated staged, unstaged, and untracked work. Do not include an
   objective file in automatic saving when it contains known unrelated edits.
7. When durable work is complete, update continuity state when needed, stage
   only objective-related whole-file or whole-directory paths, verify the exact
   staged tree, and create a clear local commit. Local completion depends only
   on local Git.

The current handoff always contains Objective, Current State, and Validation
Completed. Include Relevant Decisions, Validation Remaining, and Blockers only
when they contain useful state. Include one Next Action only while work remains;
a completed objective does not need a fabricated continuation action. Keep the
handoff compact and replace obsolete task history rather than accumulating it.

Use `.codex/new-project-setup/execution-and-continuity.md` for exceptional
execution or memory cases and `.codex/new-project-setup/local-saving.md` for
local-save details.

Authorization is required before destructive operations, deployment,
production or shared-data changes, credential or authentication changes, paid
services, global or native installation, external side effects, or material
expansion beyond the objective.
<!-- new-project-setup:v7:end -->
