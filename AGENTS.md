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

Before pushing to GitHub, verify the complete committed snapshot is safe for
public release.

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
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.0.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.0.tar.gz
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
- Update `docs/work-log.md` with a short dated entry covering the work,
  validation, and any skipped validation.
- Update `CHANGELOG.md` with a concise sanitized summary when the scoped local
  commit changes public-facing project behavior, docs, setup, or maintenance
  history.
- Run the smallest validation appropriate to the requested validation tier.
- Commit the completed scoped changes locally with a clear one-line message.
- Push sanitized public distribution content to GitHub only after verifying the
  complete committed snapshot is public-safe.
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
