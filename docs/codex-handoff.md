# Codex Handoff

## Horizon discovery member rule and back-off (overlay 0.6.35)

- Objective: let pod discovery add real sites whose gateway-named server is a
  Connection Server, and make scheduled discovery safe on a short interval.
- Current State: Member rule is `<site>-<name>` in the DNS suffix
  (`PodCollector::discoverFromEndpoint`). Per-site 24-hour back-off after a
  rejected login (`HorizonCentralConfiguration::nextBackoff`/`readBackoff`,
  `discovery-backoff.json` in the collector state directory, `--retry-now`,
  run lock). Overlay 0.6.34's pool reporters are unchanged.
- Relevant Decisions: only `unauthorized` starts a wait; skipped runs keep the
  original failure time; unreachable/TLS keep the entry; success clears it.
- Validation Completed: see the 2026-11-13 work-log entry.
- Validation Remaining: deployment and a live scheduled run are the operator's.
