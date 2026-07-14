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

This repository is the canonical universal product source. Most tasks should
touch only the relevant surfaces:

- `src/` for agent core, service host, and collectors.
- `tests/` for portable agent and LibreNMS fixture coverage.
- `installer/` for MSI behavior.
- `librenms-overlay/` for LibreNMS parser, app UI, graphs, rollback, and
  validation behavior.
- `scripts/build-*.ps1` for native builds and releases.
- `install.sh`, `install-agent.ps1`, and `README.md` for public installation.
- `artifacts/` and `SHA256SUMS` for intentional release payload updates.
- `docs/` for project state, workflow, and release notes.

Keep the local Git repo primary. Push to GitHub only after the committed
snapshot is verified as public-safe. Promotion into this installer repo is the
review boundary; after a scoped local commit is created, sync GitHub as part of
the same task unless the user explicitly says not to push.

Treat `README.md` as part of every release or promotion. If current artifact
links, MSI names, overlay names, install commands, script names, or user-facing
behavior change, update the README in the same commit and verify it no longer
references the previous current version.

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

Agent tests:

```powershell
dotnet run --project .\tests\LibreNMS.WindowsAgent.Tests\LibreNMS.WindowsAgent.Tests.csproj -c Release
```

Overlay package test:

```powershell
.\scripts\build-overlay-package.ps1 -ArtifactsDir <temporary-output-directory>
```

MSI build test:

```powershell
.\scripts\build-msi.ps1 -ArtifactsDir <temporary-output-directory>
```

Intentional release build:

```powershell
.\scripts\build-release.ps1 -UpdateChecksums
```

Snapshot review before push:

```powershell
git status --short
git ls-files
git grep -n -I -E "password|passwd|secret|token|api_key|apikey|client_secret|private_key|BEGIN PRIVATE KEY|Authorization:|Bearer " HEAD
```

Use judgment for policy-word hits in docs. Block the push if any real secret or
private deployment detail is present.
