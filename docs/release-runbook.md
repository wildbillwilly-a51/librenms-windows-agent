# Release Runbook

Use this runbook when updating the public installer or overlay package.

## 1. Confirm State

```powershell
git status --short
git branch --show-current
git remote -v
```

Stop if unrelated local changes are present.

## 2. Update Installer Or Payload

For installer-only changes, edit `install.sh`, `install-agent.ps1`, and
`README.md` as needed.

For overlay or Windows MSI changes:

1. Validate the overlay behavior in the private development project.
2. Run the scripted promotion from this repo:

```powershell
.\scripts\promote-from-dev-overlay.ps1
```

The script builds the development overlay and public Windows MSI into temp
directories, converts overlay content to generic public identifiers, updates
`artifacts/`, regenerates `SHA256SUMS`, updates release docs, validates the
result, creates a local commit, pushes `main` to GitHub, and verifies raw
GitHub URLs.

Use `-NoCommit` or `-NoPush` only when testing the promotion script itself.

## 3. Validate

```powershell
bash -n ./install.sh
powershell.exe -NoProfile -Command "[void][scriptblock]::Create((Get-Content -Raw .\install-agent.ps1))"
tar -tzf .\artifacts\librenms-windows-agent-overlay-0.6.1.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-overlay-0.6.1.tar.gz
Get-FileHash -Algorithm SHA256 .\artifacts\librenms-windows-agent-0.6.1.msi
git diff --check
```

If PHP is available, extract the package and lint all PHP files:

```powershell
$tmp = Join-Path $env:TEMP "librenms-windows-agent-overlay-lint"
Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path $tmp | Out-Null
tar -xzf .\artifacts\librenms-windows-agent-overlay-0.6.1.tar.gz -C $tmp
Get-ChildItem -Path $tmp -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## 4. Public-Safety Review

```powershell
git ls-files
git grep -n -I -E "password|passwd|secret|token|api_key|apikey|client_secret|private_key|BEGIN PRIVATE KEY|Authorization:|Bearer " HEAD
```

Also scan the extracted overlay package for private/site-specific branding and
environment facts before pushing.

## 5. Commit And Push

For scripted overlay promotions, the commit is created by
`scripts/promote-from-dev-overlay.ps1` and pushed automatically after
validation. Confirm the final state:

```powershell
git status --short
git show --stat --oneline HEAD
```

For installer-only edits, commit manually:

```powershell
git add .
git diff --cached --check
git commit -m "<clear release or maintenance message>"
git push origin main
```

After pushing, verify raw URLs:

```powershell
curl.exe -fsSI https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install.sh
curl.exe -fsSI https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/install-agent.ps1
curl.exe -fsSI https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/SHA256SUMS
curl.exe -fsSI https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/artifacts/librenms-windows-agent-overlay-0.6.1.tar.gz
curl.exe -fsSI https://raw.githubusercontent.com/wildbillwilly-a51/librenms-windows-agent-installer/main/artifacts/librenms-windows-agent-0.6.1.msi
```
