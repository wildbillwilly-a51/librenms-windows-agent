# Work Log

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
