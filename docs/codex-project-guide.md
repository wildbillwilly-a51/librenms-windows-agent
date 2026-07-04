# Codex Project Guide

Use this file when opening this folder as its own Codex project.

## Read Order

1. `CURRENT-STATE.md`
2. `README.md`
3. `AGENTS.md`
4. `docs/upstream-sync.md`
5. `docs/release-runbook.md`
6. `docs/work-log.md`

## Operating Model

This repository is intentionally small. Most tasks should touch only one of
these surfaces:

- `install.sh` for installer behavior.
- `README.md` for public usage.
- `artifacts/librenms-windows-agent-overlay-*.tar.gz` and `SHA256SUMS` for
  release payload updates.
- `docs/` for project state, workflow, and release notes.

Keep the local Git repo primary. Push to GitHub only after the committed
snapshot is verified as public-safe. Promotion into this installer repo is the
review boundary; after a scoped local commit is created, sync GitHub as part of
the same task unless the user explicitly says not to push.

## Public-Safe Rules

- Do not add private hostnames, IP addresses, device IDs, credentials, customer
  names, keys, tokens, cookies, certificates, or live LibreNMS details.
- Keep the package generic: `windows_agent_*` sections and `windows-agent`
  LibreNMS application identity.
- Avoid site-specific branding in public files.
- If a command or path is environment-specific, write it as a placeholder.

## Common Tasks

Installer edit:

```powershell
bash -n ./install.sh
git diff --check
```

Overlay package update:

```powershell
.\scripts\promote-from-dev-overlay.ps1
```

Promotion test without commit:

```powershell
.\scripts\promote-from-dev-overlay.ps1 -NoCommit
```

Promotion test with local commit but no GitHub sync:

```powershell
.\scripts\promote-from-dev-overlay.ps1 -NoPush
```

Snapshot review before push:

```powershell
git status --short
git ls-files
git grep -n -I -E "password|passwd|secret|token|api_key|apikey|client_secret|private_key|BEGIN PRIVATE KEY|Authorization:|Bearer " HEAD
```

Use judgment for policy-word hits in docs. Block the push if any real secret or
private deployment detail is present.
