# Porting reference — the scored port matrix, behaviour inventory & scope record

The matrix is the port's single checklist: one row per thing the source
does, born at recon, updated by every stage, scored at the gates. It sits
BESIDE the five gates — the gates stay the verdict layer (status = lowest
gate); the matrix score is the legible number a founder can read without a
briefing. Adopted 7 Nov 2026 from replica-skill's discipline (study:
`replica-skill-study-2026-11-07/STUDY.md`); the tools are stdlib Python in
`skills/laraslice-port/tools/`.

## The artefact — `porting/port-matrix.csv`

Columns (exact order):

```
feature,area,priority,source,port,evidence,notes
```

- **feature** — what the source does, in plain words ("create an invoice
  with line items", not "Invoices CRUD").
- **area** — the module or surface (HR, Work, CRM, Finance, Support,
  Reports, Platform, Access, Data…). Areas are the roll-up unit; keep the
  vocabulary small and stable per port.
- **priority** — `must` (a real user job breaks without it), `should`
  (expected parity; its absence is a named gap), `could` (fidelity garnish).
- **source** — `yes` | `no`. `no` marks a port-side addition (Studio,
  payroll-on-Laravel): excluded from the score, listed apart, never counted
  as parity.
- **port** — `yes` | `partial` | `no` | `skip`. `partial` means the surface
  exists but a named part is missing or divergent — the missing part goes in
  notes. `skip` means deliberately not ported; **the reason is required in
  notes** — a skip without a reason is a silent gap and the scorer flags it.
- **evidence** — where the port value was MEASURED: a gate evidence file, a
  ledger row, a status-file section, a screenshot pair. A row whose value
  rests on memory is `no` until measured. This column is what makes the
  score quotable.
- **notes** — the delta, the deviation id, or the skip reason.

## Lifecycle — who writes the matrix, when

1. **Recon (happy-path step 1)** creates it from extraction: schema graph →
   structural rows; navigation census → surface rows; the behaviour
   inventory (below) → behaviour rows. Every row starts `port: no`.
2. **Compose/Port (steps 2–3)** flip rows as declarations land: a row turns
   `yes` only when its surface is declared AND generated — never at
   scaffold time on a promise.
3. **Gates (step 4)** are where rows earn their values: each gate run
   updates the rows it measured (Structure → structural rows, Tasks →
   journey rows, Visual → layout rows with the layout_diff score in
   evidence). Rows a gate did not touch keep their old value and their old
   evidence — the evidence column's date is the row's freshness.
4. **The diff loop** (verification-gates.md §18) re-scores after every fix
   batch; the missing list, in build order, IS the next work queue.

## Scoring rule

```
python3 tools/port_matrix.py porting/port-matrix.csv            # full report
python3 tools/port_matrix.py porting/port-matrix.csv --markdown # for a report file
python3 tools/port_matrix.py porting/port-matrix.csv --status-line
python3 tools/port_matrix.py porting/port-matrix.csv --visual porting/evidence/layout/*.json
```

- Weights: must 3, should 2, could 1. Credit: yes 1, partial ½, no 0.
- Skips and port-side additions are excluded and listed separately.
- Per-area scores print weakest first; the missing list prints in build
  order (priority, then least-done first).
- **Verdict line:** any must-have not fully done (partial counts as not
  done) → NOT SHIPPABLE. All must-haves done and score ≥ 80 → SHIPPABLE.
  The score NEVER upgrades a gate: a port can score 94 and still stand at
  its lowest gate. Say both, always.

**REPLICA-STATUS score line** — one line, directly under the gate verdict,
produced by `--status-line`, dated:

```
Parity score (7 Nov 2026, porting/port-matrix.csv): 93.2/100 · must-haves
57/58 · weakest: Data 75.0, Platform 85.7, Support 90.9 · NOT SHIPPABLE —
1 must-have(s) not fully done. (Score only — the gate verdict above stands.)
```

## The behaviour inventory — recon's behaviour artefact

Extraction reads schema and templates; behaviour lives in the source's
controllers, and a port that inventories only structure discovers its
behaviour gaps at Gate T — the most expensive place to find them (the ERP
port's write-side family — status defaults, validation, document builders —
was invisible until real flows ran). The inventory replaces the passive
provenance note with a first-class recon step:

1. **Enumerate per module:** every controller (or route group) in the
   source; for each, list its non-CRUD behaviour: custom actions
   (convert, send, remind, approve, generate), store/update side effects
   (computed totals, status defaults, multi-model writes), validation
   rules beyond presence, document builders (line items, taxes,
   discounts), scheduled/background effects, public/print surfaces.
2. **One matrix row per behaviour**, priority by user impact (a default
   that silently writes NULL is `must`; a cosmetic action is `could`),
   evidence = `source: <Controller>@<method>` until the port is measured,
   then the measuring gate.
3. **A behaviour with no declaration home** (not expressible as a Heart
   field/default, a Body pattern, or a service method action) is recorded
   in the port's deviation register at recon — priced before generation,
   not discovered after it.

Method note: read the controller, not the route name. `store()` that only
persists the form is CRUD; `store()` that also writes items, stamps a
status and mails a link is three behaviours and three rows.

## Scope, skip list & sizing — decided BEFORE generation

The percentage fights start when scope is argued after the build. Decide
it at recon, in the open:

1. **Scope line** in the port plan: what is IN (modules/surfaces), in one
   sentence each, matching the matrix areas.
2. **Skip list**: every source surface/table family deliberately not
   ported becomes a `skip` row with its reason (system table, addon not
   activated in this install, derived index, licensed content, owner
   decision). Skips are excluded from the score BY DESIGN — that is what
   makes the remaining number honest.
3. **Undecided scope is an owner-decision line**, written into
   REPLICA-STATUS and the matrix notes the day it is found — never a late
   surprise wearing the clothes of a percentage. Until the owner decides,
   the rows score `no` (they are real source behaviour the port lacks);
   the decision, when it lands, converts them to `skip` with the decision
   as the reason.
4. **Size class** for the port, from the recon counts (tables, surfaces,
   behaviour rows): S (days), M (weeks), L (a quarter), XL (rescope before
   starting). Record it in the plan with the counts that justify it. No
   size class licenses a "100%" promise — the matrix score is the only
   percentage a port may quote.
