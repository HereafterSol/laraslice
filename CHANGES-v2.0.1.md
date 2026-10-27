# v2.0.1 — The Verification Patch

Released 2026-10-27

Every fix in this release was found by an independent verification programme run against the shipped v2.0.0: the full framework test suite executed on a second platform, a fresh install of the flagship package on a clean machine, and a from-scratch re-port of a production application using only the shipped skill and protocol. The port was measured with the five Replica Protocol gates; its status document is the evidence base for this release.

## Fixed

- **Data porting order.** A combined scaffold + data-import run now migrates before it imports (scaffold → migrate → import on every entry path: CLI, service, and Studio). An import that skips tables whose targets do not exist reports them by name instead of returning success with zero rows.
- **Auth import is no longer silent.** Porting data without `--import-auth` warns, naming the skipped auth tables (roles, permissions, permission_role, role_user) and the remedy. The porting runbook now states the flag is required for a working replica: without it, users import with no role assignments.
- **Porter output renders.** Generated manifests always declare their root model in `models:` (`root: true`), and generated dashboard widgets are `counter`/`chart` definitions the dashboard actually dispatches — previously the porter emitted a widget type that does not exist, so fresh ports opened on an empty dashboard. Where a slice's own-scope clauses are available (repair path), counters are emitted own-scoped inline.
- **Hub tab curation.** A hub's tab strip can now be fully curated by declaration: `ui.show.exclude_tabs` (by relation or handle) and per-tab `hidden: true` suppress inferred or declared tabs; a declared audit (`activity`) tab renders the record's audit trail from the framework audit log, or a proper empty state, instead of vanishing; the fields tab honours its declared label (e.g. Profile) instead of a hard-coded default.
- **Portal dashboard scoping.** The skill's portal-parity rule is now stated and gate-checked: on a portal-composition dashboard, counters over person-owned domains declare `scope: own` with the slice's own-scope clause inline, and Gate F requires per-persona dashboard pairing. Field-validated on an independent port: the scoped replica reads exactly the source's per-person figures, where the unscoped flagship read global totals.
- **Generation and repair integrity.** Slice discovery is invalidated after generation; artisan entry commands run against a fresh registry; manifest reads defeat the PHP stat cache; `slice:repair` is a true fixed point (repeated runs are byte-identical).
- **Permissions correctness.** Roles create/edit no longer reference schema columns that were never shipped (all such access is capability-guarded); row actions in generated listings and hub action surfaces are permission-gated, so view-only users no longer receive edit/delete actions; schema sync can no longer drop the `title`/`status` columns.
- **Studio and settings.** The Studio add-field flow reaches the form view again; the AI settings save route is no longer shadowed by the settings hub catch-all; `slice:blueprint:validate` takes its target as `path`.
- **Mind drafting.** `slice:plan:draft` no longer assumes an optional roles column exists; it reads the roles table capability-first.

## Flagship package

- Install config no longer carries a machine-specific slices path — a fresh install discovers all slices after the documented steps.
- The shipped seed database honours every login the install guide promises.
- The carried-over schema portfolio is complete for the audited domains (the genuine-gap class of the Gate S diff is closed: HR, support, communication, commerce, and finance tables), with data verified byte-for-byte or by email join against the source.
- Dashboard counters are portal-scoped; the three main hubs carry curated tab declarations.

## Tests

- Full suite green on two platforms: **555 tests, 1919 assertions**, zero errors/failures/warnings/risky. (The suite shipped in v2.0.0 measured 535 tests with 47 reds on its own baseline run; the reconciliation that produced this release's fixes brought it to zero, and this release adds regression tests for every behaviour above.)
