# Porting reference — UI contracts & parity gates

Sections 17, 19, 19B, 20, 21 of the porting skill: the declarative UI schema, composition parity, appearance parity (Tier-5 visual gate), List Contract v3, and the Page Structure Contract. Verbatim; the core SKILL.md routes here.

## 17. Declarative UI Schema & Internal UI Synthesis — Modular YAML Standards

### 17.1 The Catalog-Selection Paradigm (internal, rule-based)
Rather than having an LLM slowly generate raw HTML/CSS strings on the fly (which takes 20-30 seconds, creates hallucinations, causes CSS leaks, and breaks responsive design), LaraSlice enforces an **internal, rule-based component-selection paradigm**:
1. **Catalog Selection instead of Generation**: The AI engine (`JevDecisionService`) and code generator select exclusively from pre-designed, WAI-ARIA accessible **BlatUI components** (`<x-ui.*>`).
2. **"A Shape Nobody Anticipated Cannot Appear on Screen"**: Every table column, filter, form section card, and action button is picked from a deterministic set of typed primitives.
3. **Sub-Second Execution (70–500ms)**: Fast classification and schema composition guarantees real-time UI synthesis without latency.
4. **"Cage with a Door" Fallback**: High-confidence choices render the exact BlatUI widget immediately. If confidence drops below 50%, it falls back cleanly to an interactive prompt or standard fallback view.

> **Status note (24 Oct 2026 — read before citing this section).**
> What exists today: `JevDecisionService` performs catalog
> SELECTION for slice generation (archetype/risk decisions over
> the BlatUI catalog), and the kit contract swaps DESIGN TOKENS
> between kits (proven by the second-kit spike, docs/KIT-2-
> CONTRACT.md). What does NOT exist: a synthesis engine that
> renders Body declarations through a different kit's component
> MARKUP. A kit with different markup (e.g. a source-faithful
> replica kit) is Tier-5 work (see the Tier-5 improvement plan
> in the page-structure program), not a shipped capability.
> Until it lands, do not describe Jev as markup-level UI
> synthesis in any release, doc, or claim.

### 17.2 The Canonical `ui:` Schema in `slice.yaml`
Every `slice.yaml` declares its UI presentation, directory layout, filters, and form section structures:
```yaml
ui:
  presentation:
    create: drawer # 'drawer' | 'page'
    edit: drawer
  directory:
    columns:
      - handle: code
        label: Code
        formatter: code_badge # code_badge | composite_user | status_pill | money_breakdown | progress_bar | date_time | text
        sortable: true
      - handle: user_id
        label: Employee
        formatter: composite_user
        sortable: true
    quick_filters:
      - handle: department_id
        label: Department
        is_searchable: true
    sidebar_filters:
      - handle: status
        label: Status
  forms:
    sections:
      - key: primary_details
        title: Primary Identification & Details
        grid: 3 # 1 | 2 | 3 | 4 columns
        fields:
          - handle: user_id
            span: 1 # 1 (33%) | 2 (66%) | 3 (100%)
            is_searchable: true
          - handle: address
            span: 3
```

### 17.3 Instant Slide-Over Drawer with URL Deep-Linking
- When `presentation.create: drawer` is configured:
  - The index page renders an `<x-ui.sheet id="{slice}-create">` drawer containing the form.
  - The "+ Add" button triggers `@click.prevent="$dispatch('open-sheet-{slice}-create')"` without full page reload.
  - Deep-link synchronization pushes `?drawer=create` to the browser history (`window.history.pushState`).
  - Reloading or directly visiting `?drawer=create` automatically opens the slide-over drawer on page load.
  - A fallback link ("Open Full Page") is automatically provided for multi-tab workflows.

### 17.4 Modular YAML Validation & Synchronization
`slice.yaml` is designed for granular, sub-part synchronization:
- AI agents, CLI commands, and Studio tools can validate and edit either the entire document or isolated sub-parts (`ui`, `directory`, `forms`, `fsm`, `relations`, `models`).
- CLI Command: `php artisan slice:validate [SliceName|path] [--part=ui|forms|directory|fsm] [--fix]`.

### 17.5 Slice Studio UI Editors (v1.6.4+)

Slice Studio's Field Manager includes a full **Page & UI Architecture** editor — every `ui:` block section is editable in the browser without touching YAML:

- **Quick Filters** — tab-style filters with field picker, ▲▼ reorder (tab order = display order), label/handle editing, and options source (`distinct:field`)
- **Sidebar Filters** — date-range and dropdown filters with field picker and type selector
- **Summary Cards** — aggregate cards (count/sum/avg/min/max) with field picker and formatter
- **Row Actions** — View/Edit/Delete with ⋯Menu / Inline / Hybrid display toggle and per-action placement
- **Bulk Actions** — delete/export toggles
- **Create & Edit Form** — Drawer/Page presentation toggle, section editor with 1–4 column layouts

All changes flow into `slice.yaml` on generate. Blueprint Studio remains the reference implementation for the full declarative schema.

---

## 19. Composition Parity — Hub Tabs, Detail Composition & the Screenshot Gate (H standard)

**Scope note (24 Oct 2026).** This section governs COMPOSITION
parity: what a page contains and how it is arranged (tabs,
labels, sections, columns). It is not APPEARANCE parity — how
the page looks (colour, type, component shapes, form
presentation). Appearance is governed by §19B (Tier 5). A
"replica" claim needs both; §19 alone licenses "structural
replica" only.

Structural parity is not parity. A detail page whose tabs cannot name their
rows, or whose profile is a raw attribute dump, is not a replica — no matter
what the differ says about list-page headers. These are framework standards,
never per-slice patches:

- **H1 — Hub tabs render the RELATED slice's declared list definition.**
  `DirectoryUi::relatedTabsFromManifest()` resolves each relation tab to the
  slice that owns the related table (`SliceManifest::primaryTable()` +
  declared child models), takes its `ui.directory.columns`, fetches the rows
  through the relation, decorates them through that slice's own service
  (`BaseSliceService::listingBusinessObjects()` → its `mapToListing` + the
  query's eager loads), and renders every cell through `DataTableRows` — the
  same values as the related directory itself. The generic
  ID/Summary/Status skeleton survives only as the unresolved fallback
  (flagged `'unresolved'`); a slice whose columns do not describe the
  related table falls back to model-derived columns (child entities with no
  slice of their own) WITHOUT that slice's service decoration.
- **H2 — Label resolution is universal.** A record's display label resolves
  through the manifest/pipeline conventions everywhere (tabs, rails,
  selects). Rendering `#<id>` as content, or an empty date becoming
  "today" (`DataTableRows::formatDateValue` returns `—` for null/empty),
  is a defect class.
- **H3 — Detail composition is declared, not dumped.** The parameters card
  renders `DirectoryUi::detailRows()`: manifest fields minus the system set
  (`*_by`, approval stamps, tenant keys) — or, when the Heart declares
  `ui.show.detail_fields`, exactly those rows: `{label, from, type?}` where
  `from` is an attribute or dotted relation path (`employeeDetail.
  designation.name`, `user.email`), resolved failure-safely to `—`. A bare
  `from` the record does not carry falls back to its linked user
  (`user.<field>`) — profile tables (employee_details, client_details)
  store identity on the user row, and the source's blades read it through
  that link. The card
  title is `ui.show.detail_title` (`DirectoryUi::detailTitle()`); the header
  subtitle is `ui.show.identity_fields` (`DirectoryUi::identitySubtitle()`,
  values joined ` • `). The ownership card renders ONLY when the record
  carries `assigned_to_role`/`assigned_to_department` — placeholder
  role/department text is fabrication. Repair rules (r)/(s)/(t) in
  `slice:repair` keep existing blades at this standard.
- **H4 — Person/relation cells must resolve.** Person columns use the
  `composite_user` formatter (avatar + name + subtitle from
  designation_name/designation_id); relation columns (a person's User Role
  via `role_user` → `roles.name`) must be decorated by the slice service —
  an all-empty relation column fails the gate. Listing BOs must declare
  every directory column they serve (a missing BO property silently drops
  the column's values — cf. Projects `start_date`).
- **H5 — Tab completeness.** Hubs declare every source tab or a named
  equivalent (Shifts = shift-schedule relation tab). Gaps are listed in the
  parity plan, never silently absent.
- **H6 — Tab toolbars** (Add/Export/scoped search as the source has them)
  remain the open item of this standard; tabs ship with their content first.

**The visual gate (mandatory before any parity claim).** For each hub:
screenshot pairs (list, overview, EVERY tab) original vs port on the SAME
person/dataset, a per-page diff table, and tab-content assertions (rows
carry the related records' labels, never `#id` summaries). Capture tabs by
clicking the detail page's own tab strip and asserting the detail URL —
module list pages are not tabs. A claim is only as good as the surface it
was measured on.

### 19B. Appearance Parity — the Tier-5 Visual Gate (24 Oct 2026)

Adopted 24 Oct 2026 after the design-QA audit (computed styles
measured on matched pages: ~20 of ~31 attributes differed —
primary colour, sidebar scheme, font family, button geometry,
table-header voice, card model, pill style). Appearance parity
is a separate, measurable tier:

- **The spec is captured, not remembered.** `slice:port
  --anatomy` design capture records the source's computed
  design tokens (primary/sidebar/font/button/header/card/
  pill/search) and per-form presentation (drawer | page |
  modal) into `porting/ui-spec/source.design.yaml`. Porting
  without a design capture cannot claim Tier 5.
- **The gate is computed-style assertions**, per page, port vs
  the captured spec: EXACT for primary colour, font family,
  sidebar scheme/width; ±2px for geometry (button height,
  radius, row height per the declared density); form
  presentation must match the captured value. Failures enter
  the deviation register with the same mechanics as
  structural deviations (fix, or record + owner approval).
- **Rendering path.** The standard kit keeps its own identity
  and is NOT expected to pass this gate. Tier 5 is rendered
  by a replica kit (source-faithful component markup behind
  the kit contract) — P3 of the Tier-5 plan. Until a replica
  kit passes §19B on a page, that page is a structural
  replica, and every public surface must say so.
- **Release claim language.** Releases, changelogs, docs and
  landing pages name the tiers a claim covers (data /
  availability / structure / behaviour / appearance).
  "100% replica" requires all five, per page, with the
  register at zero unrecorded deviations.
- **Composition is asserted separately (25 Oct 2026).** The
  token gate measures computed styles; it cannot see REGION
  ORDER. The composition rules are part of appearance parity
  and are checked on the live page (the composition probe):
  (1) **The page title lives in the topbar on ERP pages** —
  title + `Home • X` breadcrumb at the topbar's left (the
  source composition); the in-page title row is retired on
  ERP slices (repair rule (aa), gated on the routes prefix
  erp/hr/crm) so exactly ONE title + breadcrumb exists per
  page; platform surfaces keep the shell breadcrumb and
  their in-page titles. The shell derives the title from the
  active navigation entry (the nav builder tries the slice's
  own declared route-name prefix first — path-named slices
  like `erp_erp_work_projects.*` otherwise never activate)
  with a URI-headline fallback. (2) **The filter bar is fused
  with the chrome on list pages** — facets + search + the
  Filters button form a full-width strip directly under the
  topbar; the data table pushes its bar into the layout's
  `filterbar` stack, and the Filters button is emitted into
  the quick-filters slot (generator + repair rule (y)), never
  the table-actions slot. Strip-placed declared filters (a
  date range with `placement: strip`) fuse into that SAME
  band via the bar's `filterbar-extra` stack (layout kit,
  26 Oct 2026) — a strip filter rendering as a second band
  inside the content is a composition defect, not a variant. The slot block's guard admits
  sidebar-only slices (`!empty($quickFilters) ||
  !empty($sidebarFilters)` — generator + repair rule (z);
  the narrow guard silently dropped the button on
  drawer-only slices). (3) **exactly one Filters opener,
  inside the strip** — the slot button dispatches
  `open-ls-filters`; the drawer's standalone trigger row
  yields to it, and a Filters button rendering anywhere else
  (the toolbar row) is a composition failure. A token pass
  with a composition failure is NOT appearance parity.
  (4) **Hub composition is declared, not templated per page**
  — `ui.show.composition: overview` (layout kit, 26 Oct 2026)
  switches a show page to the hub composition the source
  systems use: the record title renders in the topbar (the
  layout yields a page `page-title` section over its nav-label
  default), the tab strip fuses under the topbar via the
  filterbar stack and drives the in-page panels through an
  `ls-show-tab` window event (status + Edit + Delete pinned at
  the strip's right edge), the identity header card and the
  generic metadata strip are NOT rendered, declared widgets
  lead, and the field card follows as a plain 'Details' card
  (`ui.show.fields: end | hide`). The generator emits the
  branches in every show blade; standard composition remains
  the default. **Tab strips collapse, never scroll**: both
  ribbon variants carry `data-ls-ribbon` (tabs marked
  `data-ls-tab`, the right-edge actions `data-ls-ribbon-actions`)
  and the package partial `laraslice::partials.tab-ribbon-script`
  packs the tabs that do not fit into a **More ▾** dropdown —
  the source grids' behaviour; the active tab is never
  overflowed (it swaps into the visible set). The shell's
  content column (`sidebar-inset`) carries `min-w-0`: without
  it, any wide un-clipped child stretches the whole layout
  past the viewport. (5) **Portal dashboards are a composition,
  too** — `config('laraslice.dashboard.composition') =
  'portal'` renders the personal-workspace dashboard: a
  Welcome header with a live clock and the attendance punch
  (consuming the declared `clock` widget, whose panel is not
  repeated), dual-figure counters (a counter may declare a
  `secondary` measure with its own label/filter/tone — the
  "1 Pending · 1 Overdue" cards), and the widget set the
  portal declares. Dashboard filters accept an operator form
  (`{op: lt|lte|gt|gte|neq, value:}`) and the `@today` token —
  how a static manifest says "overdue". Two widget types
  join the set: `table` (declared columns over the slice's
  records, own-scope like every widget, relation-path fields
  + `with:` eager loads) and `calendar` (the current month,
  the declaring slice's records plotted on a date field).
  Widgets carry a declared `order`; collect() sorts by it.

## 20. List Contract v3 & Tab Content Contract — Density, Chrome, Wired Controls

A list page is a contract, not a layout preference (Body schema v3
in the framework's `docs/MIND_BODY_HEART.md`). Generation is
**reconciliation**: `slice:repair` keeps existing blades at the
current contract (rules (r)–(x)); a standard only new slices obey
is not a standard. The three files declare semantics; a UI-kit
component layer renders them — kits are swappable, contracts are
verified by MEASUREMENT (row height, chrome height, control
wiring), not screenshot resemblance. (24 Oct 2026: this
sentence governs the STANDARD kit's contract. Tier-5 replica
kits additionally pass the §19B appearance gate — computed-
style assertions against the captured source design spec.
Measurement, not resemblance, still rules: §19B measures
styles instead of comparing screenshots.)

- **Density is declared and total.** `ui.directory.density`
  (default `compact`, ~49px rows matching the source systems) is
  passed by the controller and governs EVERY grid cell —
  selection and actions cells included; one comfortable cell sets
  the whole row's height.
- **One actions row, one toolbar row.** Declared directory
  actions (Invite) render inside the top-bar cluster; quick
  filters/search/Filters/columns compose the data table's own
  toolbar via its `quick-filters` slot. Stacked chrome rows above
  the table are contract violations — measure the table's offset
  against the source page.
- **Every control is wired.** The top-bar Export dispatches the
  table's export event; inline editors post to the slice's inline
  endpoint, whose allowlist is the Heart's `inline_edit` plus the
  field's declared `options`. A rendered-but-dead control is a
  defect of the same class as a missing column. YAML option keys
  MUST be quoted when multi-word — unquoted flow-mapping keys
  parse truncated and corrupt the value domain.
- **Tab content contract v2 (closes H6 for relation tabs).** A
  resolved tab carries the related slice's action surface from
  its own Heart (`DirectoryUi::tabActionSurface()`): scoped Add
  (create route + relation FK preset), Export of the shown rows,
  per-row view/edit/delete, the slice's declared inline editor,
  and source-shaped pagination. Rows without actions are a
  picture of a list, not a port of one.
- **Same-name slices** (a fidelity slice and its porter twin)
  resolve manifests by PATH — the manifest a tab's columns came
  from is the manifest its actions and inline declarations must
  come from.

**v3 follow-through (same section, later 23 Oct).** The Filters
trigger lives in the data table's toolbar (the `table-actions`
slot dispatches `open-ls-filters`; the drawer listens on the
window event and its legacy standalone row self-removes when the
toolbar button exists — repair (y)). **Inline actions**: a
declared `inline_edit` field that is not a model attribute is an
inline ACTION — `BaseSliceWebController::inlineUpdate` delegates
to the service's `inlineFieldWriter($field)` (the Employees
role-pivot writer is the reference implementation); column-
declared `options` are the most local declaration and win in
`columnsForSlice`. The grid's editor cell serves any declared
inline column; the workflow transition select stays
status-only. Identity headers follow the same density standard
(repair (z)).

## 21. Page Structure Contract (Body v4) & Conformance — the porting standard

Full schema: framework `docs/MIND_BODY_HEART.md`, "Body schema
v4". The rules an agent must follow on ANY page work:

- **Composition is declared data.** Every list/detail/form
  page declares its regions from the closed vocabulary, in
  order. List: breadcrumb · title · header_actions · facets ·
  toolbar · actions · grid · pagination. Detail: breadcrumb ·
  identity · highlights · sections · tabs · related ·
  activity. If a page needs a region the vocabulary lacks,
  extend the vocabulary in the docs first — never improvise
  chrome in a blade.
- **Placement is required data.** `pages.list.placement`
  (header-right | toolbar-left | command-bar |
  below-facets-left). Ports copy the source's placement;
  industry conventions conflict, so no silent default on a
  port.
- **Title region holds the title only.** Actions live in
  header_actions / the actions band. No badges, version
  pills, or seed affordances in the title row unless declared
  (demo_seed is declaration-gated, default off).
- **Density and pagination are names** (compact |
  comfortable), never px. Banned in the 3 files: px values,
  colours, CSS classes, kit component names, inline code.
- **Detail widgets are declared too** (Body v4, Tier-5
  P2): charts and panels on a detail page go in
  `pages.detail.widgets` — {key, type: gauge | pie | donut
  | bar | stat_row | stat | list | card | calendar, title,
  shows}. A source chart with no declaration is a spec
  gap; a declared widget the kit renders as filler is a
  kit gap (§19B owns the assertion). Form presentations
  are captured evidence (source.design.yaml), never
  defaults: `presentation` in a Heart or ui-spec must
  match the capture (DesignLint presentation findings).
- **Enforcement:** `slice:drift --structure` audits every
  index blade against this contract (invalid placement,
  unguarded Seed chrome, version pills, placement readiness)
  — run it after any generator/repair change; 0 errors is
  the bar, warnings are the known backlog.
- **Anti-drift law:** generated pages emit ONLY declared
  regions/controls in declared order. Undeclared chrome in a
  blade is a drift error (slice:drift structure class) and a
  conformance failure — fix the declaration or the generator,
  never bless the chrome.
- **Conformance is the definition of done for a port:**
  structure (ARIA snapshot vs source), behaviour (one test
  per NN/g task: find / compare / view-edit-add / act),
  data (golden master), visual (same-kit phase only).
  Residual diffs go in the app's deviation register,
  classified planned | defect; a port is "100%" when every
  page is gated and every diff is fixed or
  approved-and-recorded — not when a screenshot looks close.
- **Design layer:** each kit carries a DESIGN.md (Google
  format: YAML tokens + prose rules) and a machine-readable
  component catalog (name, props, intent mapping). Check the
  catalog before creating any component; check DESIGN.md
  before choosing any visual value. Values come from tokens,
  never from taste in the moment.
- **Detail tab render modes:** a relation tab renders as a
  table by default; `render: board` (+ `group_by`) or
  `render: gantt` (+ `start_field`/`end_field`/`label_field`)
  declares an additional presentation of the same relation,
  rendered by the kit's board/gantt partials. Group labels
  resolve by the FK-relation convention (board_column_id →
  boardColumn → title/column_name/name). Declarations carry
  field names only — presentation stays in the kit.
- **JEV** is the Body→kit synthesis contract (regions →
  kit contract table → components), being built as a real
  layer under this standard (P1). Until it lands, pages are
  generator+repair output held to this contract by drift +
  conformance, and §17's synthesis wording is aspirational.
- **Account-backed create (users-backed directories):**
  when the source's directory IS a view over user accounts
  (employees, clients), the create/edit surface is ONE
  form that writes the account, the details record and the
  directory row in a single transaction — never three
  surfaces (quick create + invite + raw details form).
  On a slice that owns its web controller, override
  create/store/edit/update: validate the source's full
  field set, write user (with role) + details + row via
  forceFill inside DB::transaction, and give every
  promised field a real column first (migration on the
  slice). The directory row is born linked (user_id set),
  so hubs and self-service light up immediately. The
  employee-number suggestion derives from the directory
  codes' max suffix when the details sequence was never
  populated.
