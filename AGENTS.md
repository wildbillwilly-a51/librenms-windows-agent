# LibreNMS Windows Agent Installer Project Rules

This repository is the standalone public installer package for the generic
LibreNMS Windows Agent overlay.

## Scope

Work in this repository is scoped to:

- the public one-command installer script
- the generic LibreNMS overlay tarball under `artifacts/`
- checksum and usage documentation
- project workflow files such as this file, `CHANGELOG.md`, and
  `docs/work-log.md`

This repo must stay generic. Do not add lab-specific hostnames, IP addresses,
device IDs, credentials, private keys, tokens, or environment-specific
LibreNMS details.

## Source Of Truth

The local Git repository is the primary project record. The GitHub repository is
a sanitized public distribution mirror for installer content.

The handoff from the private development project into this installer repository
is the review and authorization boundary. After a scoped installer repo commit
is created and public-safety checks pass, push the committed snapshot to GitHub
as part of task completion.

## Safety Rules

- Preserve the public one-command installer contract unless the user approves a
  breaking change.
- Keep installer and overlay naming generic. Do not introduce site-specific
  package names, section names, service names, URLs other than the unavoidable
  GitHub owner path, or documentation text.
- Keep the overlay server-side only. Windows agent installation is outside this
  repository unless explicitly requested.
- Do not publish secrets, private infrastructure facts, customer names, private
  hostnames, private IP inventories, SSH keys, tokens, cookies, certificates, or
  live LibreNMS credentials.
- Treat `artifacts/librenms-windows-agent-overlay-*.tar.gz` as a release
  payload. Rebuild `SHA256SUMS` whenever the tarball changes.

## Validation

Use the smallest relevant validation first:

```powershell
bash -n ./install.sh
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.11.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.11.tar.gz
```

When PHP is available, also lint the PHP files extracted from the overlay
package before publishing. Also scan public content for legacy site-specific
branding before publishing.

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

<!-- new-project-setup:v2:start -->
### New project setup invocation

A bare or primary `$new-project-setup` invocation runs install/sync for this
project. Inspect first, then run the installed skill's
`scripts/apply-project-setup.ps1` against this project. Do not stop after merely
loading the skill. Questions about the skill are consultation-only.

### Default work tracking

For normal file-changing work, review status, update the local private
`docs/work-log.md`, update public-safe `CHANGELOG.md` when appropriate, run
focused validation, commit only scoped paths, then run
`scripts/github-backup.ps1`. The backup helper uses committed policy and an
isolated sanitized history. Never push source history as backup. Recheck branch,
HEAD, and scoped paths immediately before commit.

### Autonomous work packages

Complete bounded objectives end-to-end within one approved risk lane. Continue
through implementation and focused validation without routine checkpoints, but
stop before deployment, schema/migration, auth/security, destructive data,
global installs, dependency changes, broad redesign, or material scope growth
unless approved.

### Prototype lane

Use only when the user explicitly requests a quick local prototype or
throwaway exploration. Complete the local core workflow and smoke validation,
but skip routine logs, handoff, commit, and backup unless asked to preserve it.

### Portable resume

For cross-computer or externally stateful work, keep a public-safe
`docs/codex-handoff.md` with the current objective, one next action, blockers,
deployment state, and remaining validation. Keep confidential operational detail
in `*.local.md`. Fast-forward only when clean and stop on dirty, diverged, or
concurrent state.
<!-- new-project-setup:v2:end -->
