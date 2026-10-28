# Codex Handoff

- Objective: Publish/deploy overlay 0.6.32 and verify the SQL tab with Claude,
  keeping the agent release separate. Publication and deployment completed;
  live UI acceptance found a graph markup defect.
- Current State: Public/private main release commit `0cb8f92`, overlay 0.6.32,
  agent 0.6.17. All five application nodes match all 83 installed/staged files;
  backends are overlay-free and services healthy. Normal polling stores 33
  qualified metrics; all eight SQL graph types render. Live instance filters
  and evidence disclosures work. Graph tooltip corruption disrupts tab layout,
  so complete UI acceptance is not claimed.
- Correction: `codex/sql-native-graph-markup` preserves native graph HTML and
  its lazy loading, removing the post-render image rewrite. Native golden
  fixtures replace the flat stub and detect immutable 0.6.32 as a negative
  control. No release bytes, versions/defaults, metrics, agent or RRD changes.
- Validation Completed: Release: 93 portable checks, four extracted-package
  suites, 82 packaged PHP lint, full public scan and 22 public URL checks.
  Correction: 39 page fixtures, native byte/structure/tooltip checks and ten
  source-bound browser interaction runs with production styles and generic data.
  Claude reviewed release/source plans, the correction and four generic images
  with no blocking issue. Lead resolved the asset-export glyph fidelity issue
  using raw server CSS hash checks and verified every SQL graph type.
- Relevant Decisions: Published 0.6.32 is immutable. Correction must ship as a
  new overlay release after explicit operator publication/deployment approval.
  No alert rules or agent rollout. Sustained DB contention and actual alert
  lifecycle remain pilot work; qualification is not dwell/held incident state.
- Next Action: Complete correction peer review and obtain approval to publish
  and deploy the next immutable overlay version, then repeat live UI acceptance.
