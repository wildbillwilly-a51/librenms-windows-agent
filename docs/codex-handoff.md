# Codex Handoff

- Objective: Publish overlay 0.6.21, which gives each detected first-order role
  its own application-page tab, without deploying it.
- Current state: Overlay 0.6.21 is built, published on GitHub `main`, and is the
  installer default. Windows agent 0.6.16 is unchanged and its published bytes
  are untouched, so this release is overlay-only. `Active Directory`,
  `FactoryTalk`, and `Horizon` each render as their own tab, only while that role
  is detected, ahead of `Overview`; the leftmost tab present is the landing tab.
  Active Directory carries a new dashboard built from health-contract fields the
  agent already reports, so no agent or protocol change was needed. DFSR follows
  a detected domain controller and otherwise stays on `Roles & Workloads`. Nine
  Horizon trend graphs that had become unreachable after Horizon was promoted to
  its own tab now render again through a shared `Trends` disclosure.
- Relevant decisions: tab visibility keys off each role's own reported detection
  flag, never off section presence, because summary sections are emitted even when
  the role is absent. Tab labels are bare role names. The `Detected Roles`
  inventory stays on `Roles & Workloads` as the record of what was evaluated.
  Undetected roles no longer render empty `Not detected` sections. Publishing does
  not update a deployed node, so rollout timing stays with the operator.
- Validation completed: portable .NET suite; `bash -n` on the overlay installer
  and a parse check on the Windows installer; parser, app-page, and central
  collector fixtures, with the capability manifest test asserting the new overlay
  version; PHP lint over all overlay source and all packaged PHP; rendered every
  detection scenario and confirmed structurally that exactly one tab and one pane
  are active, that a host with no detected role lands on `Overview`, and that a
  host with two detected roles orders them deterministically; packaged-artifact
  verification including capability manifest, shell syntax, and a manifest-to-file
  reconciliation; public-safety scan of the committed snapshot.
- Validation remaining: the rendered pages have not been reviewed in a browser
  against live data, and the release has not been applied to any overlay node.
- Next action: apply overlay 0.6.21 to overlay nodes when convenient, then
  confirm a role tab appears for each detected role, no tab appears for an
  undetected role, and the restored Horizon trend graphs draw. Separately, bring
  any Windows agent still below 0.6.16 up to the current version so the field does
  not stay on mixed agent versions.
