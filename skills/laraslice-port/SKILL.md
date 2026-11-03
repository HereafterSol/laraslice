---
name: laraslice-port
description: Port an existing system onto LaraSlice — legacy analysis, composition mapping, the porter workflow, schema closure, and the Replica Protocol with its five gates in plain names (Structure, Data, Furniture, Tasks, Visual). Use when the user is migrating, reverse-engineering, or replicating an existing application (Laravel or not) with LaraSlice, or asks about replica status. Load the `laraslice` core skill first; use `laraslice-start` instead for a brand-new project.
---

# LaraSlice — Porting an Existing System

**Load first:** the `laraslice` core skill (the trilogy,
the fields story, the concepts map). This skill is the
porting door only: analyze → compose → port → prove with
the five gates. Replica status is quoted ONLY from the
port's `REPLICA-STATUS.md` — the lowest gate is the status.

This file is the **core**: the happy path, the gates, and
the non-negotiables, sized to load always (≤5K tokens).
Depth lives in `references/` beside it — load a reference
only when the step you are on names it. Nothing was
deleted in the restructure; every original section has
exactly one home in the map at the bottom.

## The happy path (follow it in order)

1. **Analyze the source where it runs.** Inspect the
   running program, not screenshots: schema graph, routes,
   controllers, permissions, seeders, the navigation as
   rendered. One behaviour per investigation; a prediction
   before inspection; a check that can prove you wrong.
   Produce `porting-architecture-plan.json` before any
   generation. → `references/extraction-workflow.md`
   (§12–14), `references/enterprise-and-analysis.md`
   (§16.5 AI-first analysis).
2. **Compose the declaration.** Map the source onto
   Mind/Body/Heart: domains and slices from the source's
   own bounded contexts (siblings vs sub-tables decided
   per table), fields only in the Heart, UI intent in the
   Body. Follow the source — for replicas, the source's
   rendered behaviour is the specification (fidelity
   mandate, §16.5.1). → `references/extraction-workflow.md`
   (§12 steps 2–7), `references/ui-contracts.md` (§17).
3. **Port.** `php artisan slice:port` (dual-source
   discovery: migrations + live database), then schema
   closure (`slice:derive-schema-closure`), navigation
   derivation (`slice:derive-navigation`), and the
   slice-aware data import — referenced lookup/settings
   tables included, identity remapped by natural key,
   role grants carried by name translation.
   Non-Laravel sources (legacy PHP, SQL dumps) have their
   own intake. → `references/enterprise-and-analysis.md`
   (§15.3, §16.11–16.13),
   `references/non-laravel-and-manifests.md`.
4. **Prove with the five gates** (below). Fix every
   residue at its own layer — kit / pattern / declaration
   / generator — never per-page. Record the outcome in
   the port's `REPLICA-STATUS.md`; that file is the only
   quotable status. → `references/replica-protocol.md`
   (§22, full text + runbook),
   `references/verification-gates.md` (§18).

## The five gates (plain names first)

Status = the LOWEST unverified gate, on a declared scope.

- **S — Structure.** Does the declaration cover the
  source's shape? Schema closure diff = 0; every table,
  column, and relation the source uses exists in the
  port's manifests. (Port-local remediation is not a
  framework capability — say which one you have.)
- **D — Data.** Do the values match? Row counts and
  spot-joins by natural key (email, codes) across every
  shared table — including the slice-less lookup and
  settings tables the import is prone to skip.
- **F — Furniture.** Do the surfaces carry the source's
  elements? Directory columns, hubs and their tabs,
  widgets, the shell — navigation, buttons, page titles —
  audited element by element, shell FIRST (a surface is
  not converged while its shell is raw).
- **T — Tasks.** Can real users do real work? Per-domain
  journeys for each persona (admin and employee at
  minimum), values asserted on screen, edits round-trip
  without silent data loss.
- **V — Visual.** Does it LOOK like the source? For a
  replica port this gate is literal (§19B tolerances):
  same kit decision (house vs replica is declared in the
  Mind — `design.kit`), same layout, same values in the
  same places, screenshot pairs as evidence.

Converge = `slice:drift` zero errors + the gates.
Never self-report; a percentage headline always carries
its declared scope ("benchmark-converged" and
"whole-port partial" are different claims).

## Non-negotiables

- **Zero hardcoding in the framework.** Anything the
  porter or a gate needs to know about a source system
  is derived, declared, or recorded as a deviation —
  never baked into framework code (§16.1).
- **Code-first ground truth.** The running source and
  its code are reality; documents about it are context.
  When they disagree, the code wins and the document is
  corrected.
- **Fix at the layer that owns it.** A miss found on one
  page is a kit/pattern/declaration/generator fix, so
  the next port inherits it. Per-slice CSS and one-off
  patches are forbidden.
- **Deviations are the owner's call.** Record them in
  the port's deviation register; never self-approve.
- **Status comes from `REPLICA-STATUS.md`** — rendered
  truth, extraction counts labelled separately, subsets
  never counted as full-suite results.
- **Evidence is recorded, not remembered.** Journey
  gates write `porting/evidence/<suite>.json` (per-check
  pass/fail, unique ids, a timestamp); `slice:conformance`
  measures the port by that evidence and fails on a
  recorded failure — REPLICA-STATUS cites the evidence
  file, never the other way round. Declaration changes
  are ledgered automatically (`slice:history`, recorded
  by slice:cache and slice:sync), and
  `slice:sync --dry-run` is pure: it reports, it never
  writes.

## Reference map (load on demand)

| When you are… | Load |
|---|---|
| Starting the analysis; planning the migration phases; about to suggest code | `references/extraction-workflow.md` (§12–14) |
| Facing taxonomy/tenancy questions; doing AI-first source analysis; porting data; hardening RBAC | `references/enterprise-and-analysis.md` (§15–16) |
| Declaring UI; chasing composition or appearance parity; wiring list/page contracts | `references/ui-contracts.md` (§17, 19, 19B, 20, 21) |
| Running real-usage journeys or preparing a release | `references/verification-gates.md` (§18) |
| Running the Replica Protocol end-to-end | `references/replica-protocol.md` (§22) |
| Porting a non-Laravel source, or working YAML-only | `references/non-laravel-and-manifests.md` |
