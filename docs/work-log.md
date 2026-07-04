# Work Log

## 2026-07-04

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
