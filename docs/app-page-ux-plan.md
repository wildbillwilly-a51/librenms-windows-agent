# Application Page UI/UX Roadmap

Execution roadmap for a full evaluation, correctness, and polish package on the
`windows-agent` LibreNMS application page. This document is the plan of record.
Objective state for whichever phase is active belongs in `docs/codex-handoff.md`.

## Goal

Make every tab present the same shape of information, put the metrics an operator
actually decides on at the forefront, and move inventory and evidence into
disclosures. Correct the health classifications that currently report inaccurate
counts. Surface metrics the agent already collects but the page never shows.

## Non-Goals

- No new alert rules. New visibility stays non-alerting by default. Conditions
  that look like good alert candidates are recorded in the phase notes, not
  implemented.
- No changes to existing RRD schemas. New metric shapes get new graph families.
- No changes to the `windows_agent` / `windows_agent_*` protocol or the
  `windows-agent` application identity.
- No agent-side change unless a phase explicitly calls for one. Every phase below
  is overlay-only except where stated.

## Standing Constraints

- The repository stays generic: no environment hostnames, addresses, device or
  application IDs, or site-specific detail in source, fixtures, or docs.
- Published artifacts are immutable. Each phase that ships publishes the next
  overlay version and becomes the installer default in the same commit.
- One version per batch of work. Iterate locally without bumping.
- Publishing never updates a deployed node. Rollout timing stays with the
  operator.

## Decisions Already Taken

- Research draws on vendor and manufacturer documentation, general web sources,
  published best practice, product communities where one exists, and the metrics
  already present on the page.
- Demoting content that is currently at the forefront is acceptable. A polish pass
  that only adds is a failure.
- Alerting remains out of scope for this package.
- Phase 0 ships before the presentation work, so the UI is designed against
  correct numbers.

---

## Phase 0 — Correct Horizon Machine State And Capacity Classification

Correctness, not polish. It ships first because it changes the numbers the pool
drawers display, and designing presentation around saturated values would bake in
the defect.

### Defect

Capacity health saturates at its worst value and stays there, so it carries no
information. Confirmed against a live deployment: the capacity scope and the
overall scope held their worst value continuously while the vendor's own problem
counts moved, and the collector's built-in vendor-disagreement metric held a
constant non-zero offset.

Mechanism, in `librenms-overlay/tools/horizon-central-lib.php`:

1. **Placement readiness counts one state.** In the machine loop, a machine is
   counted ready only when its state is exactly the single "available" value;
   every other state increments the unready counter. A desktop with an active
   session — the healthiest state a pool member can be in — is therefore counted
   unready, as are normal idle and provisioned states, and machines in
   maintenance.
2. **Pool scoring then treats that as an outage.** Zero ready spares scores the
   pool critical, and two or more unready scores it warning. With step 1, a fully
   utilised pool scores critical and almost any real pool scores at least warning,
   permanently.
3. **Unrecognized states are treated as unhealthy.** The machine classifier has no
   `unrecognized` branch and falls through to `warning`. The gateway classifier in
   the same file does this correctly by returning `incomplete` for an
   unrecognized status. Not knowing a state is not evidence of a problem.
4. **Unknown machines inflate issue counts.** The per-machine issue flag includes
   `incomplete`, so a machine whose state could not be determined is counted as a
   problem machine.
5. **A dead classifier misleads readers.** `machineStateIsIssue()` is defined and
   never called anywhere in the overlay. Its logic differs from the classifier
   actually in use, so anyone reading it draws the wrong conclusion.

### Approach

Replace the three disagreeing code paths with one **state taxonomy**: a single
table mapping each machine state to four independent properties.

| Property | Meaning |
| --- | --- |
| `placement_ready` | Counts toward capacity available for a new session |
| `health_class` | `ok` / `info` / `warning` / `critical` / `incomplete` |
| `is_issue` | Counts toward problem machines |
| `capacity_relevant` | Participates in capacity scoring at all |

Rules the taxonomy must encode:

- In-use states are healthy and are **not** placement capacity. Utilisation is not
  an outage; capacity scoring must distinguish "no free capacity" from
  "capacity is broken", and they must not share a reason code.
- Maintenance is intentional. It is informational, excluded from placement
  capacity, and never an issue.
- Transitional states are informational until they exceed a bounded age, which the
  existing consecutive-sample logic already provides.
- Unrecognized states report `incomplete` with a distinct reason code, never
  `warning`, and never count as issues.
- The per-machine health class and the issue flag derive from the same table, so
  a row's displayed state can never disagree with the counts.

Build the taxonomy from the vendor-documented machine state enum, then reconcile
against the states a live deployment actually reports.

Also in this phase: delete the dead classifier, and add a **redacted machine state
distribution** (state string and count only, no machine identifiers) to the
Horizon raw diagnostics disclosure. It is generic, permanently useful, and makes
this class of defect diagnosable without filesystem access to the collector state.

### Exit Criteria

- The vendor-disagreement metric trends to zero, or any residual gap is explained
  by a documented vendor semantic difference.
- The capacity scope changes value as the environment changes, and a fully
  utilised healthy pool no longer scores critical.
- "No free capacity" and "capacity degraded" are separate reason codes.
- Fixtures cover every state in the taxonomy plus at least one unrecognized state,
  plus a fully utilised pool and an all-maintenance pool.
- No row's displayed state disagrees with the aggregate counts in any fixture.

---

## Phase 1 — Tab Contract And Shared Renderer

### Current State

Three tiers of polish coexist:

- **Horizon** — a bespoke rich workspace with its own CSS vocabulary and
  purpose-built sub-layouts.
- **FactoryTalk and Active Directory** — the shared role-dashboard pattern:
  status strip, stat tiles, attention list, then collapsible evidence.
- **Roles & Workloads, Security & Certificates, Backup, Services & Events, Agent
  Performance** — bare stacks of collapsible panels with no summary layer, so the
  operator must expand sections to learn whether anything is wrong.

### Target Anatomy

Every tab, in this order:

1. **Status strip** — state label, one plain-language sentence, the next action
   when there is one, and when the evidence was collected.
2. **Stat tiles** — four to six maximum. Decision-grade numbers only.
3. **Attention list** — what is wrong, why, and what to do. Rendered only when
   non-empty.
4. **Evidence** — collapsible panels holding today's tables and inventory.
5. **Trends** — a single disclosure holding that tab's graphs, preserving the
   secondary-graph grouping.

### Work

- Extract the status strip, tile row, and attention list into one shared renderer
  so a tab declares content rather than re-implementing markup. Three copies of
  this markup exist today.
- Apply it to the five tabs that lack a summary layer.
- Keep Horizon's bespoke workspace. It is richer than the generic contract and
  earns its exceptions; align its status strip and tile row with the shared
  vocabulary where that is possible without flattening it.
- Enforce the tile ceiling in the renderer, not by convention.

### Exit Criteria

- One implementation of each summary element.
- Every tab answers "is anything wrong here" without expanding a disclosure.
- No tab exceeds six tiles.
- Light and dark themes verified for every tab.

---

## Phase 2 — Per-Role Metric Research And Forefront Selection

One research pass per role. This is the substantive content work and the part that
determines whether the page is actually more useful.

### Roles In Scope

Active Directory / domain controller · FactoryTalk · Horizon · SQL Server · IIS ·
Backup and Datto · TLS certificates · Services and event logs · Agent, VM, and
Windows performance.

### Method, Per Role

1. Inventory what the agent already reports for that role and what the page shows
   today, forefront versus disclosure.
2. Research what the vendor or manufacturer treats as primary health indicators,
   what published best practice and the product community monitor, and what
   comparable monitoring products surface first.
3. Answer, in writing: what does an operator need to know at 2am, which numbers
   change a decision, and which are inventory.
4. Select four to six forefront tiles. Everything else is demoted to evidence or
   cut.
5. Record what was cut and why, so the choice can be revisited.

### Rules

- Forefront means actionable and decision-changing. Counts that are always the
  same number are not decision-grade.
- Every role needs a plain-language verdict sentence, not only a state label.
- A metric with no defined healthy range does not belong in a tile.
- Prefer a state plus its reason code over a bare number.
- Demotion is expected. A pass that only adds has failed.

### Deliverable

A short per-role record appended to this document: forefront selection, what moved
to evidence, what was cut, sources consulted, and any metric identified as
collected-but-not-surfaced (which feeds Phase 3) or not-collected-but-valuable
(which becomes a separate agent-side proposal, out of scope here).

---

## Phase 3 — Collected-But-Unsurfaced Data Audit

Mechanical, high value, cheap: the collection already works.

Compare every field the polling parser stores against every field the page
renders. Three orphans have already been found incidentally — an unused graph
list that left nine existing Horizon graph definitions unreachable from the UI, a
dead machine-state classifier, and the graphs pane lost when Horizon was promoted
to its own tab. A deliberate sweep will find more.

Classify each unsurfaced field as: promote to a tile, add to evidence, wire an
existing graph family, or intentionally unused with a recorded reason.

### Exit Criteria

- Every parser-stored field is either rendered or listed as intentionally unused
  with a reason.
- Every graph family under `includes/html/graphs/application/` is reachable from
  the page, or documented as deliberately retired.

---

## Phase 4 — Horizon Pool Drawers

The pool drawer interaction is deliberate and must be preserved: the toggle, the
per-pool summary grid, the machine-state inventory, and the mobile layout that
collapses the grid.

Order of work: Phase 0 first so counts are correct, then re-evaluate what the
drawer surfaces given correct data, then apply the Phase 1 vocabulary without
regressing the interaction.

### Exit Criteria

- Pool toggle, summary grid, machine inventory, and mobile layout all behave as
  before.
- Displayed per-machine states reconcile with the pool counts.
- Utilisation and degradation are visually distinguishable, not both red.

---

## Phase 5 — Validation Strategy

String-matching fixtures are weak for layout. Three levers:

- **Local visual review.** The app-page fixture runner already supports a render
  mode and a dark-theme parameter, so every scenario can be rendered locally in
  both themes with no cluster involvement. This is the backbone of the UI pass.
- **Structural assertions** rather than string matching: tile counts per tab,
  exactly one active tab and one active pane, no rendered-but-empty section, tab
  ordering, and no disagreement between a row's state and its aggregate count.
- **Live oracles** for Horizon: the vendor-disagreement metric and whether the
  capacity scope varies over time.

Each phase also runs the existing tiers: portable .NET suite when agent code is
touched, parser and app-page and central-collector fixtures, PHP lint over source
and packaged output, and packaged-artifact verification including a manifest
reconciliation.

---

## Release Sequencing

One version per phase that ships, each becoming the installer default in the same
commit.

| Order | Content | Agent change |
| --- | --- | --- |
| 1 | Phase 0 — Horizon state taxonomy, capacity correctness, redacted state dump | none |
| 2 | Phase 1 — tab contract and shared renderer | none |
| 3 | Phase 3 — unsurfaced data audit, wiring orphaned graphs | none |
| 4+ | Phase 2 — per-role forefront changes, grouped by role | none expected |
| last | Phase 4 — pool drawer refinement | none |

Phase 3 is sequenced before Phase 2 because knowing the full set of available
metrics is an input to choosing which ones lead.

Phase 2 may identify valuable metrics the agent does not yet collect. Those are
recorded as agent-side proposals and handled as separate work, since they need an
agent release and a protocol addition.

## Open Items

- The machine-state distribution from a live deployment is wanted for Phase 0
  reconciliation and as a before-and-after baseline. It is not a blocker: the
  taxonomy can be built from the vendor enum plus a safe unrecognized class. The
  redacted distribution dump added in Phase 0 removes this dependency permanently.
- Whether the Horizon workspace should eventually adopt the generic contract fully
  or stay a deliberate exception is deferred until Phase 1 is applied elsewhere.
