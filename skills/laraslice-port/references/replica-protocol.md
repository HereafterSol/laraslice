# Porting reference — the 100% Replica Protocol (§22)

Section 22 of the porting skill in full: the five gates in plain names, the evidence discipline, and the porting runbook. Verbatim; the core SKILL.md carries the compressed protocol and routes here for the full text.

## 22. The 100% Replica Protocol — porting any existing system

**Playbook (phases + agent checklist + greenfield branch):** `docs/PORT-100-PLAYBOOK.md`.

A replica claim is a MEASURED state, never an impression.
"100%" is earned gate by gate, and a port's status is its
LOWEST unverified gate. Five gates, in order; each produces
evidence, and all four feed one status file (below). The
porter workflow (§12/§16) does the building; this protocol
decides when building may call itself a replica.

**The five gates, in plain words** (always say the plain name
first; the letter is only a shortcut):

| Gate | Plain name | The one question it answers | Example |
| --- | --- | --- | --- |
| S | **Structure** | Is the database built the same? Every table and column the source has — or a written reason. | The employees table is missing `joining_date` → S fails. |
| D | **Data** | Is the actual data the same? Rows and values, checked record-by-record against the source. | Employee #12's hourly rate reads 97 in both → that record passes D. |
| F | **Furniture** | Is everything on the screen? Every tab, button, card, column and menu the source shows. | The hub is missing its Reporting card → F fails. |
| T | **Tasks** | Can a real user DO the work? Login, open, edit, save, create — scripted journeys, admin AND employee. | The employee logs in and opens her own hub → that journey passes T. |
| V | **Visual** | Does it LOOK the same? Side-by-side screenshots, read by eye — layout and composition, in the port's own design skin. | The statistics rail sits under the chart instead of beside it → V records the delta. |

A founder reading a status line should need nothing else:
"Structure ✓ · Data ✓ · Furniture ✓ · Tasks 24/24 · Visual —
2 deltas" means the port works and matches, except two named
look-and-feel differences. If a gate has never run, the status
says so — silence is not a pass.

**Phase 0 — the composition map (before any table work).**
Tables are not the unit of porting; surfaces are. After
Analyze, agents MUST execute **§16.5.1** so nav and pages
follow the source — not the default one-table-one-sidebar
porter layout. From the source's routes, controllers and
views, list every surface
whose read or write spans more than one model: users-backed
directories (employees = users + details; clients = users +
client details), documents (invoice = header + items +
totals), hubs (record + relations + widgets), wizards,
joined reports. Each becomes a named composition with one
owner slice BEFORE schemas are ported. A directory declared
over an invented or mirror table the source does not have
is a defect at this phase, not a design choice. Cross-model
creates follow the account-backed rule (§21): one form, one
transaction, all models written. **Twins**: when two port
slices claim the same source surface (a pilot and a tree
port of one page), the map names BOTH, and every later gate
pairs EACH twin — or records it as a deliberate duplicate
with its own verdict. Pairing only the healthy twin while
the other renders placeholders is how a hollow flagship
page survived three audits (QA8, 26 Oct 2026).

**Gate S — schema parity.** Column-level diff, source table
↔ ported table: every source column exists (name, type
family, nullability) or carries a written reason the owner
approved. Dropped-column defects (a parent_id, a lifecycle
date set, a status vocabulary) are found HERE, by diff —
never later, by a user. Generated forms never render system
columns (§21.6): audit stamps, workflow state, and FK
bookkeeping are classified in the Body, not discovered in
the browser.

**Closing an existing port.** Fresh ports are closed by
construction: the migration inspector replays `Schema::table`
alters from `up()` in filename order, so columns the source
added after its create migration are in the inspection set.
A port scaffolded before that (or from any partial schema
source) is closed with `slice:derive-schema-closure`
(dry-run diff first; `--apply` writes one guarded migration,
appends the fields to the manifest, the Schema form and the
Listing/Form business objects, and copies the values by
primary key). The BO step is not optional polish: a column
that exists in the table and the manifest but not in the
listing BO still renders empty. Framework infrastructure
tables, the auth-merged tables and `deleted_at` are excluded
by design and reported as such, never silently skipped.

**Gate D — data truth.** ETL is proven against the source,
never against the loader's own output: row counts per table,
aggregate reconciliation (sums on money columns, counts per
status), FK integrity (every foreign key resolves), and
join-level sampling — pick real entities and compare the
JOINED record (user ⋈ details, invoice ⋈ items) field by
field in both systems. Mirror tables must reproduce the
source's pairings exactly; a shifted pairing (right name,
wrong company) is a Gate D failure even when counts match.

**Gate F — furniture conformance.** §21's page contract:
every paired page diffed at the element level — headers,
cards (declared only, never inferred), actions, counts,
composition order — with deltas classified D/M/S and either
fixed or carried as owner-approved deviations. Dashboards
are paired PER PERSONA: counter values are compared for
each role that lands on them, and person-domain counters
must be own-scoped (see Dashboard widgets — Widget
scoping).

**Gate T — task conformance (real usage, mandatory).**
Per domain, the core journeys are EXECUTED in both systems
with identical input and the outcome asserted in the
database: create with the FULL form filled (every field the
source accepts must persist in the port), edit, delete, each
declared action, filters returning the same row sets. A page
can pass Gate F and fail Gate T (a beautiful form that
writes one of three models) — the port is not a replica
until both pass. Personas matter (§18): run key journeys as
the roles that actually perform them, not only as admin.

**Gate V — visual conformance (composition, read by eye).**
Paired screenshots of the flagship surfaces are READ side
by side — shell and navigation model, topbar furniture,
page-header pattern, the composition blocks in their
order, density and treatment. A computed-style probe over
sampled elements is NOT a visual gate (QA9, 26 Oct 2026:
a token kit passed every probe while the shell, the
dashboard and every hub remained a different composition).
A style/token layer over standard markup can satisfy
Gate V's token row only; layout divergence is recorded as
Gate V divergence no matter how green Gates F and T are,
and a replica claim names Gate V with the rest.

**Gate V — the shell is a scored surface, not backdrop
(29 Oct 2026).** A port's sidebar, page titles and directory
chrome are part of the replica, scored element-by-element
like any directory or hub — a surface is not "converged"
while the shell around it still shows raw slice names.
The executable check: capture the source's menu as a probe
(ordered groups of label + href from its own sidebar), run
`slice:derive-navigation --probe=<menu.json> --hide-children`
(dry-run first — its report IS the shell audit: matched
labels/groups/order, hidden child slices, unmatched menu
items named as coverage gaps or curator aliases), apply,
and score the rendered sidebar against the probe. Directory
chrome is scored from the same paired screenshots: the
create button's label (`ui.directory.create_label`, derived
from the probe's primary action — never the generator's
"Add <SliceName>" when the source's words differ), every
declared directory action (invite / import / export — an
action renders only when its contract is declared, e.g.
`ui.directory.invite` gates the invite route), and the page
title/breadcrumb (the navigation label, not the slice name).
`REPLICA-STATUS.md` carries a shell line under Gate V with
the derivation report as evidence.

**The status file — `REPLICA-STATUS.md`.** Every port keeps
one at its root, updated at every gate run, in this shape:
- Header: source system, date of last full run, overall
  status = the lowest gate, in plain names first (e.g.
  "Furniture ✓ · Tasks 82% — status: Tasks").
- Per-gate table: scope, measured result, evidence links
  (diff outputs, screenshots, registers).
- Per-domain task matrix: journey × result in both systems.
- Deviations: each delta not fixed — classified, with the
  owner's approval or "awaiting owner" stated plainly.
- NOT covered: everything untested, named explicitly.
The AI answers "what is the replica status?" FROM this file
— offline, without re-running anything — quotes its date,
and never upgrades a number the file does not back. When
the file is stale or a gate has never run, the honest
answer is the gap, not a guess. A port with no status file
has no status.

**Declaring 100%.** Only when all four gates read 100% on
the same run, the deviations list is empty or fully
owner-approved, and the NOT-covered list is empty. Anything
less is reported as its real number — "structurally 100%,
tasks 82%" — because a developer planning around a false
100% inherits every hidden gap as a production surprise.

### The porting runbook — follow it in order

**Developer track (CLI / Studio):**

1. **Capture the anatomy first.** `slice:port --anatomy` on the
   source records every page's interaction inventory (page type,
   export/import, bulk selection, workflow actions) as porting
   evidence. For a non-Laravel source, point the porter at its
   SQL dump or live database — it hard-fails on an ambiguous
   source DB rather than guessing.
2. **Write the Phase 0 composition map** (above) before porting
   a single table: the cross-model surfaces, the account-backed
   directories, the documents, the hubs.
3. **Port.** `slice:port` (add `--permissions` for the source's
   role × permission matrix, `--table-prefix` to coexist with an
   existing install). Name collisions resolve by twin
   namespacing — the incumbent keeps its name, the twin is
   qualified by its app; renames are reported, never silent.
   Two flags decide whether the replica actually works:
   `--import-auth` is **required for a working replica** — it
   merges the source's roles/permissions and role assignments
   (by slug; framework roles are never overwritten). Without
   it the auth tables are skipped by design and imported users
   arrive with **no role assignments**: the app looks complete
   and every account is permission-less. The porter warns when
   a data import runs without it; treat that warning as a
   failed step, not a footnote. And when scaffolding and data
   import run in one invocation, the order is scaffold →
   migrate → import; an import that reports tables skipped
   because their targets did not exist imported nothing —
   migrate, then re-run it. Generated manifests always declare
   their root model in `models:` (`root: true`) and generated
   dashboard widgets are `counter`/`chart` definitions the
   runtime actually renders — a widget type the dashboard does
   not dispatch renders nothing and is never emitted.
4. **Move the data, then prove it.** Run the ETL, then verify
   Gate D by joins against the source — never by the loader's
   own row counts.
5. **Declare the surface.** Filters with defaults, hub tabs and
   composition (`ui.show.composition: overview`), dashboard
   widgets, the replica shell on ported trees. Everything is a
   Heart/Body declaration; per-page code is a smell.
6. **Repair + cache.** `slice:repair` retrofits the declared
   patterns fleet-wide (idempotent, evidence-gated), then
   `slice:cache`.
7. **Walk the gates in order** — S, D, F, T, V — and write the
   results into the port's `REPLICA-STATUS.md`, residuals and
   NOT-covered list included. The port's status is its lowest
   gate.
8. **Ship by the release boundary:** working demo + a changes
   file + the owner's approval before any push, tag, or release.

**AI track (an assistant porting with this skill):**

- Read §21 (page structure) and this section *before* touching
  the source; then work the developer track — the steps are the
  same, your evidence discipline is stricter.
- Never port table-by-table without the Phase 0 map: an
  invented-table directory is a defect you created, not a
  source behavior you discovered.
- Quote replica status **only** from the port's
  `REPLICA-STATUS.md`, with its date. Never upgrade a gate from
  a probe: Gate V is read from paired screenshots, by eye — a
  token measurement is not a visual pass, and reporting one as
  the other is the exact failure this protocol exists to stop.
- Verify every claim against the running systems (both of
  them), value-for-value where the source exposes values.
- Fix at the generator / standard layer so the next port
  inherits the fix; a per-slice patch that only this port
  enjoys is unfinished work.
- Deviations are the owner's call: record them, never approve
  your own.
