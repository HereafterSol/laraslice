# Porting reference — real-usage verification & release gates

Section 18 of the porting skill: per-domain real-usage journeys, access reconciliation, and the release gates. Verbatim; the core SKILL.md routes here.

## 18. Real-Usage Verification, Access Reconciliation & Release Gates (Tier 0 standard)

A port is NOT done when its pages render for the super admin. It is done when
real people in real roles can complete real journeys. Three layers, all
mechanical, all required before any release claim:

**The trilogy is the contract.** Mind (`porting/system.yaml`) = clubs,
journeys, decisions — never executes. Body (`porting/ui-spec/<slice>.ui.yaml`,
schema v2 in the framework's `docs/MIND_BODY_HEART.md`) = per-slice pages:
list, **form** (per-field input/relation/default + `system_fields` that must
never render), detail, and **access** per club with data scope (`all`/`own`).
Heart (`slice.yaml`) = the runtime truth the generator reads. A list-only
Body is an unfinished draft.

**Access reconciliation (`slice:drift`).** Clubs map to roles via `maps_to`
in the Mind (names rarely match). Patterns use the REGISTRY's vocabulary
(per-slice singular bases: `leaf.*`, `task.*`, `project_time_log.*`) — domain
prefixes (`work.*`, `billing.*`) match nothing and are drift. `X.apply`
requires `X.create`; `X.view-own` is a framework construct slug. Run
`php artisan slice:drift` — **zero errors is a release gate** (empty roles,
ungranted club promises, unmapped clubs). `slice:drift --fix` grants mapped
roles what their clubs promise, creating view-own permission rows when
missing; it NEVER widens a view-own promise into a full view grant.
Per-person **overrides** (the Mind's second layer) live in `user_permissions`
(grant/revoke per person, enforced by `Access` via `PermissionOverrides`;
a revoke beats the club grant; supers bypass).

**Form hygiene is generator law** (FieldDefinitionNormalizer): manifest
`show_in_form`/`hidden` flags are honored; system/audit columns (`*_by`,
`approved_*`, `event_id`) default OFF forms; `*_date`/`*_on` datetimes render
as date inputs; relations render as pickers, never raw ID inputs. Workflow
state fields are server-set: the base controller applies the workflow's
`initial_state` on create when the field is empty — a record saved with an
empty status is a defect. Own-scope: a `view-own`-only grant narrows lists to
the viewer's own records and 404s other people's detail routes. What "own"
means is the Heart's `access.own_scope` clause list — `{field: <column>}` or
`{relation: <name>, key: <related column>}` entries, ANY match wins (default
when undeclared: the `user_id`/`owner_field` column). Declare the real shape:
projects are the client's by `client_id` AND members' by the `members`
relation; invoices are the client's via `{relation: project, key: client_id}`.
A third clause form covers membership pivots without needing an Eloquent
relation: `{pivot: project_members, foreign_key: project_id, user_key: user_id}`.

**Record actions, public links, print, imports (the D6/D7/D9
layer).** Declared `ui.actions` (RecordActions) come in six
types: `duplicate` and `transition` (v1), plus `link` / `copy`
(URL templates — `{id}`, `{show}`, `{edit}`, `{public}`, any
record attribute like `{hash}`; gated on view), `send` (mails
the record's link to its client email; gated on update) and
`method` (calls a named method on the slice's own service —
where slice-specific behaviour like a lead→client conversion
belongs). A slice declaring `ui.public_link: {column: hash}`
exposes the record unauthenticated at
`laraslice/public/{prefix}/{token}` — in a port the token is
the source's own stored hash, so links the source already
mailed keep working. Any show page with `?print=1` renders
as a clean document (chrome hidden, print dialog opens) —
that is the Download / View-PDF surface. Deletes confirm
through ONE styled dialog ("Are you sure? … Yes, Delete
It!") shared by the row menu, show pages and bulk delete —
never the native confirm. Declared CSV imports
(`ui.directory.import`) upsert by their declared key and
force-fill the declared columns: the declaration is the
allowlist, so a mapped field missing from `$fillable` must
never silently vanish.

**The scope layer (Option B).** Grants carry the source's permission types —
`added / owned / both / all / none` on `permission_role.permission_type_id` —
and the porter resolves them **by type NAME, never by id** (ids differ across
installs; an earlier binary carry read id 1 as 'none' where it meant 'added'
and inverted the whole matrix). `Access::scopeFor` resolves the widest scope
per user+slug (added+owned merge to both; a typeless legacy grant counts as
'all'); lists and detail routes narrow by scope — owned via `own_scope`,
added via `added_by`, both via the union — and a compound sub-resource grant
(`view_project_discussions`) never widens its parent slice's scope: the
exact-entity grant owns the scope. A viewer may always open the record that
IS them (their hub) even with no view grant on that slice.
A persona the Mind names (e.g. an external Client) is not real until its club
exists in the Mind, its role exists, and `slice:drift` reads 0 errors —
`--fix` creates missing view-own rows and grants mapped roles, but never
creates roles and never widens view-own into view.

**The real-usage test (per domain, before release).** Drive BOTH the source
and the port through the same journeys in a real browser, as super admin AND
as an employee-role user, with the same seeded person: create/edit/view every
form, open every tab, approve/reject a workflow item. Assert VALUES, not
renders: the saved row's status, the scoped list's row count, the other
person's detail returning 404, the notification landing. Structural diffing
(admin-only, header-level) cannot see empty columns, 403 walls, or missing
defaults — the 23 Oct HR test found all three on a port that had "passed"
every structural audit. The form audit (Body v2 vs the live create form:
system fields rendered, declared fields missing, relations as raw inputs,
workflow state editable) must return zero findings per slice.

**The fleet interaction sweep (every visible module, before a
replica or release claim).** Per-domain journeys prove the
benchmark; they do not exercise the fleet. Once per port,
drive EVERY module in the sidebar on BOTH systems: census
its buttons, filters, row menu and delete dialog; exercise
one filter per module and compare counts; download one
export per module and compare rows; on the port only, run a
full create → edit → delete cycle with a throwaway record
per module family and assert in the database — then delete
the probes and re-verify the dataset matches the source.
Two defect classes hide exactly here (1 Nov 2026 interaction
audit of a production-ERP port, after every benchmark gate
had passed): a directory Delete that dispatched a UI event
nothing listened for — the confirm closed, no request was
sent, the record silently survived — and create forms that
500'd on every module whose declarations modelled a
belongsTo lookup as a child repeater. Rule: a control is
not verified until its effect is observed (network or DB),
never from its render; and `slice:drift --structure` flags
the repeater defect statically — run it.

**Port completeness checklist (from the 24 Oct full re-audit).** CRUD
parity is not port parity. For every source module, enumerate — from
the live page, not the schema — and then declare in the three files
or record as a deviation:

- **Record workflow actions** beyond CRUD: send / remind / duplicate /
  archive / convert (estimate → invoice) / copy / public link /
  download / PDF / sign. These live in row menus and detail headers;
  a slice that only has CRUD has the data, not the workflow.
- **Bulk**: does the source list have row checkboxes and bulk
  actions? The directory must declare them; absence is a deviation.
- **Export / Import** presence per list (the machinery exists —
  enablement is a declaration, per slice).
- **Reports**: source report pages (attendance, finance, leave,
  sales, task, time-log…) are pages, not slices of a table. A port
  with no report pattern has a named gap, never a silent one.
- **Settings surfaces**: every settings section in the source maps
  to a declared settings section (the `bind:` pattern), a platform
  surface, or a recorded deviation — count the source's sections.
- **Timers & clocks**: per-record timers, a global timer, and
  attendance punch actions are interactions, not decorations.
- **Route-name uniqueness**: same-named slices MUST NOT share
  route-name bases — the generator path-qualifies nested slices;
  after any port or regeneration run `slice:links` and require
  zero route claims, or every list's links jump to the wrong twin.
- **Twin ports**: porting a module whose slice name is already
  installed (different table or different app) namespaces the
  incoming slice at mapping time (app-qualified, e.g.
  `AccountingInvoices`; renames are reported in the port
  result) — it never shadows the incumbent. A re-port of the
  SAME slice (same name, table, app) keeps its name and
  updates in place. In `slice:links`, shared-TABLE claims
  between a twin pair are expected facts; registry NAME
  collisions after a port are defects.

## The export, reports and messages layer (1 Nov 2026, late)

- **Exports are server-side XLSX.** The directory Export control
  downloads the full filtered, scope-narrowed list as a real
  workbook (`{prefix}/export`, `XlsxWriter`) — never a CSV of the
  rendered page. Filters ride the same criteria builder as the
  directory itself (`applyDirectoryCriteria`): an export that
  disagrees with the on-screen list is a defect. `*_id` cells
  resolve through the form's option labels.
- **Reports are declarations, not pages.** The source's report
  pages map onto `ui.directory.report` ({group_by, aggregates:
  count|sum|avg, date_field}) on their home slice, plus named
  reports under `ui.directory.reports[]`; multi-stream money
  comparisons (Income Vs Expense) use a named report's
  `compare` sources. Group labels resolve through form options
  first, then the model's own relation (source table names
  rarely match conventions — board_column_id →
  taskboard_columns). An empty report is honest when the
  source data is empty too (the source had zero orders).
- **Messages are a conversation declaration.** Pair mode
  (conversation + replies tables) or `ui.conversation.flat`
  for the common source shape — one message table
  (from/to/body/seen): conversations derive from the pairs,
  the conversation id is the other user's id, opening marks
  incoming rows seen, and sends fill the legacy mirror
  columns (user_one/user_id) when the table carries them.
  Check the table was actually CARRIED: an unowned table
  (users_chat had no slice) never crosses in the data port.
- **Reference tables are carry surface too.** pipeline_stages
  arrived empty while deals referenced its ids — reports and
  directories printed raw ids. When a label resolves nowhere,
  suspect the reference carry before the label machinery.
