# Codex Handoff

- Objective: Publish the repaired Windows agent/MSI 0.6.15 while preserving
  overlay 0.6.19; endpoint deployment remains a separate approval.
- Current state: The exact 0.6.15 MSI and its versioned config are built and
  accepted. The bootstrap no longer pre-uninstalls MSI packages, prepares
  configuration before service startup, checks prerequisites and port
  conflicts, preserves rollback state, and retains verbose failure evidence.
  Overlay 0.6.19 is unchanged.
- Relevant decisions: Windows Installer owns registered upgrades and rollback.
  Firewall-policy refusal is reported but cannot roll back a functioning
  service. The public one-command contract remains unchanged. Horizon health
  classification work waits until this installer release is published and
  separately approved for endpoint rollout.
- Validation completed: 59 .NET tests; PowerShell 5.1 parsing; WiX metadata,
  launch-condition, service-control, rollback, config, and firewall assertions;
  administrative MSI extraction and packaged-config validation; exact-artifact
  0.6.14 upgrade with config preservation; fresh install; same-version repair;
  occupied-port refusal; reinstall; live TCP payload; clean uninstall and
  residue checks. All acceptance MSI logs returned zero with no `Return value
  3`. MSI SHA-256:
  `80cd00920000c108d0bbe7a73b96289aca40e07231dda88ecd90312e4d622b20`.
