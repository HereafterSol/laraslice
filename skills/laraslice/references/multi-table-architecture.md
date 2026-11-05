## 4. Multi-Table Slices vs Domain Suites (Real-World Architecture)

### A. Domain Slices with Cross-Slice Relationships (e.g. CRM: Companies & Contacts)
In real-world enterprise applications (large SaaS and billing platforms), entities like **Companies** and **Contacts** are **autonomous, first-class vertical slices** within a shared **Domain Group**:

```text
📁 app/Slices/Crm/
   ├── 📂 Companies/            <-- Independent Slice (/crm/companies)
   ├── 📂 Contacts/             <-- Independent Slice (/crm/contacts)
   └── 📂 Deals/                <-- Independent Slice (/crm/deals)
```

1. **Why Sibling Slices instead of Sub-Tables?**:
   - Contacts are people with their own lifecycles, emails, phone calls, and direct navigation.
   - Sales reps need global search across all contacts regardless of company.
   - **Single Canonical Edit URL**: Editing contact #1 is always `http://localhost:8000/crm/contacts/1/edit`.
   - **Anti-Pattern to Avoid**: Never generate duplicate conflicting route trees like `/crm/companies/1/contacts/1/edit` alongside `/crm/contacts/1/edit`. Duplicate routes cause developer confusion, broken breadcrumbs, and redirect ambiguity.

2. **Connecting Sibling Slices**:
   - In `Contacts` schema: `company_id` uses the `<x-ui.combobox-relationship>` with quick-add.
   - In `Companies` view: Render an "Associated Contacts" tab listing contacts where `company_id = company.id`, with an "+ Add Contact" button linking to `/crm/contacts/create?company_id={id}`.

### B. Composition Aggregates (e.g. `Order` -> `OrderItem` or `Invoice` -> `InvoiceItem`)
Use child sub-tables **only** when an entity has no independent business life without its parent:
- An `OrderItem` makes no sense outside an `Order`.
- It does **not** appear in the main navigation sidebar.
- It uses **Laravel Shallow Routing**:
  - `POST /orders/{id}/items` (Add line item to order)
  - `DELETE /order-items/{id}` (Delete line item by unique ID)

**Inline line items (repeater fields).** Aggregates whose children are
edited *inside* the parent form (invoice lines, order items) declare a
repeater field in `ui.forms.sections[].fields`:

```yaml
- handle: items            # form collection name; submits as items[n][field]
  type: repeater
  label: Items
  model: invoice_item      # child model handle
  table: invoice_items     # child table (synced directly)
  foreign_key: invoice_id  # child column referencing the root record
  repeater_fields:         # editable child columns (never the FK/tenant/audit)
    - { handle: name, label: Name, type: string, required: true }
    - { handle: quantity, label: Quantity, type: decimal, required: true }
    - { handle: price, label: Price, type: decimal, required: true }
  add_button_text: Add Item
```

The generator turns this into a DTO array property, a `hasMany` on the
root model, and `repeaterDefinitions()` on the service. The base service
then validates each row, syncs children transactionally on save (insert /
update-in-place by `id` / delete removed rows), loads them into edit
forms, and deletes them with the parent. Porter output populates
`repeater_fields` from the child table's columns, with `required`
following column nullability. A repeater whose rows are all removed
still syncs (the component submits a `_repeaters[]` marker), while a
partial API update that omits the collection leaves children untouched.

**Document arithmetic.** A repeater whose rows are money lines declares
a `document:` block on the field — which row fields are the quantity,
unit price and line amount, an optional row `type_field` (`item` adds
to the subtotal, `discount` subtracts, `tax` adds to the total only),
an optional per-row `tax_amount`, and the header fields that receive
the `subtotal_field` / `total_field`:

```yaml
  document: { quantity: quantity, unit_price: price, amount: total,
              tax_amount: tax, total_field: amount }
```

The form then renders computed line amounts (read-only) and live
Subtotal / Total footers, and the base service recomputes everything
on save via `Support\DocumentMath` — client-submitted amounts are
never trusted. Declare the header total field read-only in document
forms; it is an output, not an input.

**Detail-page composition (hub tabs).** A slice's show page composes
related-record tabs from the manifest, resolved at request time by
`DirectoryUi::relatedTabs()` in this precedence: (1) `ui.show.tabs`
entries with `type: relation`, (2) the root model's declared `hasMany`
relations, (3) repeater form fields. The base web controller resolves
tabs from *its own slice's* manifest (duplicate slice names make
name-based lookup unreliable) and passes them to the view with live
relation counts. Declared relations must exist as methods on the model:
the generator emits them from the manifest relations it is given
(deduplicated against repeater and foreign-key relations), resolving
target classes through `SliceManager::modelClass()` — including
`belongsToMany` relations declared with a `pivot: {table,
foreign_pivot_key, related_pivot_key}` block — which derives
namespaces from each slice's actual directory path and falls back to a
filesystem scan so slices shadowed by a duplicate name still resolve.

**Tab curation.** Declared tabs order and label the strip, but the
inferred sources (root-model `hasMany`, repeaters) append everything
they find — curation needs suppression as well as ordering. Declare
`ui.show.exclude_tabs` (a list of relation names or tab handles) to
suppress inferred, declared, or repeater-derived tabs alike; matching
is by relation OR effective handle, so excluding a handle kills only
that presentation while excluding a relation kills all of them. A
declared tab with `hidden: true` hides itself AND its inferred twin.
The fields tab renders its DECLARED label (e.g. `Profile`); the
generic `Overview` is only the fallback when no label is declared.
A declared tab with `type: audit` (handle `activity`) renders the
record's audit trail from the framework's audit log (actor, action,
field diffs, timestamps), or a proper empty state when nothing has
been recorded — it never silently vanishes from the strip.

Hub headers can also declare **stats** in YAML (`ui.show.stats`):
each entry names a `relation`, an `aggregate` (`count`, or `sum`/`avg`
with a `column`), an optional `filter` map (equality or `whereIn` over
the related table's columns), and a `label`. The base controller
computes them via `DirectoryUi::showStats()` and the show template
renders them as the KPI strip in place of the generic
record/status/created cards — e.g. an employee hub's Open Tasks
(filtered by status), Projects, Hours Logged (sum of time-log hours),
Tickets.

Formatter inference is shared: `FieldInference` holds the name/type
signal rules (status → status_pill, money-named → money_breakdown —
never type-driven, so geo decimals stay plain; due dates →
date_overdue; progress → progress_bar; codes → code_badge; assignees
→ composite_user). The porter AND the generator's default directory
both use it: a slice generated without a declared `ui.directory`
still gets inferred columns and status/priority quick filters —
but NEVER inferred summary cards (fidelity rule, QA6 25 Oct
2026: the generator and porter used to invent money-sum cards
and a "Total Records" fallback card; the original lists do not
show them, so they were pure deltas — 90 demo manifests carried
them). Summary cards exist only when declared from the source
page's anatomy. Declared columns always win.

**Directory landing order.** A directory states its order or
inherits a wrong one: legacy timestamps tie constantly, so a
created_at sort silently reads oldest-first. The contract is
`ui.directory.default_sort: {field, direction}` — an explicit
request sort always wins, then the declaration, then the
runtime fallback newest-first (id desc). Derivation declares
it when the source grid's first data column is a plain scalar
DB column (a code, a date), descending — the source grids
land on that column; badge/FK first columns (an employee-id
badge, a client column) take the fallback instead.

Declared filters APPLY: the web controller builds filter criteria
only from handles the manifest declares (`ui.directory.quick_filters`
+ `sidebar_filters`, including `<handle>_from`/`<handle>_to` inputs
of `date_range` filters) and the base service applies them to the
list query (equality / whereIn / ranges; a declared column the table
lacks is skipped, never a 500). The generated index renders the
sidebar filters as a GET panel with resolved select options
(`distinct:` values or `relation:` labels). UI config resolution is
own-manifest-first — route-prefix/name lookups mis-resolve when
slice names collide.

Overlay presentation is URL-bound (F7): declare
`ui.presentation: { create: page|drawer|modal, edit: ..., show: ... }`
and list-page actions load the screen's CANONICAL route URL as a
fragment into a drawer/modal shell — the same URL still works
standalone as a full page. The base controller answers overlay
fetches (header `X-LaraSlice-Overlay`) with the fragment layout;
generated form/show blades use `@extends($overlayLayout ?? ...)`.
Never put Blade `@if` inside a component tag in table slots (it
breaks component rendering there) — emit ternary-valued props
instead (empty string = off).

View namespaces (G6): every slice registers its name-variant
namespaces PLUS a unique path-derived one (`ls_` + path relative to
the slices base, acronyms kept whole). Same-named slices share the
plain namespace first-match-wins, so `BaseSliceWebController`
resolves index/form/show through `ownViewName()` — when the shared
namespace's winning hint is not the controller's own slice
directory, it uses the slice's unique namespace. A slice always
renders its OWN templates; route NAMES are still shared (duplicate
names resolve to one URI — path-qualified route names are the
remaining G6 step).

Route names (G6 step 2): nested slices get PATH-QUALIFIED route
names — the generator derives the name prefix from the URI hierarchy
(erp/erp/work/tasks -> erp_erp_work_tasks.*) across the routes file,
the controller's getRoutePrefix() and every blade route() reference;
root slices keep plain names. Existing installs repair with
`slice:qualify-routes [SliceName] [--dry-run]` (filesystem scan —
SliceManager's registry is name-keyed, so same-named slices hide
each other there; the command deliberately bypasses it and rewrites
only inside each slice's own directory).

Manifest resolution under twins (QA5): `DirectoryUi` resolves a
page's declarations by the ROUTE OWNER first — when a same-named
slice's routes file owns the current request path, its manifest
wins; only non-twin lookups use the name cache. Without this,
twinned pages render the first-discovered slice's columns (and
every relation the wrong model lacks renders as an em dash).
Porter type rule: `tinyint(1)` is boolean; wider tinyints are
integers (a tinyint(4) completion percent typed boolean rendered
every progress bar as "1%"). Detail headers use
`DirectoryUi::recordLabel()` — title/name chain, then any
`{thing}_name` attribute, then document numbers — never the raw
five-field chain that titled records "Slice #id".

Show-page widgets (25 Oct 2026): `ui.show.widgets` gain
`placement: rail` (stacked cards at the top of the show
page's right sidebar; default `main` = the overview grid)
and two types beside gauge/chart — `stats` (declared
measures: a record field, a relation aggregate, or the
difference of two aggregates, with prefix/suffix/decimals)
and `list` (relation rows with label/meta paths). Gauges
also accept a record-field value (`value: {field: …}`),
not only relation aggregates. Repair rule (ak) splits
existing show blades into the main grid + rail loops.

Dashboard widgets (25 Oct 2026): three personal widget
types join counter/list/week/chart/today/clock —
`people_dates` (upcoming recurrences of a date field:
birthdays, joining anniversaries; always renders, honest
empty state), `profile` (the signed-in user's own record
as a card: name/subtitle paths + declared label/value
lines; one unresolvable line path drops the line, never
the card), and `schedule` (the user's Mon–Sun week as
named cells, e.g. shift names). Lists and today-widgets
honor declared `filter:`; counters accept `suffix:` and a
`range: {field, period: this_week}`. The dashboard
aggregates widgets from EVERY discovered slice — twin
slices that share a name both contribute — and a slice's
model resolves declared-root-first with a path-derived
namespace, so a name-shadowed twin's model is its own.

**Widget scoping (portal parity).** Every widget honors
`scope: own` plus an `own:` clause — `{relation: …, key:
id}` or `{field: …}` — narrowing its query to the
signed-in user; a widget declared without it counts
globally. On a portal-composition dashboard, counters
over person-owned domains MUST declare `scope: own` with
the slice's own-scope clause inline on the widget (tasks
via their assignees relation, projects via `members`,
deals via `agent_id`, leaves/tickets via their user
field) — the source's portal numbers are the viewer's
own, so an unscoped counter there is a parity defect,
not a default. A viewer with no own records honestly
sees 0; global views live in the directories.

Inline-edit cells (25 Oct 2026): a column declared in
`ui.directory.inline_edit` gets ONE editor in its cell — the
plain select of the field's declared options posting to the
slice's inline endpoint. The progress bar declared via
`progress_field` rides on its own row key (`<key>__progress`)
and renders ABOVE the editor, so hiding the pill never hides
the bar; the workflow transition select does not render
inside an inline-edit cell (transitions live in the bulk bar
and detail views). A filter facet is never an editable
value: option-criteria facets (Overdue, buckets) belong to
filters only — the inline value domain is the FIELD's
declared options.

Filter criteria vocabulary (25 Oct 2026): a declared filter
OPTION may carry `criteria` — field => {op, value} conditions
(ops eq/neq/lt/lte/gt/gte/between/in/not_in/like; value
tokens $today/$now/$user) that REPLACE the plain handle
equality when that option is chosen, applied by the base
service as one AND group (`__conditions`). This declares
computed facets without code: the source's Overdue status
(deadline < $today, status not in finished/canceled) and its
progress buckets (between bounds). A date_range sidebar
filter with `placement: strip`, `columns` +
`column_options` + `column_param: date_filter_on` +
`default_column` renders the source's strip range with its
applies-to select; the drawer skips strip-placed filters.
The first-run empty panel renders only when NO filter is
active — a list filtered to zero keeps its chrome. The
panel's own button row still carries the declared directory
actions and the Import button (generator + repair rule
(al)) — an empty slice must not lose its context actions.

List furniture standards (QA6, 25 Oct 2026 — element-level diff
against the original, not just structure): in the ERP
composition the data-table renders ONE row above the table —
page actions (belowToolbar slot) on the left, view switcher +
columns menu on the right — never a separate right-aligned
controls row stacked over the actions band (owner-caught: the
stack cost ~72px of dead space and split what the original
keeps in one row). The matrix template's row header is
declared per slice (`ui.directory.matrix.row_label`, default
'Employee'). Two dead-space traps are healed by repair and
must not be reintroduced: the legacy Compact Top Bar wrapper
rendered an empty bordered sliver when the actions placement
was not header-right (its @if sat inside the div — rule (ah)
moves the @if outside), and the index section wrapper's
space-y rhythm landed on the zero-height chrome (bulk bar,
drawer) when no KPI cards render (rule (ai) + the generator
apply the spacing class only when cards exist). List dates render in
the source install's company format `d-m-Y` (datetimes
`d-m-Y, h:i A`); progress thresholds are <50 red / 50–89 amber /
≥90 green (read off the original list, superseding the earlier
75/40 guess); a column may declare `progress_field: <field>` —
the field's percentage renders as the source-style bar
(percent centered on the track) ABOVE the cell's own content,
which is how the original composes progress over the status
control instead of giving progress its own column; the
composite_user subtitle chain reads company_name/client_company
before email, so a client cell shows name + company like the
original.

Document views (G2): declare
`ui.show.document: { title:, line_items: <relation|repeater>, totals: [fields] }` — add `primary: true` when the record's page IS the document (the source products' invoice behaviour): show renders the document in the app shell with Back/Edit/Print-view actions, and the `/document` route keeps the print layout.
and the slice gains `{index-uri}/{id}/document` — SliceManager
registers the route centrally (no routes-file edit), the base
controller serves `DirectoryUi::documentFromManifest()`, and the
package's standard print template renders header fields, the line
items table and the totals block. A slice overrides the template
with its own `Resources/views/document.blade.php`. Generated show
pages link to it via the controller's `documentUrl`.

Matrix views (G2): declare
`ui.directory.matrix: { row_key:, date_field:, cell_field?:, marker?:, title?: }`
and the slice gains `{index-uri}/matrix?month=&year=` — a rows x
days-of-month grid with per-row presence counts, served by
`DirectoryUi::matrixFromManifest()` through the standard package
template (slice-overridable via `Resources/views/matrix.blade.php`).
Declared routes (document + matrix) are registered by SliceManager
BEFORE the slice's own resource routes, or the {id} show route
swallows the /matrix segment.
Roster population (QA8, 26 Oct 2026): with `roster: true` the
grid lists the row-key label map — when that map is broader
than the surface's population (a users map includes clients
and admins), declare
`matrix.roster_population: { table:, key: }` and the roster
intersects the map with that table's key values (attendances:
`employee_details.user_id` = exactly the employees). An
unscoped roster silently lists every account.
Taxonomy badges (QA8): a column whose label is a taxonomy
record carrying its own color (leave types, priorities)
declares `formatter: badge` + `color_from: <relation>.<color
column>` — the cell renders a solid pill in the record's
color; plain text for a colored taxonomy is a defect.
Filter option shapes (QA8): quick/sidebar filter `options`
may be a `{value: label}` map OR a list of `{value, label}`
pairs; the runtime normalizes both before rendering (a map
reaching the strip unnormalized rendered identical 'All'
tabs).
Relation-multi form fields (QA8-fix, 26 Oct 2026): a form
section field with `type: relation_multi` (handle = the
model's BelongsToMany method, `relation:` overrides it)
renders a multi-select fed by controller-resolved
`{handle}Options` (from the root model's own relation); the
form BO carries an int-id array under the handle, and the
base service SYNCs the relation on save (generated services
declare `relationMultiDefinitions()`; partial updates only
touch provided selections). This is how a task's Assigned
To is built — never a per-slice controller hack.
Labeled-lines cells (QA8-fix): formatter `lines` renders
stacked "Label: value" rows from the column's `lines:
[{label, from, case?}]` spec (values via data_get on the
row) — for meta-pair cells like a ticket's Others (Agent /
Priority) that are facts, not a person or a single value.
Columns built by `DirectoryUi::columnsForSlice` pass the
formatter keys through (badge_from, lines, color_from,
relation, breakdown…); a formatter key missing from that
pass-through renders an honest em dash, not an error.
Bar-comparison show widgets (QA8-fix): `ui.show.widgets`
type `bars` — each bar is a stats measure (field /
relation aggregate / minus, optional `scale:` divisor)
with its own `color:`; widths scale to the largest bar and
an unset field reads as zero. This is the Planned-vs-Actual
shape (project Hours Logged, Project Budget); rendered by
`x-ui.bars` in main and rail placements.
Matrix composition (25 Oct 2026): the slice's declared sidebar
filters fuse with the month/year period into the page's GET bar
(criteria MERGE with the period bounds — replacing them silently
unfiltered the grid), and the declared bulk-entry + import
actions render in their own row. `roster: true` renders every
member of the row-key label map (elapsed days read absent,
future days dash, Total column `present / days-in-month`) and
the declared filters then scope the ROSTER itself (the row-key
filter picks its member; `relation_exists` filters resolve
through the model's own relation — BelongsTo owner key /
HasOne foreign key). `legend: [{icon:, label:, class?:}]`
renders the Note row above the grid.

Report views (G2): declare
`ui.directory.report: { group_by:, aggregates: [{fn: count|sum|avg, column?, label?}], date_field?:, title?: }`
and the slice gains `{index-uri}/report` — grouped rows with
computed aggregates and a totals row via
`DirectoryUi::reportFromManifest()` and the standard package report
template (slice-overridable). With `date_field`, `?month=&year=`
scopes the period. Declared-route registration reads the controller
class from the routes file's own `use` statement — never derive it
from the slice namespace (segment casing breaks PSR-4 lookups).

Named + comparison reports (25 Oct 2026): a slice may declare
ADDITIONAL reports under `ui.directory.reports[]`, each
`{handle:, title:, ...}` — served at `{index-uri}/report/{handle}`
(route name `{prefix}.report.{handle}`). A report (primary or
named) may declare `compare:` INSTEAD of group_by/aggregates:
`compare: [{label:, table:, date_field:, amount:, sign:}]` — the
multi-source money-stream shape (Income vs Expense): each source's
rows are summed per month, columns align by month with a signed
Net column, and the page opens with Total/Net cards, computed by
`DirectoryUi::compareFromManifest()`. Compare sources are read
from their tables directly (no per-slice service scoping); the
host slice's authorization gates the page. Pilot: the demo's
Payments slice hosts its grouped Finance Report at /report and
Income vs Expense at /report/income-expense (values reconciled
against the tables).

Show-stat filters (F3c extension): a stat's `filter:` values may be
`!`-prefixed to EXCLUDE (whereNotIn) — for definitions inclusion
cannot express, e.g. open tasks = `board_column_id: ['!<complete-id>']`
(the source application's own User::openTasks definition).

Conversation surface (25 Oct 2026): a slice may declare
`ui.conversation` — the two-pane 1:1 chat shape (the source app's
Messages page) that no directory pattern expresses. The slice's
model is the conversation: fields `user_one` / `user_two` plus a
`replies` has-many whose rows carry `reply` + `user_id`
(relation names are conventional; override with `one:` / `two:` /
`replies:` in the declaration; `title:` names the page).
SliceManager registers `{prefix}/chat` (GET page, POST start,
POST `{id}/reply`); BaseSliceWebController serves the standard
blade (`laraslice::directory.conversation`, slice-overridable):
contact search + conversation list (partner, relative time,
last-reply preview), thread with own/other bubbles, composer,
and a New Conversation modal over the app's users. Server-
rendered throughout (selection is `?c=<id>`; nothing is
auto-selected — the empty state shows, as in the source); no
realtime transport is faked, and there is no read-tracking —
the source schema carries no read flag either.

### C. Multi-Slice Domain Scaffolding via CLI & UI
Developers and AI agents can scaffold entire domain suites in one command:

```bash
# Scaffold an entire CRM suite under the CRM domain
php artisan slice:make Companies Contacts Deals --domain=CRM

# Scaffold an entire E-Commerce suite with Flutter clients
php artisan slice:make Products Orders Customers Categories --domain=E-Commerce --flutter
```

In the **Visual Studio** (`/laraslice/wizard`):
- Click **CRM Suite (3 Slices)**, **E-Commerce Suite (4 Slices)**, or **Billing Suite (3 Slices)**.
- Or enter comma-separated slice names: `Companies, Contacts, Deals` with Domain `CRM`.

### D. Domain Accordion & Lifecycle Controls (Slice Studio)

The Installed Slices panel in Slice Studio (`/laraslice/wizard`) groups slices into **collapsible domain accordions** with full lifecycle controls:

1. **Domain Accordion UI**:
   - Slices are grouped by their `domain` or `navigation.group` from `slice.yaml`.
   - Each domain card shows: slice count badge, total models badge, and domain status (`Active`, `Disabled`, `Partial`).
   - The accordion header toggles collapse/expand and supports a search/filter input above.

2. **Domain-Level Lifecycle Actions** (on the accordion header row):
   - **⚡ Seed Domain Data**: Seeds demo data into all slice tables in the domain.
   - **👁️ Toggle Domain Active**: Enable/Disable all slices in a domain (hides from navigation sidebar).
   - **🧹 Wipe Domain Data**: Truncates all tables in the domain.
   - **🗑️ Remove Domain**: Destroys all slices in the domain (with mode selection).

3. **Slice-Level Lifecycle Actions** (per-slice dropdown menu):
   - **⚡ Seed Demo Data**: Seeds realistic relational data for the individual slice.
   - **👁️ Toggle Active**: Enable/Disable the slice (hide/show in navigation).
   - **🧹 Wipe Table Data**: Truncate all records from the slice's tables.
   - **🗑️ Delete Slice**: Destroy the slice with confirmation modal.

4. **Seeding Engine (`SliceSeederService`)**:
   - Resolves relational dependency ordering across slice tables.
   - Type-aware value generators: inspects column types (`Schema::getColumnType`) and generates integers, floats, dates, booleans, and text appropriately.
   - Foreign key safe disable/re-enable via `SET FOREIGN_KEY_CHECKS = 0` with `try...finally`.

---

