# Mind · Body · Heart — the three files of a Slice system

> **Porting:** trilogy files describe the system; **completion** is measured separately — [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md), [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md), status template [templates/REPLICA-STATUS.md](templates/REPLICA-STATUS.md).

Every Slice system is described by three coordinated files. Only
one of them runs.

| File | Scope | Question it answers | Runtime? |
| --- | --- | --- | --- |
| **Mind** — `system.yaml` | one per system | WHY does this system exist, WHO uses it, and HOW does work flow through it? | No — intent |
| **Body** — `porting/ui-spec/<slice>.ui.yaml` | one per slice | WHAT does each page look like and do? | No — evidence + decisions |
| **Heart** — `slice.yaml` | one per slice | WHAT runs? (data, fields, relations, UI declarations, workflows, settings) | **Yes — the only runtime truth** |

A non-technical founder can read the Mind and understand the whole
system. A developer (or an AI) reads Mind → Body → Heart and can
rebuild or extend the system on any Slice runtime (LaraSlice today;
the Mind and Body are language-agnostic by construction, and the
Heart declares intent each runtime maps to its own stack and
design system).

**The Soul is not a file.** It is the experience identity — the
chosen design system, density, and refinement layer (JEV) —
declared once in the Mind (`design:`) and realized by the runtime's
component mapping.

## The Mind (`system.yaml`) — schema

```yaml
system: {name, purpose, owner}
design: {system: blatui-tailwind, density: compact, refinement: jev}
actors:            # every kind of user, human or external
  - {name: Employee, kind: internal, description: "..."}
roles:             # permission CLUBS + the override model
  model: role-clubs-with-user-overrides
  clubs:
    - {name: Admin, permissions: [tasks.*, leaves.approve]}
  overrides: per-user grants/revocations on top of the club
journeys:          # the work, end to end, in plain language
  - {name: Apply for leave, actor: Employee, steps: [...], outcome: "..."}
navigation:        # the menu tree users actually see
  - {label: Work, children: [Projects, Tasks, Time Logs]}
routes: {style: resource, prefixing: module}
flows:             # internal + external, incl. approvals and timers
  - {name: Leave approval, kind: internal, summary: "..."}
integrations:      # APIs in/out, webhooks, mail, storage
  - {name: SMTP mail, kind: outbound, purpose: notifications}
templates:         # email + print inventory and who owns them
  - {name: Leave approved email, kind: email, slice: Leaves}
storage: {uploads: local-disk, policy: "..."}
pipelines:         # import / export / zip / sync, declared not coded ad hoc
  - {name: Tasks export, kind: export, format: csv}
operations:        # the Operate quadrant, declared
  backups: {schedule: daily, retention_days: 30, includes: [db, uploads]}
  sync: {files: none, db: none}
  audit: {enabled: true}
```

Rules:

1. The Mind **describes and decides**; it never executes and never
   holds code or JS logic. Behavior expectations are written as
   plain-language outcomes ("a second timer start is refused").
2. Roles are **clubs of permissions**; individual people may hold
   **overrides** (grants or revocations) on top of their club —
   both layers are declared in the Mind, the slices declare the
   permissions they need in their Hearts, and the runtime enforces
   the union.
3. The Mind and the Body are **checked against the Heart**, never
   the other way round: extraction generates their drafts when
   porting, audits keep them honest, and drift is reported — the
   Heart wins every conflict until a human edits it deliberately.
4. For a new project the order reverses: Mind first (from an idea
   or a BRD), Body derived from the Mind, Hearts generated from
   both. Porting and planning are the same three files at
   different moments of a system's life.

## Record actions (declared in the Heart)

A slice's Heart may declare record-level workflow actions
beyond CRUD under `ui.actions` — the L3 pattern:

```yaml
ui:
  actions:
    - {name: duplicate, label: Duplicate, type: duplicate}
    - {name: archive, label: Archive, type: transition,
       field: status, value: archived, confirm: "Archive?"}
```

Two executor types ship: `duplicate` (copies the record and
its repeater children; the first label column gains a
" (copy)" suffix; gated on the slice's create permission)
and `transition` (sets one declared column to one declared
value; gated on update). The directory payload carries the
actions per row (`RecordActions::urlsFor`), the row menu
renders them, and one framework endpoint
(`POST /laraslice/record-actions/{prefix}/{id}/{action}`)
executes them — slices adopt actions by declaration alone,
no route regeneration. Undeclared types and undeclared
fields are refused by construction.

## Global chrome (platform, declaration-driven)

The app frame's global items derive from the app itself,
never from hardcoded slice names:

- **Quick Create** (`Support\QuickCreate`): slices that
  are nav-visible, have a create route and grant the user
  create permission; labels honour `ui.directory.create_label`.
- **Global timer** (`Support\GlobalTimer`): the user's
  running P11 timer, whichever slice owns it, with a
  one-click Stop on that slice's own endpoint.
- **Invite member** (`Support\InviteTarget`): the user
  menu gains the item when any slice declares a P14
  invite (`ui.invite`); it links to that slice's invite
  page.
- **Setup Progress** (`Support\SetupProgress`): an
  admin topbar widget (label, n/total, thin bar) shown
  only while setup is incomplete, linking to a
  `/checklist` page. The six steps probe the app's own
  settings data: installation, an existing user account,
  the SMTP from-address off its install placeholder,
  `global_settings.last_cron_run`, `logo`, `favicon`.

## Settings sections (declared in the Heart)

A slice's Heart may carry a top-level `settings:` list — the
P9 settings pattern. The framework aggregates every active
slice's sections into ONE hub at `/admin/settings` (rail +
search, one form card per section, per-section save):

```yaml
settings:
  - handle: smtp
    title: 'Email (SMTP)'
    icon: mail
    bind: { table: smtp_settings }   # optional — see below
    fields:
      - { handle: mail_host, label: 'Mail Host', type: text }
      - { handle: mail_password, label: 'Mail Password', type: secret }
```

- **Unbound** sections persist through the core key-value
  Setting store, keyed `{section}.{field}` unless a field
  declares its own `key:`.
- **Bound** sections (`bind.table`) treat fields as COLUMNS
  of a single-row settings table: values read from the
  row (created on first save when the table is empty) and
  saves write only declared fields / existing columns.
  This is how ported systems surface their settings — the
  source's own `*_settings` tables become hub forms, not
  CRUD directories. Toggle values normalize on read and
  are written back in the row's existing convention.
- **Keyed-rows bound** sections (`bind.table` +
  `bind.rows_key`) edit a MULTI-row settings table as one
  row per record with declared toggle columns
  (`bind.toggles`, row label from `bind.rows_label`) —
  the per-event notification-settings shape. Saves
  rewrite each row's toggles in that row's own convention.
  ('yes'/'no' or 1/0). A section whose table is not
  installed is omitted from the hub.
- Field types: text, number, textarea, select (options),
  toggle, secret (blank saves keep the current value).
- Multi-row tables (per-module, per-language rows) are
  catalog data, not settings sections — they stay
  directories.

## The Body (`porting/ui-spec/<slice>.ui.yaml`) — schema v2

One Body per slice. It is the reviewable evidence of what each
page of the slice looks like and does, and what each club may do
with it. Bodies are written in the source system's words (its UI
nouns, its labels), never in storage words. Every item carries a
`decision` (`undecided` at extraction, then `keep`, `drop`,
`rename`, or `add`); audits check the Heart and the running app
against the decided Body.

```yaml
slice: leaves
labels: {singular: Leave, plural: Leaves}   # the UI's own nouns
pages:
  list:
    path_source: /account/leaves
    path_port: /erp/erp/hr/leaves
    toolbar: [{item: 'New Leave', decision: keep}]
    filters: [{item: status, decision: keep, placement: drawer}]
    columns:
      - {item: Employee, decision: keep, value: user.name}  # value = what must render non-empty
  form:                       # the create/edit surface — v2 addition
    presentation: drawer      # page | drawer | modal (create), page | drawer | modal (edit)
    sections:
      - {title: Leave, fields: [user_id, leave_type_id, leave_date, duration, reason]}
    fields:
      user_id:        {input: picker, relation: users, label: 'Choose Employee'}
      leave_type_id:  {input: select, relation: leave_types}
      leave_date:     {input: date}                 # date even if storage is datetime
      duration:       {input: select, options: [full, first_half, second_half]}
      reason:         {input: textarea, required: false}
      status:         {input: workflow-state, default: workflow-initial, editable: false}
    system_fields: [added_by, last_updated_by, approved_by, approved_at, event_id]
      # storage/audit columns that exist in the Heart but must
      # never render on a form; generation hides them by default
  detail:
    composition: [header-counters, tabs]
    tabs: [Profile, Activity]
access:                       # per club — checked against the Hearts + roles
  Super Admin:  {actions: [view, create, edit, delete, approve], scope: all}
  Admin:        {actions: [view, create, edit, approve], scope: all}
  Employee:     {actions: [view, create], scope: own}   # own = records whose user_id is the viewer
workflow: {state_field: status, initial: pending, on_create: apply-initial}
```

Rules:

1. **A Body is incomplete without `pages.form` and `access`.**
   A list-only Body is a draft, and audits must say so.
2. **System fields are declared, then hidden.** Any column the
   user should never type (`*_by` audit stamps, `approved_*`,
   `event_id`, timestamps) is listed under `system_fields`;
   form generation hides them by default, and the form audit
   fails if one renders.
3. **Defaults have sources.** A field's default is one of
   `workflow-initial`, a literal, `current-user`, or
   `today` — never an accident of an empty input. A workflow
   slice's state field defaults to the workflow's initial
   state, applied by the server on create.
4. **Relations are pickers.** A field whose value is another
   record declares `input: picker` (or `select`) with its
   `relation`; rendering a raw numeric ID input for a person
   or a named record is a defect the form audit flags.
5. **Access is part of the surface.** `scope: own` means the
   runtime filters the list (and guards detail routes) to the
   viewer's own records for that club. The drift audit
   compares `access` against the Hearts' declared permissions
   and the roles' actual grants — a club the Mind promises
   access and no role grants is drift, reported before release.
   What "own" means is the Heart's declaration:
   `access.own_scope` is a list of clauses, each
   `{field: <column>}` or `{relation: <name>, key: <related
   column, default id>}`, resolved against the viewer — a
   record is theirs when ANY clause matches. One clause
   covers the common case (leaves are the viewer's by
   `user_id`, which is also the underived default); several
   cover the real shapes of shared records: a project is its
   client's by `client_id` and its members' by the `members`
   relation; an invoice is its client's via
   `{relation: project, key: client_id}`. The list narrows
   through one grouped OR in the base service; detail routes
   evaluate the same clauses against the loaded record.
6. **Values are auditable.** A list column may declare
   `value:` (the relation path that must render). An audit
   that finds the column rendering empty for every row has
   found a defect, even when the header matches.

## Where each surface reads them

- **Dev Studio** — Plan mode reads/writes the Mind; Design mode
  works the Body; Create/Manage modes work the Hearts. The
  Operate console reads the Mind's `operations:` plus live
  runtime state (health, backups, sync, audit, security).
- **CLI** — `slice:port` extracts draft Mind + Bodies from a
  source system; the delta audit verifies Bodies against both
  applications; `slice:links` checks the Hearts. `slice:drift`
  audits the Mind's club promises and the Bodies' access
  declarations against the roles' actual grants (zero errors is
  a release gate); `slice:drift --fix` reconciles them —
  granting mapped roles (`maps_to`) what their clubs promise,
  creating `view-own` permission rows when missing, and never
  widening a view-own promise into a full view. Per-person
  overrides live in the `user_permissions` table (grant/revoke)
  and are enforced by the runtime's access layer on top of the
  club grants; a revoke beats the club grant.
- **Manuals and the AI API are generated outputs** — user manuals
  from the Mind's journeys + the Bodies; AI access is the CLI/MCP
  surface over the same three files. Never hand-maintain them.

## Plan mode (Studio)

The Studio's fifth mode, **Plan**, is where the Mind is read and
edited: actors, journeys, operations, and role overrides render
as plain-language cards, and purpose / role clubs / journeys /
operations edit as simple line-based forms. Saving is
diff-before-save (preview renders a unified diff of the YAML;
confirming writes `porting/system.yaml`). Sections the form
does not touch are preserved, except YAML comments — a Plan
save re-renders the file and drops comments, so keep prose in
values, not comments. `php artisan slice:plan:draft` drafts a
Mind plus a Body per slice from the discovered slices (R6);
drafts are marked [draft] and existing files are never
overwritten.

## Operate mode (Studio)

The sixth mode reports the running system against the Mind's
`operations:` declarations: health checks (database, slices,
storage, Mind presence, audit trail, runtime), backups — the
declared policy is shown and "Run backup now" produces real
artifacts honoring the declared includes — the recent audit
trail, and an access review (R5) listing every user's roles
with flags on system-wide roles, multiple roles, and missing
roles. "Generate manual" (R4) downloads an operator manual
assembled from the Mind and the slice inventory. Operate
never changes runtime behavior; it is the Maintain loop's
home (R3).

## Body schema v3 — the list contract, the tab content contract, and the kit seam

Schema v2 made forms and access declarable. The 23 Oct visual-parity
work exposed the next layer: v2 said nothing enforceable about how a
LIST is composed or what a detail TAB must do, so generators shipped
comfortable-density grids, stacked chrome rows, dead Export buttons
and read-only tab tables — while the framework already owned density
modes, inline editors, filter slots and an export event that no
declaration ever switched on. v3 closes that gap. Its governing
principle:

**Generation is reconciliation, not scaffolding.** The three files
are the runtime truth; generated blades are a cache of them.
`slice:repair` continuously reconciles existing blades to the
current contracts (rules (r)–(x)), so a contract improvement lands
on every existing install — a standard that only new slices obey is
not a standard.

### pages.list (v3)

- `density: compact | comfortable` — the Heart's
  `ui.directory.density`, default **compact** (the source systems'
  ~49px row). Density governs EVERY cell of the grid, including the
  selection and actions cells — one comfortable cell silently sets
  the whole row's height.
- **One actions row.** Page-level actions (create, import, seed)
  and declared directory actions (`ui.directory.actions`, e.g.
  Invite) render in a single top-bar cluster. A declared action on
  its own row is a contract violation.
- **One toolbar row.** Quick filters, search, the Filters trigger
  and column controls compose the data table's own toolbar (the
  `quick-filters` slot) — never stacked page rows above it.
- `columns[].formatter` is the cell contract: `composite_user`,
  `user_stack`, `status_pill`, `progress_bar`, `code_badge`,
  `date_overdue`, `timer`, … A column the source shows (e.g.
  progress) that the Heart omits is a fidelity defect in the
  Heart, and a listing BO that does not declare the property
  silently drops its values.
- `inline_edit: [field, …]` — the field becomes an editor in the
  grid (status select), posting to the slice's inline endpoint.
  The endpoint enforces exactly this allowlist plus the field's
  declared `options`. Option keys in YAML flow mappings MUST be
  quoted when multi-word (`{'in progress': 'In Progress'}`) — an
  unquoted key parses truncated and corrupts the value domain.
- Every rendered control must be wired: an Export button dispatches
  the table's export event; a button that does nothing is a defect
  of the same class as a missing column.

### pages.detail.tabs[].content (v3) — the tab content contract

A relation tab is **a working list of the related slice**, rendered
from that slice's own list definition (v2/H1) PLUS its action
surface, all derived from the related slice's Heart at runtime
(`DirectoryUi::tabActionSurface()`):

- `toolbar`: scoped **Add** (the related slice's create route; the
  relation's foreign key preset for has-many relations) and
  **Export** of the shown rows.
- `row_actions`: view / edit / delete against the related slice's
  own routes, per row.
- `inline`: the related slice's declared `inline_edit` field
  renders its editor inside the tab (the same endpoint and
  allowlist as its directory).
- Pagination with the source's footer shape ("Showing X to Y of Z
  entries") at the contract's page size.

A tab that renders rows without the related slice's actions is a
picture of a list, not a port of one.

### The kit seam (design-system independence)

The three files declare **semantics, never styling**: page kinds,
column formatters, density, actions, editors. Rendering maps those
semantics through the active UI kit's components (`x-ui.data-table`,
`x-ui.filter-tabs`, … in the default BlatUI kit). A future kit
(Material, Ant Design, a house kit) re-implements the component
layer against the same contracts — density, toolbar composition,
tab actions and inline editors are behavior contracts that every
kit must satisfy, verified by the same measurement gate (row
height, chrome height, control wiring) rather than by screenshot
resemblance. The backend foundation — Hearts, Bodies, services,
endpoints — does not change when the kit does.

## Body schema v4 — the Page Structure Contract (23 Oct 2026)

Why this exists: v2/v3 declared FIELDS, columns, filters and tab
content, but page COMPOSITION — which bands a page has, in what
order, with the primary action where — lived as hard-coded
generator template. When a source page composed differently, no
file could say so, and the drift was found by eye. v4 makes
composition declared data. Research basis (Carbon, Ant,
Atlassian, NN/g, Salesforce, Fluent; commissioned R&D,
`PAGE-STRUCTURE-PORTING-FIDELITY-PLAN-2026-10-23.md`): the
canonical anatomy is convergent across design systems, but
PRIMARY-ACTION PLACEMENT is genuinely contested (header-right /
command-bar-left / grid-toolbar) — so placement is declared per
page, never a framework default. Ports take every value from
the source; from-scratch pages take the defaults marked *.

### The region vocabulary (closed — pages may use only these)

LIST (`pages.list.regions`, ordered):
`breadcrumb · title · header_actions · facets · toolbar ·
actions · grid · pagination`
- `title`: page title (+ optional description). Nothing else
  may render in this region (no buttons, no badges, no seed
  affordances).
- `facets`: declared quick filters (tabs or selects, per
  facet) — the filter model of the page.
- `toolbar`: search + table-level controls (filters drawer
  trigger, columns menu when declared).
- `actions`: the page's action band — primary action first,
  then secondary actions, in declared order.
- `grid`: columns in declared order; first column is the
  human identifier; row actions inline when <3, overflow
  otherwise.
- `pagination`: bottom only, with total + page-size.

DETAIL (`pages.detail.regions`, ordered):
`breadcrumb · identity · highlights · sections · tabs ·
related · activity`
- `identity`: detail_title + identity subtitle (declared
  `detail_title` / `identity_fields`, v3 machinery).
- `highlights`: ≤7 key fields beside the identity.
- `sections`: grouped field rows (split-line separation).
- `tabs`: only for weakly-related large content; each tab's
  content is a v3 list reference.
- `related`: related lists as their own region/tab.
- `activity`: optional right rail.

#### Detail widgets (v4, Tier-5 P2) — the chart/panel vocabulary

Detail pages carry visual panels a field list cannot express
(a progress gauge, a status pie, a bar breakdown). Before
this vocabulary existed they had nowhere to be declared, so
ports shipped stat cards and filler where the source had
charts — invisible to every structural gate. A detail page
declares its widgets under `pages.detail.widgets`, in
render order:

```yaml
pages:
  detail:
    widgets:
      - {key: progress, type: gauge, title: 'Project Progress', shows: 'percent complete'}
      - {key: task_mix, type: pie, title: 'Tasks', shows: 'tasks by status'}
```

- `type` is one of: `gauge` (single percentage arc),
  `pie` / `donut` (part-to-whole), `bar` (labelled bars),
  `stat_row` (a band of counters — the highlights region
  when it is data, not identity fields), `stat` (one
  counter card), `list` (a short record list), `card`
  (a titled info panel), `calendar` (a month view).
- `key` is the panel's stable name; `title` is the
  source's own words; `shows` says in one phrase what the
  panel displays (the data binding is the kit's business;
  the declaration is the contract).
- Widgets are DECLARED data like everything else in Body:
  a kit renders the declared set; an undeclared chart on
  the source is a spec gap the porter must close, and a
  declared widget the kit cannot render is a kit gap —
  neither may pass silently (the appearance gate, SKILL
  §19B, owns the assertion).

#### Tab render modes (detail tabs)

A relation tab renders as a table by default. A tab may
declare a render mode — presentation only, never data:

- `render: table` (default): the related slice's list
  definition (columns + rows) in the tab.
- `render: board` + `group_by: <field>`: the relation's
  records grouped into columns by that field. The group
  label resolves by convention from the field's relation on
  the record (board_column_id → boardColumn → its
  title/column_name/name), ordered by the related model's
  priority when present.
- `render: gantt` + `start_field` / `end_field` /
  `label_field`: the records as bars over a shared date
  axis computed from the set's min/max. Records missing
  either date are counted in a footnote, never dropped
  silently.

A render-mode tab is an ADDITIONAL presentation of its
relation: dedupe keys plain tabs by relation, render-mode
tabs by their declared handle. Declarations carry field
names only — geometry, colours and CSS stay in the kit
(the board/gantt partials), per the v4 bans.

FORM (`pages.form.regions`, ordered):
`breadcrumb · title · sections · fieldsets · actions`
- `actions`: submit/cancel placement declared
  (`placement: footer-right*` | `header-right`).

### The placement property (required on list pages)

`pages.list.placement: header-right* | toolbar-left |
command-bar | below-facets-left`
Declares where the primary action renders. The generator may
not move it; the kit may not restyle it into another region.

### Density and pagination are names, never numbers

`pages.list.density: compact | comfortable*` — the kit owns
the pixels behind each name (compact ≈ 40–49px rows,
comfortable ≈ 48–56px). Ports declare the name nearest the
source's measured density. BANNED in all three files: pixel
values, colours, CSS classes, kit component names, inline
code. A declaration that needs one of those is a vocabulary
gap — extend the vocabulary, never smuggle style.

### The anti-drift rule (law, enforced by slice:drift + the
conformance gate)

The generator and every kit component may emit ONLY regions
and controls declared in the Body, in declared order and
placement. Kit-invented chrome (undeclared bands, badges,
buttons, cards) is a conformance failure, not a style choice.
Undeclared regions in a generated blade = drift error.

### JEV (decision recorded 23 Oct 2026)

The Body→kit rendering contract defined by this schema — the
synthesis of a page from declared regions through the active
kit's contract table — IS JEV, and P1 builds it as a real
layer (generator + kit contract), replacing the template
hardcoding. Until P1 lands, SKILL §17's synthesis description
is aspirational and marked as such; `JevDecisionService`
(Blueprint archetype/risk) is the only JEV code today, and it
is a decision helper, not the renderer.
