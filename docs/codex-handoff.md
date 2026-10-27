# Codex Handoff

- Objective: Prepare overlay 0.6.31 from the unreleased SQL support, with Claude
  reviewing the plan and final diff. Agent release work is separate.
- Current State: Candidate artifact built locally; installer default, capability
  manifest and version assertion are 0.6.31. SQL component/performance parsing,
  per-instance metrics, two new RRD families and app-page evidence are included.
  Unavailable and first-sample job deltas stay `-1`; page wording describes
  collection deltas. Published MSI/config 0.6.16 are preserved. Publication and
  deployment are pending; the public overlay remains 0.6.30.
- Relevant Decisions: Preserve protocol/app identity and existing RRD schemas.
  SQL graphs remain outside the inline app page. No alerts are enabled. Counter
  totals include only readable instances; job deltas are sampled evidence, not
  durable event history. Reconcile separately prepared agent release metadata
  before publishing this candidate.
- Validation Completed: Release-script agent suite 93/93; parser/app-page
  fixtures 15/15 each; central Horizon 35/35; shell syntax and diff checks pass.
  Source/package PHP lint 71/71 each; all 72 manifest entries match source;
  independent checksums and published MSI/config byte preservation pass. The
  original SQL RRD schema is unchanged; new graph datasets match definitions
  and the 19-character limit. Full public-snapshot scan: 263 files and 2,371
  files/archive members, no findings. Claude's plan and final diff reviews
  found no remaining blockers. The separate agent source still matches the
  tested SQL contract; only its version/bootstrap release edits differ.
- Validation Remaining: GitHub publication and deployed SQL/UI/graph checks
  require approval. Installed-agent validation belongs to the agent release.
- Next Action: Obtain publication approval and reconcile release metadata if
  the separate agent release is promoted before this overlay candidate.
