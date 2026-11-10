## [2.1.1] - 2026-11-10

**The evaluator patch. Driven by two independent cold evaluations of v2.1.0 (a shipped-demo install-and-drive, and a from-scratch port) and by the owner's own hands-on test of the flagship's speed and feel. Full suite: 981 tests, 3793 assertions. Full notes: CHANGES-v2.1.1.md.**

### Added
- **Lazy hub tabs** — `ui.show.lazy_tabs: true` ships non-landing relation tabs as counts and loads their fragment on first view (`?tab=` deep links preserved). Flagship employee hub: 3,073,525 → 506,009 bytes initial payload (−84%).
- **Migration row-size preflight** — before a port's migrations run, every CREATE is estimated (charset-weighted worst case); a table over the InnoDB 65,535-byte limit stops the run up front with the table, estimate, and remedy named, instead of a mid-run error 1118 and a half-migrated schema.
- **Blaze leaf adoption** — slot-free leaf components (icon, spinner, separator) are memoized via `@blaze`; a no-op `blaze` directive is registered when livewire/blaze is absent, so the components render either way.

### Changed — behaviour existing consumers will notice
- **Directory updates are in place.** Inline edits, workflow transitions, quick filters, and the filters drawer's Apply swap the directory region via a fragment round-trip and close the drawer; failures fall back to ordinary navigation. Fragment requests are exempt from the default-view redirect that previously hijacked them on matrix-default slices.
- **Quick-filter option precedence** is now `options_from` → declared `options` → the field's declared options, so unbound filter selects render their real vocabularies.
- **`slice:links` classifies table claims by primary ownership** — two primary owners remain a failure; shared composition is reported as information naming the primary owner.

### Fixed
- **Regeneration staging completes fields from the root model's column list** — slices whose Contracts were held back indefinitely by the loss detector are unblocked (adoption remains deliberate per slice).
- **Mind/Body drafting survives map-shaped relation declarations** — `slice:plan:draft` no longer dies with "array to string conversion" on porter-shaped Hearts; the Mind is written.
- **Porter people facets** derive against the users table with the correct handle and lookup (`user_id`, `lookup:users:id:name`).
- **Release-archive skeleton directories** (`bootstrap/cache`, storage framework dirs) are asserted by the packaging build; their absence in v2.1.0 halted cold installs.

## [2.1.0] - 2026-11-09

**The AI-surface release. Driven by a full functional audit of the flagship demo, a screen-by-screen comparison against a production application running the original software, a day-2 operations drill, and a four-phase runtime programme. Full suite: 953 tests, 3709 assertions. Full notes: CHANGES-v2.1.0.md.**

### Added
- **Runtime metadata projection.** Schema and discovery are served from the declarations at runtime: a compact fleet index and a per-slice describe (fields, relations, operations, auth), with content-hash caching, ETag/304, pagination, domain filtering, and a live row-count overlay that is never cached.
- **One discovery ladder for MCP, Copilot, and HTTP** — index → describe → call, with per-tier payload budgets (`ai.ladder`: frontier / free / webgpu / on_device; unknown tiers fall back to `free`). The Copilot's provider path no longer assembles a whole-fleet dump per call.
- **Per-slice schema contract + Explorer.** A versioned contract (`laraslice/slice-schema` v1, with a JSON-Schema rendering) and an Explorer page that generates invocable forms for the operations a schema can derive; everything else is inspect-only with the reason stated. Validation failures return per-issue, self-repair-shaped errors (`{field, problem, expected}`).
- **Two-phase confirmation for AI writes.** No AI-initiated write executes in one step: proposal → a signed token bound to the surface, the tool/operation, the exact arguments, and the proposing user (300s default, single-use, consumed only after every check passes) → verified execution. Approval forms are validated catalog specs (operation · field · warning · note · expiry). An absent APP_KEY fails closed. Covers the MCP write tools and every Explorer write; named non-goals are documented (direct human-session HTTP is ordinary UI use).
- **`slice:regen-artefacts`** — re-syncs a slice's service, model, record views, and its three Contracts from the declaration, with a name-keyed loss detector that blocks any write that would drop hand-written code.
- **`slice:absorb`** — the reverse path: hand edits in generated files are read back into the declaration as proposals (declarable · vocabulary-gap · hand-owned). New declaration vocabulary: `hasManyThrough` relations and field-level `cast:`.
- **`slice:alter`** — additive-only declaration→database alters: one guarded migration for new columns; type/length/nullability changes and unsafe NOT NULL adds are refused and named; undeclared columns are named, never dropped.
- **`slice:corpus`** — a cross-runtime conformance corpus (fleet-derived + synthetic declarations), a runner asserting projection invariants, and a fidelity table. Pattern only: cross-runtime proof awaits a second runtime executing the same corpus.
- **Porter fidelity scorecard** — every port run emits a scored fidelity artefact in the port matrix's vocabulary (live reference run: 80.4 NOT SHIPPABLE on unmapped pivot foreign keys; a deliberately degraded re-score: 60.9).
- **Custom Fields runtime** — declared groups and fields render on slice forms and record pages with source-faithful storage semantics (select stores the option index, checkbox joins with `', '`, required-only validation), generated for new slices and healed fleet-wide for existing ones.
- **Declared hub tab types** — `permissions` (a unified grant matrix with a per-user typed-scope layer), `chart` (burndown series), `calendar` and `thread` relation-tab render modes, a `stack` composition mode, and `ui.show.default_tab`.
- **Settings wiring** — one framework read path consumes the settings hub's bound tables (company identity, app name, currency formatting, theme tokens, storage), so hub edits take effect on the surfaces that display them.

### Changed — behaviour existing consumers will notice
- **Strict binding on slice write paths.** Type-invalid input (e.g. text into a foreign key) is rejected with a 422 carrying per-issue errors instead of being coerced or partially saved. A blank string on a blank-allowed field remains the one exemption.
- **Update semantics are explicit.** Each slice's projection serves `update: {mode, declared}`; the default is `patch` (only provided fields change). A slice declaring `replace` rejects partial bodies with an error naming what is missing.
- **Permission re-sync totals.** A rebuild now derives the grant state deterministically (1,148 permission rows on the reference fleet, corroborated by a from-scratch rebuild); carried grants with a module total 427.
- New optional config sections `ai.ladder` and `ai.confirmation` — existing installs without them run on the built-in defaults (free tier, 300s TTL); no config publish is required.

### Fixed
- Regeneration covers the three Contracts (a silent data-loss path: added Contract fields rendered and validated but never persisted).
- Blueprint apply — in the CLI and in the Studio alike — reports success only after verifying the planned tables exist; migrations run from the slice's own folder.
- Studio stability: the Design factory loads in the standalone Studio wrapper, the save-preview diff modal binds inside the wizard root, slice-directory resolution searches the framework's real nesting depth, and a blueprint validate endpoint lets the engine itself judge declarations.
- Copilot factual reliability: one inventory source for counts, slice-aware routing ahead of table handlers, and complete count/health answers that name their basis.
- Declaration→preview fidelity: new slices are born in the canonical declaration shape (first preview diff 132 characters, previously ~5,200), and relation resolution is declaration-first — including a fix for person labels resolving through the wrong table (a record could render a different person's name).
- The flagship demo: session revocation destroys the stored session; role dashboards, record hubs, the task board, and messaging all work end-to-end (found by a clean-room functional audit, not by install smoke).

## [2.0.2] - 2026-11-02

**Convergence release — driven by a from-scratch sandbox port of a production application, converged surface-by-surface against the running original until its benchmark scope passed every Replica Protocol gate (scripted admin and employee journeys 24/24), then audited interaction-by-interaction across every module and completed with its messaging, reports, payroll and settings workspaces. Full suite: 643 tests, 2300 assertions. Full notes: [CHANGES-v2.0.2.md](CHANGES-v2.0.2.md).**

### Added
- Porter carries role→permission grants on auth import: legacy permission names translate onto framework slugs by entity evidence (navigation label, slice name, title, root table; token-vote fallback); scopes carried by name (added · owned · both · all · none) into a typed grant pivot; untranslatable names reported, never guessed.
- Permission scope layer: five grant scopes narrow directory lists and record pages at runtime (`access.own_scope` declarations, including a pivot-clause form; self-record hub access without a view grant).
- Design kits: the Mind declares `design.kit` (house default; a replica kit ships for source-faithful ports), `?kit=` session override, `slice:install` publishes the kits.
- Directory interaction layer: declared imports (`ui.directory.import`, declaration as allowlist), server-side XLSX export over the list's own filtered + scope-narrowed criteria, declared record actions (`ui.actions`: link · copy · send · method), public record links (`ui.public_link`) with an unauthenticated read page, and a `?print=1` print surface on record pages.
- One styled delete confirmation dialog across row menus, bulk delete and legacy confirm forms.
- Report group labels fall back to the model's own relation; conversation pattern gains a flat mode for pair-shaped message tables.
- MCP: dual-era protocol negotiation, resources (skills · gates · docs · slices · commands), and JSON gate output (`slice:drift` / `slice:conformance --format=json`) with a fix hint on every finding.
- Schema closure for auth tables' descriptive columns under a hard blocklist (credentials, tokens, MFA, payment identifiers, permission payloads never cross); auth values pair by natural key.
- Hub identity pattern (`ui.show.identity`: identity + About cards), widget relation fallback (user-scope columns, back-references, pivot joins), `pairs` widget type, `_period` current-period stat scopes, stats measure `default:`.
- SKILL §27: the named six-step path shared with spec-driven toolkits (constitution · specify · plan · tasks · build · converge).
- SKILL §28 pre-build dossier: six named slots (PRD · TRD · App Flow · UI/UX brief · schema · plan) mapped to LaraSlice artefacts; one-page TRD template (`docs/templates/TRD.md`); `success_metrics` slot in the Mind (drafts with the Mind, editable in Plan mode); `slice:flow` generates the App Flow artefact (HTML or Mermaid) from Mind journeys + slice navigation; the five Replica gates gain plain names (Structure · Data · Furniture · Tasks · Visual) said first everywhere.
- Studio YAML diff preview before overwrite (no silent writes); journey breadcrumb: Port → Design → Customize; `slice:diff` CLI parity with Studio preview.

### Fixed
- Bound settings toggles preserve each table's own value convention (yes/no · active/deactive · enabled/disabled · integers) on reads and writes; module-style status rows no longer render off or save as 1/0.
- Directory row-menu delete was a silent no-op in generated views; it now submits a real delete. Repeater definitions without a foreign key no longer 500 record creation (save-path guard + `slice:drift --structure` audit for the declaration class).
- Foreign keys resolve by evidence chain at generation (record-prefixed → conventional → fused → plural-prefixed → unique suffix → same-table siblings → people-are-users), every candidate verified to resolve; the same chain heals generated models via `slice:repair` (205/262 lookups resolving on the convergence port).
- Edit forms: string `*_id` codes no longer render as empty relationship comboboxes; date/datetime inputs emit their own format (raw values rendered empty and saved back as NULL).
- AI Copilot pill renders below sticky form action bars (it intercepted Save clicks).
- YAML Phase 3: JSON-only load warns once and points at migrate; YAML Phase 4: JSON read fallback removed; `slice:migrate-json-to-yaml` is the upgrade path.

# Changelog

All notable changes to the **LaraSlice** framework will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.1] - 2026-10-27

**Verification patch — every fix in this release was found by an independent verification programme run against the shipped 2.0.0: its full test suite on a second platform, a fresh install of the flagship package, and a from-scratch re-port of a production application.**

### Fixed
- **Data porting order.** A combined scaffold + data-import run now migrates before it imports; imports that skip tables whose targets do not exist report them loudly instead of returning success with zero rows.
- **Auth import is no longer silent.** Porting data without auth import prints a warning naming the skipped auth tables and the remedy; role assignments are never dropped without a signal. The runbook states the flag is required for a working replica.
- **Dashboard widgets emitted by the porter render.** Generated dashboards use `counter`/`chart` definitions the runtime dispatches (previously a non-existent type that rendered nothing), and generated manifests always declare their root model, so counters and model-bound features bind on fresh ports.
- **Hub tab curation.** Manifests can suppress inferred or declared tabs (`ui.show.exclude_tabs`, per-tab `hidden: true`), declared audit (`activity`) tabs render the record's audit trail or an empty state instead of vanishing, and the fields tab honours its declared label.
- **Portal dashboard scoping rule** documented and enforced by declaration: counters over person-owned domains declare `scope: own` with the slice's own-scope clause inline; Gate F pairs dashboards per persona.
- Slice discovery is invalidated after generation; artisan entry commands run against a fresh registry; manifest reads defeat the PHP stat cache; `slice:repair` is a true fixed point.
- Roles create/edit no longer reference unshipped schema columns; row actions and hub action surfaces are permission-gated for view-only users; schema sync can no longer drop the `title`/`status` columns.
- Studio add-field edits reach the form view again; AI settings save route is no longer shadowed by the settings hub catch-all; `slice:blueprint:validate` argument renamed to `path`.
- Flagship package: install config no longer carries a machine-specific slices path; the shipped seed honours every login the install guide promises; the portfolio of schema carried over from the source application is complete for the audited domains, with portal-scoped dashboard counters.

### Tests
- Full suite green on two platforms: 555 tests, 1919 assertions (baseline at 2.0.0: 535 tests with 47 reds, now zero).

## [2.0.0] - 2026-10-27

**The Foundation Release — Mind · Body · Heart, the Standard UI/UX system, and the Replica Protocol**

Proven live against a running port of a production ERP, compared page-by-page with its source throughout the program.

### Added
- **Mind · Body · Heart** — `system.yaml` (intent), `*.ui.yaml` per slice (declared surface), `slice.yaml` (sole runtime truth). `slice:plan:draft` drafts a Mind and Bodies from discovery, marked as drafts, never overwriting.
- **Studio: six modes** — Plan (the Mind readable and editable with diff-before-save) and Operate (health checks, backups per the Mind's policy, access review, operator-manual generation) join Port, Design, Create, and Manage as destinations in the ONE Studio.
- **Standard UI/UX system** — directories, overlay/page forms, hubs, documents with line-item arithmetic, matrices (roster + legend + fused filters), named and comparison reports, keyed-rows settings sections, and dashboards behave by declaration; specialized screens are standard YAML patterns, never per-slice CSS.
- **Layout kit** — ported trees render under the source's shell model; strip-placed filters fuse into the one directory band; `ui.show.composition: overview` gives detail pages the hub composition (record title in the topbar, fused tab strip, widgets-led overview, details card); portal dashboards (`laraslice.dashboard.composition`) compose a personal workspace: welcome header with live clock and attendance punch, profile card, dual-figure stat cards, own-scope `table` widgets, and a `calendar` month grid. Dashboard filters accept an operator form and the `@today` token; widgets carry a declared order.
- **100% Replica Protocol** — five porting gates (schema parity, join-verified data truth, furniture, task conformance, visual conformance read from paired screenshots) with a per-port `REPLICA-STATUS.md`; the guidance ships in the framework skill (concepts map included).
- **Declared patterns** — relation-multi form fields (multi-selects resolved from the root model's relation, synced by the base service), planned-vs-actual `bars` show widgets, a `lines` directory cell formatter, import with upsert, invite, seed actions, clock punch, declared record actions, and a server-rendered conversation surface.
- **Workflow notifications** — a `WorkflowTransitioned` event with a built-in assignee/owner listener.
- **Account-backed create** as a standard pattern: one form writes account + details + directory records transactionally (demonstrated by employee hiring in the reference port).

### Changed
- Own-scope data access is a foundation behavior: view-own grants narrow lists and guard detail routes; per-person grant/revoke overrides sit on top of role clubs; enforcement reads the Heart's declared permissions first.
- Form hygiene is on by default fleet-wide (system columns never render; workflow initial state applied on create); `slice:drift` audits Mind/Body promises against actual grants and `--fix` reconciles; `slice:repair` retrofits patterns into generated slices idempotently.

### Fixed
- Generator template integrity (row-action emission), lossless `slice:cache` (name-shadowed slices no longer dropped), registry-wide `slice:sync` and settings aggregation, repair passes no longer invent routes, deterministic `modelClass()` resolution with recorded ambiguity, charts render server-side with no host-side JS dependency, and blueprint apply works end-to-end in YAML.

## [1.8.0] - 2026-10-20

**ONE Studio + YAML-only manifests + Default UI**

### Added
- The ONE Studio: the developer studios unified into one shell (Port, Design, Create, Manage modes) with a YAML diff preview before any overwrite — no silent writes.
- `slice.yaml` is the only manifest format; JSON read fallback removed (`slice:migrate-json-to-yaml` is the upgrade path).
- Default UI program: generated and ported applications present correctly with zero hand-tuning — directory formatters, quick filters, and composition defaults resolved from the live manifest at request time.

## [1.7.0] - 2026-10-20

**Slice YAML v0.1 + Formatter Pipeline + Non-Laravel Porting**

This release turns ported schemas into rich, demo-grade UI by describing presentation in YAML — and proves it by porting two real systems end-to-end: a **production Laravel ERP** (85 slices, 1,630 rows imported) and a **non-Laravel PHP ERP** (407 tables discovered from SQL dumps). Conventional PHP-builder approaches informed the design; the YAML-first, AI-writable manifest is LaraSlice's differentiator.

### Added
- **Slice YAML v0.1 spec** — `docs/SLICE_YAML_V0.1.md` + machine-readable JSON Schema (`schemas/slice-v0.1.json`) with closed vocabularies: 8 field types, 10 directory formatters, `input` widget enum. No HTML in YAML, ever.
- **`php artisan slice:validate`** — Validates a slice YAML against the v0.1 schema (single file or `--all`), with exact error paths; exit 1 on failure. Data type vs form widget split: `type` is storage-only, `input` declares the widget.
- **Formatter pipeline (YAML → pixels)** — Directory columns carry `formatter` metadata end-to-end: generator emits it, `DataTableRows` maps it, and the data-table renders `status_pill`, `progress_bar`, `money_breakdown`, `date_overdue`, `code_badge`, `text_with_badge`, `composite_user`, `date_time`, `number`, and `text` formatters.
- **Phase 2 DataTable parser** (`DataTableUiParser`) — Statically classifies source `*DataTable.php` closures into the v0.1 formatter vocabulary (no closure execution, no HTML scraping). Wired into `slice:repair --re-infer-ui --source`.
- **Request-time directory UI resolution** (`LaraSlice\Support\DirectoryUi`) — Index views resolve column formatters from the live slice manifest at request time; works for path-repo, vendor, and published installs (replaces repair-time absolute-path baking).
- **Workflow bulk actions** — Declared in YAML (`ui.directory.actions.bulk`), enforced by RBAC, with Studio editors for quick filters, sidebar filters, summary cards, row/bulk actions, and form sections.
- **Non-Laravel porting** — `slice:port` now discovers schemas from `CREATE TABLE` SQL dumps (a source system's `install/mysql` dump, etc.), strips table prefixes (`--strip-prefix=llx_`, defaults `llx_`/`wp_`), and ports MySQL sources via `--source-*` connection options.
- **Porting safety: no silent wrong-database** — If no source schema is found, `slice:port` hard-fails with five numbered fix options instead of falling back to the target app DB. Opt-in fallback only via `LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=true` (discouraged).
- **Runnable examples** — `blueprints/examples/kitchen-sink.slice.yaml` (all 10 formatters) plus CRM (Companies, Contacts, Deals), HR (Employees, Leave Requests), and Shop (Products, Orders) suites. All validate against v0.1 and apply via Blueprint Studio or CLI.
- **Dev Studio Standard** — `docs/DEV_STUDIO_STANDARD.md`: the unified Dev Studio + CLI contract (parity table, Generate = files + migrate + seed, single "Start from…" picker, mobile rules) that future Slice runtimes (NetSlice, PythonSlice, JavaSlice, FlutterSlice) inherit.
- **`LARASLICE_MFA_ENABLED`** — Environment toggle for MFA enforcement (dev/test), `SecurityPolicyService::requiresMfa()`; `slice:repair` merges missing keys into published `config/laraslice.php` without overwriting.
- **`slice:blueprint:apply --migrate / --seed`** — Apply now optionally runs migrations and seeds demo records in the same flow, and always prints the exact seed command.

### Changed
- **Blueprint applier speaks v0.1** — `BlueprintValidator` normalizes `spec_version: "0.1"`, accepts the full formatter list, maps column `key` → `handle`, and lifts map-style `fields:` into `models[0].fields`. Valid YAML is now also applicable YAML.
- **Porting Studio & Blueprint Studio** — Single "Start from…" picker (Presets | Examples v0.1 | Single Slice Blueprints); secondary actions move into an overflow menu on small screens; mobile overflow fixed at 390px; AI Copilot starts collapsed on small viewports.
- **Porting is non-interactive friendly** — `--dry-run` / `--no-interaction` defaults to `mode=system-base` without prompting (CI-safe).

### Fixed
- **ETL chain** — Source connection is passed through the full port pipeline; `--scaffold` no longer bypasses ETL; NOT NULL target columns missing from source are filled with defaults; column-name mismatches resolved via alias families (`heading`→`title`, `project_name`→`title`, `deadline`→`due_date`). Verified: 1,630 rows / 66 tables imported from the source ERP.
- **Formatter data loss in data-table** — `$cols` mapping previously dropped `formatter`/`badge_from`/`breakdown`; Alpine now receives formatter metadata intact.
- **Status column invisible with inline edit** — Cells with `inline_edit` now always render formatted HTML and only show the workflow transition `<select>` when transitions exist.
- **Studio JS errors** — `selectedSliceIdx` defaulted; null-property guards on Studio pages.
- **Generated form validation** — Ported fields set `required`/`nullable` from DB nullability; identity columns default to required.

---

## [1.6.4] - 2026-10-17

### Added
- **RBAC Hardening** — `super-dev` platform developer role with whole-system bypass (`Access::isSuperDev()`); immune to data-port wipes; created by `slice:install` and migration `2026_10_17_000000_add_super_dev_role` for existing installs.
- **ETL Auth Guards** — `roles`, `permissions`, `permission_role`, `role_user` are never wiped by default (`skipped_system_auth`); `users` merged by email; opt-in `--import-auth` normalizes legacy auth data instead of replacing.
- **Slice Studio UI Editors** — Full Page & UI Architecture editor: Quick Filters (reorder, field picker, options source), Sidebar Filters, Summary Cards, Row Actions (⋯Menu/Inline/Hybrid), Bulk Actions, Create & Edit Form sections.
- **Core Slice UI Alignment** — Users page rewritten to foundation layout; `slice.json` ui blocks for core slices; `getSliceUiConfig()` reads slice.json; `getDistinctFilterOptions()` supports `getModelClass()` services.
- **AI-First Porting Intelligence** — Three new source analyzers in `SlicePortService`:
  - `detectDeadTables()` — Flags tables with no active Eloquent model (e.g., Akaunting's 2017 v1 legacy tables). Found 22 dead tables in Akaunting.
  - `detectBusinessLogic()` — Scans controllers for non-CRUD methods (`generatePdf`, `sendEmail`, `markSent`) and generates `porting_notes` in each `slice.yaml`.
  - `analyzeSourceUi()` — Inspects Blade templates to extract form fields, sections, and modal patterns (found 51 fields in Akaunting's document forms).
  - `detectWorkflows()` — Detects state machines from `mark*` methods (e.g., `markSent`, `markCancelled`) and generates workflow definitions.
  - `analyzeUserJourneys()` — Identifies persona-based workflows: onboarding wizards, client portals, core business flows, admin journeys.
- **v1.6.2 UI Schema Generation** — `buildUiFromInspectedFields()` generates canonical `ui:` blocks from REAL database columns with smart formatter inference (`amount` → `money_breakdown`, `status` → `status_pill`, `*_at` → `date_time`, `*_number` → `code_badge`).
- **Workflow Engine Integration** — Detected workflows now populate `slice.json` manifests via `workflowConfig`. The `WorkflowEngine` is no longer dead code.
- **User Journeys Tab** — New 🧭 tab in Porting Studio dialog visualizing persona-based workflows with step-by-step flow diagrams.
- **Step-by-Step Developer Guidance** — Porting dialog now shows a 5-step recommended workflow: Review Journeys → Inspect VSA → Review UI → Load into Studio → Execute.

### Fixed
- **26 porting bugs** including: `--super-domain` ignored, scaffold errors swallowed, `$rowActionsSlot` undefined, hardcoded DB credentials, pivot tables dropped, fabricated KPI stats, auto-fire Analyze (4 paths), blocking `alert()` dialogs, stale plan data, and more. See `CHANGES-v1.6.4.md` for full list.
- **Missing `ui:` blocks** — `scaffoldSlices()` now generates v1.6.2 canonical UI schema for every ported slice.
- **Incomplete `slice.yaml`** — Now includes child models with relations (Invoices: 1 model → 5 models, 8 relations, VALID).

## [1.6.3] - 2026-10-16

### Fixed
- **Sanctum Migration Deduplication in `slice:install`**:
  - `SliceInstallCommand` now checks if any `*_create_personal_access_tokens_table.php` migration file already exists before calling `vendor:publish --tag=sanctum-migrations`.
  - Automatically deduplicates and removes redundant pending Sanctum migration files, preventing SQLSTATE 42S01 (`Table 'personal_access_tokens' already exists`) when re-initializing or running `slice:install` on fresh database installations.

## [1.6.2] - 2026-10-16

### Added
- **Enterprise N-Tier Architecture & Recursive Slice Discovery**:
  - Recursive directory discovery up to 6 levels deep (`app/Slices/{SuperDomain}/{Domain}/{Slice}`, `app/Slices/{Domain}/{Slice}`, or `app/Slices/{Slice}`).
  - Extended `SliceManifest.php`, `slice.json`, and `slice.yaml` schemas with `super_domain`, `domain`, `sub_domain`, `table_prefix`, `multitenant`, and `tenant_key` metadata.
  - Dynamic PSR-4 namespace calculation (`getNamespace()`) reflecting exact physical directory taxonomy.
  - Hierarchical navigation builder in `SliceManager` (`getSuperDomains()`, `getDomains()`, `getHierarchicalNavigation()`).
  - Domain acronym & CamelCase preservation in `SliceName::domainSegment()` (`ERP`, `ProjectExecution`, `FinancialAccounting`).
- **Pluggable Multi-Tenancy & SaaS Isolation**:
  - `TenantManager` singleton managing active tenant context, identification, and `withoutTenancy()` bypass for background jobs and super-admin operations.
  - `HasTenant` Eloquent model trait with automatic `TenantScope` query registration and automatic tenant assignment during model `creating()`.
  - `TenantScope` global query scope applying `where($table.$tenantKey, $tenantId)`.
  - `IdentifyTenant` middleware resolving tenant context from route parameters, query string, request headers, or authenticated user company context.
  - Centralized tenancy configuration under `config/laraslice.php` (`tenancy`).
- **Universal Monolith Reverse-Engineering & ETL Data Streaming Pipeline (`slice:port`)**:
  - Multi-block `Schema::create` migration parsing in `SlicePortService.php` to introspect consolidated initial migrations across large enterprise monoliths (tested on a 227-table production ERP).
  - Multi-tenancy column detection (`company_id`, `tenant_id`) and exclusion from aggregate-swallowing algorithms.
  - Bounded business domain consolidation (12 Canonical Business Modules instead of raw flat tables).
  - Dynamic seeder generation: extracts multi-table master taxonomies into deterministic database seeders in `database/seeders/` to eliminate blank select dropdowns on clean slate deployments.
  - `SliceDataPortService`: High-throughput ETL data streaming engine with foreign key check suppression, dependency-aware sequencing, and auto-ID preservation.
  - CLI commands: `php artisan slice:port` with `--plan`, `--import-data`, `--source-connection`, `--dry-run`, and `--no-interaction`.
- **Interactive Blueprint Studio Porting Suite & Canvas Loading**:
  - Added **🚀 Port Legacy App** modal in Blueprint Studio with live side-by-side architecture preview, live BlatUI sidebar preview with record badges, and ETL pipeline inspection.
  - **📥 Load Ported Suite into Blueprint Studio**: Loads 28 bounded slices directly into the Blueprint Studio canvas with full model schemas, field definitions, and 4-step BlatUI architecture plans.
  - 4-step BlatUI Page & UI architecture inspector for every slice (Listing/Directory, Show Workspace with Sub-Tabs, Create/Edit Forms, Workflow/FSM state transitions).
- **Declarative UI Schema & TypeSafe UI Synthesis (`slice.yaml`)**:
  - Canonical `ui:` schema declaration for `presentation` (`create: drawer|page`, `edit: drawer|page`), `directory` (columns with formatters: `code_badge`, `composite_user`, `status_pill`, `money_breakdown`, `progress_bar`, `date_time`, `text`), `quick_filters`, `sidebar_filters`, and `forms` (sections grid 1-4, field span 1-4, `is_searchable`).
  - Instant slide-over drawer (`<x-ui.sheet>`) with browser URL deep-linking (`?drawer=create` synchronization and `pushState`).
  - Modular YAML validation and synchronization: `php artisan slice:validate [slice] [--part=ui|forms|directory|fsm] [--fix]`.
- **BlatUI Enterprise Components & Data Table Upgrades**:
  - Top header Module Switcher Ribbon with reactive Alpine.js filtering across active bounded domains.
  - Collapsible domain-scoped sidebar with active domain badge and 1-click clear.
  - Enhanced `<x-ui.data-table>`: multi-select checkboxes, bulk actions bar, sortable headers with raw value sorting (`__raw`), status badges, and inline row quick-action dropdowns.
  - New UI components: `<x-ui.import-modal>` for ETL/CSV uploads, `<x-ui.searchable-select>` for foreign key relations, and `<x-ui.status-pill>` for state machine status rendering.
- **Generator & Seeder Hardening**:
  - Defensive migration guards: `if (Schema::hasTable($tableName)) return;` prevents collisions with framework core tables (`users`, `cache`, `job_batches`).
  - Enterprise field normalization capacity increased from 50 to 250 fields.
  - Smart FK relationship wiring: foreign key `*_id` fields automatically resolve to target slice models with searchable select dropdowns.
- **RBAC Hierarchical Matrix**:
  - Hierarchical permission matrix in `Roles/Resources/views/form.blade.php` grouped by super-domain and domain with bulk toggle controls.

## [1.6.1] - 2026-10-12

### Added
- **Vertical Slice Workflow State Machine Engine**:
  - Native lifecycle orchestration engine (`WorkflowEngineService`, `HasWorkflow` trait, `WorkflowWebController`, `WorkflowActionController`).
  - Interactive visual UI components for state transitions: `x-workflows.stepper`, `x-workflows.timeline`, `x-workflows.action-tray`, and `x-workflows.forward-modal`.
  - Robust state transition gating, role-scoped permission guards, transition remarks enforcement, electronic file movement/handoffs, and historical audit trail logging.
  - Built-in Enterprise Workflow templates: `DisputeAdjudicationWorkflow`, `ExecutiveProcurementWorkflow`, and `GrantDisbursementWorkflow`.
- **Dual-Flow Statutory Adjudication & Citizen Intake Architecture**:
  - Production-grade dual-flow intake separating **Admin Docket / Inward Registry Intake** (inward delivery mode, courier tracking/consignment, diary date/time stamping, prior petition linkage) from **Citizen Grievance Submission** (streamlined public filing defaulting to online intake).
  - Multi-stream dispute partitioning:
    - *Stream A*: Workplace Harassment & Statutory Inquiries (harassment nature, organization classification, employment nature, postings).
    - *Stream B*: Property Rights & Asset Restitution with dynamic multi-unit repeatable Property Dispute blocks (`+ Add Another Property` with district, acquisition mode, property nature, secretariat region, and parcel/khasra details).
  - Multi-respondent accused repeater (`+ Add Another Accused`) supporting unidentified respondent flags, corporate designations, and postal summons service addresses.
  - Documentary evidence file upload repeaters and statutory solemn declarations.
- **Enterprise Porting Engine & Monolith Reverse-Engineering Improvements**:
  - Real-world monolith reverse-engineering with schema introspection and automatic classification into Aggregate Roots, Child Sub-tables, and Shared Reference Taxonomies.
  - Automated taxonomy seeder generation: extracts multi-table reference registries into clean, deterministic database seeders, eliminating blank select dropdowns on clean slate deployments.
  - Clean bounded domain navigation architecture: automatically scopes non-aggregate child tables (`accused`, `properties`, `approvals`) under primary aggregate root controllers, eliminating raw table clutter from navigation menus.
  - Automated role provisioning and permission synchronization with cascade cleanup during slice removal and domain reconstruction.
- **Blueprint Studio Enterprise Suite**:
  - Added enterprise-ready **Dispute Adjudication Suite** preset (6 Slices: Complaints, Hearings, Notices, Orders, Inquiries, MasterData) modeled after real-world judicial dispute adjudication workflows.
  - Synchronized two-way Domain YAML editor and visual slice designer with multi-table aggregate configuration.

## [1.6.0] - 2026-10-11

### Added
- **Monolith Reverse-Engineering & Legacy App Porting Engine (`slice:port`)**: Reverse-engineers legacy monolithic Laravel applications (such as statutory dispute adjudication portals, education management systems, or internal ERPs) into Clean Architecture vertical slices grouped by Bounded Domains.
- **Dual Data Porting & Migration Modes**:
  - `system-base` (Clean Development Slate): Automatically scans legacy database schemas, extracts reference master taxonomy tables (e.g. `complaint_statuses`, `categories`, `hearing_types`, `districts`), and generates deterministic database seeders in `database/seeders/` to guarantee 0 blank dropdowns on clean slate deployments.
  - `full-data` (Production Migration Cutover): Topologically migrates all historical transactional records, preserving parent-child IDs and foreign key integrity. Includes `--with-files` to automatically copy physical uploads and document attachments from legacy `storage/app/public`.
- **Interactive Visual Studio UI (Wizard & Blueprint Studio)**:
  - Added **🚀 Port Legacy App** modal in Blueprint Studio (`/laraslice/wizard/blueprint`) and launch button/banner in Wizard (`/laraslice/wizard?port=1`).
  - Interactive **🔍 Analyze & Preview Mapping** displays live side-by-side Table-to-Slice Architecture comparison before executing migrations.
- **Conversational AI Agent Protocol**: Enhanced `SKILL.md` with instructions requiring AI coding assistants to proactively prompt developers for project path, target domain, and data mode (`system-base` vs `full-data`) when invoked in chat/CLI environments.

## [1.5.3] - 2026-10-10

### Fixed
- **WebAuthn Passkey signature counter validation**: Platform authenticators and synced passkeys (Windows Hello, Touch ID, Face ID, Apple Keychain, 1Password) do not increment monotonic signature counters across devices and emit `signCount = 0`. Previously, `WebAuthnService` artificially incremented `sign_count` on the first sign-in, causing subsequent sign-in or lockscreen unlock attempts to fail with `signature counter not valid` or `Passkey verification failed`. LaraSlice now aligns with W3C WebAuthn / FIDO2 standards by skipping counter comparison for passkeys unless explicitly enabled (`LARASLICE_PASSKEY_ENFORCE_SIGNATURE_COUNTER=true`) for dedicated hardware tokens (e.g. YubiKey).
- **Full-screen Auth & Lockscreen scrollbar support**: Resolved an issue where `overflow-hidden` on `body` prevented scrolling when content expanded beyond the viewport height (e.g. on smaller screens, expanded backup MFA options, or lockscreen credentials). All authentication and lockscreen layouts now use `overflow-y-auto` and auto-centering margins (`my-auto`).

## [1.5.2] - 2026-10-10

### Fixed
- **Multi-slice generator file locking resilience on Windows**: Fixed an issue where rapidly generating multiple slices in a domain preset (such as CRM or E-Commerce) in the Wizard could fail on Windows/NTFS with `Unable to publish generated slice; no existing files were replaced`. Transient file-handle locks from watchers (e.g. Vite `npm run dev`), search indexers, or antivirus could prevent atomic directory renaming. LaraSlice now triggers garbage collection, extends retry attempts, and falls back to a recursive copy (`Directory::copy`) before safely cleaning up the staging directory.

## [1.5.1] - 2026-10-09

### Fixed
- **`slice:install` checks that the front end builds.** It now runs `npm run build` after `npm install`. When npm has skipped a platform package (the npm bug behind "Cannot find native binding" from Vite/rolldown, https://github.com/npm/cli/issues/4828), it says so and how to fix it: delete `node_modules`, run `npm ci`, then `npm run build`. Without a build, the layout falls back to CDN assets in which dropdowns are not positioned.
- Installer steps that fail now show FAIL on Laravel 13 (Laravel 13 shows DONE for a task that returns `false`).

### Upgrading from 1.4.x
- If you ran `slice:install` with 1.4.x and then updated, run `php artisan slice:install` once more. It keeps your files and admin password, and on a local install it turns Slice Studio on (`LARASLICE_WIZARD_ENABLED=true`).

## [1.5.0] - 2026-10-09

A correctness, performance and maintenance release. No new security defaults, but read **Upgrading**: a few behaviours changed.

### Upgrading
- **Run `php artisan migrate`.** A new migration adds `source` and `domain` columns to `permissions`. Permissions synced from slices are marked `source = laraslice`; only those are ever pruned.
- **Run `php artisan slice:repair` once** if you have slices generated by older versions. Discovery used to rewrite slice files on every request; it no longer writes anything, and the upgrades it applied now happen only through `slice:repair` (use `--dry-run` to preview).
- **Composer now requires `laravel/framework`** (11, 12 or 13) instead of a partial list of `illuminate/*` packages, plus `guzzlehttp/guzzle` and `lbuchs/webauthn`. `minimum-stability` is `stable`.
- **`slice:field` columns are nullable by default**, as documented. Use the new `--required` flag for NOT NULL.
- **Regenerate route caches.** Generated slices register one named route group; the duplicate domain and singular aliases (`billing.invoices.*`, `invoice.*`) are gone because `route:cache` silently dropped them.
- **MCP clients:** tools now describe their arguments as `inputSchema` (per the spec), and `laraslice:mcp --transport` is removed (only stdio ever worked).
- **AI providers:** default models are `claude-opus-5-5`, `gemini-3.8-flash` and `gpt-4o-mini`. The copilot always uses the provider chosen in AI settings; the browser can no longer pick one.

### Fixed — generator, blueprints and Slice Studio
- Fields added through `slice:field` or the studio are added to `$fillable` and the validation rules; they were silently dropped on save.
- Slice names that are PHP reserved words or clash with imported classes (`List`, `Match`, `Model`, …) are rejected, and generated PHP is parsed before it is written.
- Generated DTOs have typed properties and defaults (a boolean field used to produce a fatal error), and request values are coerced to those types instead of throwing a 500.
- Generated schema classes, child-entity routes (no more `show` route) and `belongsTo` relations work: related models are found through every discovered slice, and foreign keys are indexed and constrained when the table is known.
- Adding a child table is all-or-nothing; schema sync refuses to drop core columns, writes a real `down()`, and rollbacks report failed migrations instead of claiming success.
- `slice:toggle --domain` and creating slices with child tables in the wizard crashed (both called methods that did not exist).
- Blueprint validator, planner, applier and generator accept the same field types and options; `length`, `encrypted` and `soft_deletes` take effect, and options nothing implements are rejected. All built-in studio templates validate.
- Navigation edits move the existing route group instead of duplicating it, and never write into the package's own core slices.
- Every `slice.json` writer uses one field format (a map keyed by column name), with locked, strict JSON writes. Older manifests are converted when read.
- Flutter export: integer ids, the real plural and domain-aware API path, a Sanctum bearer token, custom fields, and `--force` instead of silent overwrites.

### Fixed — users, database and AI
- Partial user updates no longer reset status, roles or profile details.
- Foreign-key toggling and table listing are portable (`Schema::disableForeignKeyConstraints()`, `Schema::getTables()`); destroy, wipe and seed work on SQLite and PostgreSQL.
- When LaraSlice creates the `users` table it uses an auto-increment id, and rolling back its migration no longer drops the host application's table.
- MCP follows the protocol: version negotiation, `ping`, no responses to notifications, `isError` tool results, and the real package version.
- Claude replies are read from text blocks (current models return thinking blocks first, which gave empty replies).
- The copilot honours the floating-bubble and live-telemetry settings, and the bubble only shows for users with `ai.copilot.use`.
- The MFA policy accepts host user models that have no `hasMfa()` method.
- Device and attempt logs recorded iPhones as macOS and Opera as Chrome, and labelled remote sessions with a made-up "Islamabad, PK" location.
- `laraslice:audit:prune` ignored the configured retention because `--days` defaulted to 90; without `--days` it now uses the `audit.retention_days` setting.
- The facade alias, the starter publish path and `laraslice:skill:publish` pointed at things that did not exist; `active: "false"` in a manifest now disables the slice; the studio no longer fails with 419 (missing CSRF meta tag).

### Fixed — found by installing into a fresh Laravel 13 app and testing in Chrome
- **Installable on Laravel 13:** a new app locks Guzzle 8, which the Guzzle requirement rejected.
- **`slice:install`:** re-running it no longer overwrites a customised `config/laraslice.php`, layouts, components, CSS or JS (`--force` still does); on a local install it turns Slice Studio on, since the summary and landing page link to it.
- **Slice Studio:** presets and forms generate again (string lengths and defaults such as `"50"` and `"0"` were rejected); no JavaScript errors; the audit trail search and filters work.
- **AI settings page** returned 500 whenever Slice Studio was disabled (the default).
- **Sign-in** lands on the dashboard route (Slice Studio for builders, the account page for others) instead of always on account settings.
- **End users** see only the controls they may use: generated listings hide Create, Edit, Delete and Seed without permission, and Studio links are hidden without `studio.access`.
- **Permissions:** a slice destroyed and generated again gets its permissions back (a stale cache skipped the sync).
- **Two-factor setup** from the account page needs a confirmed code before it turns on; before, it was active immediately and could lock users out. QR codes are rendered on the server (`bacon/bacon-qr-code` is now required).
- **Passkeys** work end to end on `localhost` (tested with Chrome's virtual authenticator); the 127.0.0.1 hint shows the app's real address instead of port 7000.
- **Account settings** validate the profile (another user's email used to cause a 500); gender can be left unset.
- **Demo data:** booleans are 0/1 (they overflowed on MySQL) and select fields use their declared options.
- **AI copilot** says whether a provider key is configured instead of advertising a free service that needs a key.
- `slice:destroy` removes the empty domain folder.

### Performance
- Booting slices no longer reads, rewrites or writes files on each request.
- Roles, permissions, navigation and schema checks are memoized per request; repeated permission checks run no queries.
- Opening the role pages no longer re-syncs every permission.
- The users, roles, MFA and metrics pages run the same number of queries regardless of row count; device lists and relationship dropdowns are capped (`forms.relationship_options_limit`, default 500).
- AI provider calls use Laravel's HTTP client with a connect timeout, configurable timeout and retries (`ai.http.timeout`, `ai.http.retries`), and log failures without credentials.

### Changed
- `audit.enabled` now stops audit writes, and `audit.auto_prune` schedules a daily prune.
- The vendored WebAuthn library is replaced by the `lbuchs/webauthn` package (same API, newer release).
- Alpine.js and the Tailwind browser build are pinned to exact versions with Subresource Integrity.
- `slice:ai` describes itself honestly: it picks the slice name and workflow by keyword matching and calls no AI provider.
- Form labels and icon-only buttons on the sign-in, users, MFA and roles pages have accessible names.
- `skills/laraslice/SKILL.md` is the only skill file, updated for this release.

### Removed
- The unused `ui_framework` config key, `Field::toFlutterWidget()`, and the never-rendered `Settings` AI view (the page is `laraslice::ai-settings`).
- `LaraSlice\Console\Commands\SliceSyncCommand` moved to `LaraSlice\Commands\SliceSyncCommand`.

### Development
- PHPUnit 11/12 on Testbench, Pint, and Larastan level 5 (with a baseline of pre-existing findings), run by GitHub Actions on PHP 8.2–8.4 × Laravel 11–13. Run `composer test` and `composer lint`.
- Test suite grew from 62 to 298 tests, including route and command smoke tests, query-count checks and replays of browser requests.
- Added the MIT `LICENSE` file; tests, docs and tooling are excluded from Composer dist archives.

## [1.4.1] - 2026-10-07

### Bug Fixes
- **Passkey / WebAuthn:** Fixed `user not verificated during authentication` error during passkey sign-in and lockscreen verification. Passkey assertions now use `preferred` user verification by default, allowing authenticators that verify presence (UP) without the strict biometric UV flag to authenticate successfully. Configurable via `LARASLICE_PASSKEY_USER_VERIFICATION` and `LARASLICE_PASSKEY_REQUIRE_UV`.
- **Starter Layout:** Guarded `route('laraslice.wizard')`, `route('laraslice.wizard.schema_studio')`, and `route('settings.ai')` with `Route::has(...)` in `resources/views/layouts/app.blade.php`. Prevents `RouteNotFoundException` when the Slice Studio or AI settings routes are disabled.
- **Dashboard Redirect:** Added `Route::has('laraslice.wizard')` check before fallback dashboard redirect to prevent routing exceptions when Slice Studio is disabled.
- **RBAC / User Trait:** Added `hasMfa()` method to `HasSlicePermissions` trait for compatibility with host applications using standard `App\Models\User`.
- **MFA / TOTP Challenge Screen:** Authenticator App (TOTP) tab and alternative login options now only show if the user has actually scanned and verified their TOTP code. If a user only enrolled via Passkey, provisional or unverified TOTP credentials are not presented on the challenge screen or lockscreen.

## [1.4.0] - 2026-10-07

This is a security release. Upgrade every installation that is reachable from a network. Several defaults change; read **Upgrading** before deploying.

### Security
- **Removed a bundled OpenCode API key.** v1.2.0–v1.3.3 shipped a hard-coded `sk-5bR4…` key that was used whenever no key was configured. Treat that key as public. Set your own `OPENCODE_API_KEY` (or another provider) if you used the copilot without configuring one.
- **Authentication on every core route.**
  - Users, Roles and Settings web routes now require `auth`; their API routes require `auth:sanctum`.
  - Before, guests could list, create, edit and delete users and roles. They could also overwrite SMTP settings and act as the first user in the database: read their recovery codes, change their email, disable MFA and register a passkey.
- **Deny-by-default permissions.**
  - Slice controllers (web and API) deny an action unless the user holds the matching permission (`users.create`, `roles.edit`, …).
  - Previously a check was skipped when the permission row was missing or the database threw an error.
- **No super-admin shortcuts.**
  - Super-admin status now comes only from the `super-admin` role.
  - Users whose email was `admin@laraslice.com`, and users holding role id 1, are no longer treated as super-admins. Anyone could register that email through the open API.
- **AI copilot.**
  - All copilot routes require sign-in plus `ai.copilot.use`; settings and provider tests require `ai.settings.view` / `ai.settings.edit`.
  - The copilot can no longer insert into arbitrary tables. Before, guests could add rows to `role_user` and make themselves super-admin.
  - It never reads rows from, or writes to, protected tables (users, roles, settings, tokens, …), and other tables need the matching slice permission.
  - Provider keys are stored encrypted and never sent back to the browser.
  - TLS certificate verification is back on for provider calls.
- **MCP endpoint.** Off by default; POST and JSON-RPC only; Sanctum bearer token plus `studio.access`. Destructive tools require a `confirm` argument and the matching permission.
- **Slice Studio.**
  - Off by default.
  - Write actions need their own permissions (`studio.create`, `studio.blueprint`, `studio.migrate`, `studio.wipe`), and running migrations always needs `studio.migrate`.
  - Code generation, migrations and destructive actions are refused in production unless explicitly allowed.
- **Sign-in and MFA.**
  - Rate limits on sign-in, registration, MFA, device codes and lockscreen unlock.
  - One generic sign-in error, and lockout thresholds taken from the security policy.
  - A password alone can no longer read an enrolled TOTP secret or enrol a new passkey, and the pending MFA state expires after 5 minutes.
  - TOTP codes cannot be replayed.
  - Suspended or locked users cannot sign in with a passkey.
  - Recovery codes are stored hashed and shown once; TOTP secrets are encrypted at rest.
  - QR codes are no longer generated by api.qrserver.com, which received the TOTP secret.
  - API sign-in enforces the same lockout and MFA policy and issues Sanctum tokens.
  - Open API registration is off by default.
- **Code generation.**
  - Labels, defaults, navigation URLs and relationship definitions can no longer inject PHP or Blade into generated files.
  - `SliceNamespace` no longer cuts namespaces such as `App\Admin` to `App\Admi`.
- **Cross-site scripting.**
  - Copilot replies are HTML-escaped before markdown formatting.
  - The MFA recovery console escapes device and passkey names.
  - The login page passes the previous email to Alpine safely.
- **Destructive operations.** Core slices (Users, Roles, Settings, Auth) can no longer be destroyed or wiped.

### Added
- `laravel/sanctum` dependency; the slice `User` model uses `HasApiTokens`. `slice:install` publishes Sanctum's migration when needed.
- `LaraSlice\Core\Security\Access`, the single super-admin and permission check.
- `TotpService`, `RecoveryCodeService`, `QrCodeService` (inline SVG when the suggested `bacon/bacon-qr-code` package is installed) and `LoginAttemptService`.
- Config keys:
  - `wizard.allow_in_production`
  - `auth.api_registration`
  - `ai.providers.*` (keys and models from `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, …)
  - `ai.protected_tables`
- A Testbench test suite (`phpunit.xml.dist`) with feature tests for route authentication, authorization, the copilot, sign-in/MFA, generator input and destructive operations.

### Changed
- `slice:install` generates a random super-admin password unless `--password` is given. It no longer resets the password of an existing account.
- `slice:seed` asks for confirmation in production. `slice:ui:prune` and `slice:field` default to "no" at their prompts.
- New passkeys are created as discoverable credentials, and passkey sign-in requires user verification.
- Recovery codes are now 10 characters (`XXXXX-XXXXX`).

### Fixed
- `php artisan route:cache` failed because `settings.ai` was registered twice.
- BlatUI views failed with "Unresolvable dependency … TailwindMerge" when a second application booted in the same process (tests, Octane).
- Editing a user without entering a new password cleared the stored password.
- Copilot code blocks rendered as broken inline code.

### Upgrading
1. Merge the new keys from `config/laraslice.php` into your published config (or re-publish it). Removed keys: `ai.enabled`, `ai.default_provider`, `ai.api_key`, `ai.model`, `ai.mcp_server.tools_enabled`, `wizard.route`.
2. Run `php artisan vendor:publish --tag=sanctum-migrations && php artisan migrate` (or re-run `php artisan slice:install`).
3. Make sure your administrator account holds the `super-admin` role. The `admin@laraslice.com` email no longer grants it.
4. Give other roles the permissions they need. Users who are not super-admins are now refused anything they lack a permission for, including the AI copilot (`ai.copilot.use`). To sync permissions for generated slices, open any role's create or edit page under **Admin → Roles**.
5. In development, set `LARASLICE_WIZARD_ENABLED=true` to use Slice Studio. To use the MCP HTTP endpoint, set `LARASLICE_MCP_ENABLED=true` and send a Sanctum token. Set `LARASLICE_API_REGISTRATION=true` only if you want open sign-up.
6. Existing recovery codes keep working once each; regenerate them to switch to hashed storage. Existing TOTP secrets are encrypted the next time they are saved. Rotating `APP_KEY` makes encrypted TOTP secrets and saved provider keys unreadable.
7. Hardware security keys without a PIN or biometric need one configured, because passkey sign-in now requires user verification.
8. The local demo login (`admin@laraslice.com` / `password`) only works if you install with `php artisan slice:install --password=password`.

## [1.3.3] - 2026-10-06

### Changed
- **BlatUI data-table on every listing**: Generated slice and child-entity index pages, the parent-form "Associated records" panel, and the Users directory, devices, MFA register, security logs and access metrics now use `<x-ui.data-table>` with client-side search, sorting, row selection, paging and row actions. Cells are plain text; dates are shown as `Y-m-d H:i` so they sort chronologically.
- The MFA register's method/status filters are replaced by the data-table search (search "passkey" or "needs enrollment").
- Security log and metrics lists load up to the data-table cap instead of a fixed 100 rows.

### Added
- `laraslice.data_table.max_rows` config (`LARASLICE_DATA_TABLE_MAX_ROWS`, default 1000) and `BaseFilter::withLimit()`; index pages load up to that many rows and show a notice when a table holds more.
- `LaraSlice\Support\DataTableRows` helper for building data-table rows.
- Generated index views keep columns in one array ending with `// @laraslice:columns`, where Slice Studio appends new fields.

### Fixed
- Generated index pages showed only the first 20 records with no way to page through the rest.

### Upgrading
Existing generated slices keep their old index view; regenerate it from Slice Studio (or re-run the generator) to get the data-table. Rebuild assets afterwards (`npm run build`).

## [1.3.2] - 2026-10-06

### Fixed
- **Seed Demo Data `Data truncated for column 'updated_by'`**: The demo seeder treated any column containing "date" as a date, including `updated_by`. Date columns are now matched by whole word (`close_date`, `date_of_birth`).
- **Orphaned userstamps in demo data**: `created_by` and `updated_by` are now filled with the authenticated user (or the first existing user) instead of made-up ids; `deleted_by` stays null.
- **Boolean guessing**: `is_` / `has_` columns are matched as prefixes only, so names like `analysis_notes` keep text values.

## [1.3.1] - 2026-10-05

### Changed
- **Admin sidebar rebuilt on BlatUI sidebar-02**: The starter layout's sidebar now uses collapsible navigation groups with a version switcher and search form in the header. Groups are Workspace, one per domain, Vertical Slices (ungrouped app slices), and Administration (core slices). Slices with child pages render as dropdowns that open automatically on their active page, and every item shows the Lucide icon from its slice navigation settings. Long labels truncate instead of overlapping.

### Added
- `block/version-switcher` and `block/search-form` BlatUI components, published by `slice:install`.
- `core` flag on items returned by `SliceManager::getNavigableSlices()`.

### Removed
- Duplicate "AI Copilot & Settings" sidebar link (available under Settings & AI).

## [1.2.5] - 2026-10-05

### Fixed
- **Slice Studio "Generation failed: 0 slice(s) successfully generated"**: Escaped `$table` in the `SliceGenerator` migration template. The unescaped variable raised a PHP warning that failed every slice during web requests and produced migrations with invalid PHP elsewhere. This also fixes the `BlueprintApplierTest` failures.
- **Hidden generation errors**: Slice Studio's domain suite generation now logs each failing slice's exception and includes the slice name and error in the response message instead of only the success count.

## [1.2.4] - 2026-10-05
*Special thanks to community contributor **Muhammad Adnan** ([@adnanansari901](https://github.com/adnanansari901)) for contributing the v1.2.4 security, passkey, and installation enhancements!*


### Fixed
- **Passkeys on HTTP dev domains**: Passkey flows (login, MFA enroll/challenge, lockscreen, settings) now detect an insecure context and ask for HTTPS or `localhost` instead of wrongly reporting "WebAuthn / Passkeys are not supported by this browser".
- **`Unknown column 'session_id'` on device enrollment**: Added migration `2026_10_05_000000_add_device_trust_columns_to_user_devices_table` to backfill `session_id`, `device_label`, and `is_trusted` on `user_devices`, which the v1.3.0 schema skipped when the v1.1.0 table already existed.
- **Settings page TypeError with `App\Models\User`**: `UserWebController` now resolves the host app's authenticated user to the slice `User` model.
- **`Call to undefined method hasRole()`**: `slice:install` now injects `HasSlicePermissions` into the app's User model whatever traits it already uses (e.g. `use HasApiTokens, HasFactory, Notifiable;`).
- **`ComponentAttributeBag::twMerge does not exist`**: Registered the `twMerge` attribute macro used by the published BlatUI components, backed by the new `gehrisandro/tailwind-merge-php` dependency. An app-provided `twMerge` macro takes precedence.
- **`[postcss] ENOENT ... open 'tailwindcss'` on `npm run dev`**: `slice:install` now adds `tailwindcss` and `@tailwindcss/vite` v4 to `package.json`, registers the Tailwind plugin in `vite.config.js`, and runs `npm install` when any required package is missing. The starter `blatui.css` now scans `vendor/hereafter/laraslice` instead of a non-existent vendor path and a hard-coded local `E:/` path.

## [1.2.3] - 2026-03-31

### Fixed
- Removed non-standard `bps_scale` reference and fragile `->after(...)` clauses in `enhance_users_table_and_security_devices` migration that caused column not found errors during `php artisan slice:install` on fresh databases.

## [1.2.2] - 2026-03-31

### Fixed
- Registered `SliceInstallCommand` in `LaraSliceServiceProvider` so `php artisan slice:install` is immediately available in downstream Laravel applications.
- Registered `laraslice-config` and `laraslice-starter` vendor publish tags.

### Added
- Added `skills.sh.json` and official skills.sh directory badge to README.

## [1.2.1] - 2026-10-05

### Fixed
- **Packagist Upstream Re-Tag Resolution**: Incremented release tag to `v1.2.1` to comply with Packagist immutability requirements.
- **Passkey Revocation 404**: Multi-verb routing (`DELETE`, `POST`, `GET`) with safe model deletion and redirect to `#mfa`.

## [1.2.0] - 2026-10-05

### Added
- **Visual Slice Studio (`/laraslice/wizard/studio`)**: Visual orchestrator for vertical slices, aggregate entities, and schema management with live bidirectional event bus (`laraslice-slice-selected`).
- **Real-Time MySQL Column Introspection**: AI Copilot inspects exact database column schemas via native `SHOW COLUMNS FROM {table}` queries, returning accurate field names, types, nullability, keys, and defaults.
- **Conversational 1-Click Schema Migrations**: Natural language column additions in Copilot (`add emergency_contact in user_details`) with inferred SQL types, safe non-breaking nullability, enterprise suggestion chips, and instant execution.
- **Dynamic Context-Aware Slash (`/`) Commands**: Intelligent `/fields [table]`, `/add-field [table]`, `/relations`, `/permissions`, `/nav`, `/logs`, `/suggest-fields`, `/seed`, and `/wipe` commands.
- **Enterprise User Aggregate (15 Tables)**: Primary `users` root table coordinating 14 child entities (`user_details`, `user_devices`, `user_passkeys`, `user_security_logs`, etc.).
- **FIDO2 / WebAuthn Biometric Passkeys**: Complete passwordless enrollment, session lockscreen unlock, and safe revocation handling with multi-verb route dispatching.
- **Live DOM Screen Context**: Copilot automatically inspects visible page inputs and the 4 Quick Starter Domain Suites (CRM, E-Commerce, Billing, Helpdesk).
- **Public Changelog Page**: GitHub Pages changelog at `https://hereaftersol.github.io/laraslice/changelog.html`.

### Fixed
- **Passkey Revocation 404**: Resolved route matching issue by supporting multi-verb dispatch (DELETE/POST/GET) and adding graceful fallback redirection back to `#mfa`.
- **Field Migration Button Visibility**: Corrected status assignment in Alpine Copilot so field migration action cards render completely.

---

## [1.1.0] - 2026-09-20

### Added
- **Declarative Blueprint Studio**: Visual schema and relationship designer with live directory preview.
- **Multi-Table Aggregate Root Architecture**: Parent-child entity hierarchy with transaction-safe cascading updates.
- **Cross-Slice Contracts & DTOs**: Decoupled slice communication standards ensuring zero tight coupling between bounded contexts.
- **Dual-Layer Audit Engine**: Intrinsic user lifecycle stamps paired with immutable system event logs and automated retention pruning (`slice:audit:prune`).

---

## [1.0.0] - 2026-08-15

### Added
- Initial release of **LaraSlice** — Autonomous Vertical Slice Architecture framework for Laravel 11, 12, and 13+.
- **Three-Layer Packaging Model (ADR-001)**: Core Engine, Starter Slices, and Project Slices.
- **Pure Blade + Alpine.js + Tailwind CSS v4 + BlatUI**: Zero Livewire or Metronic bloat; ultra-fast page loads.
- **Artisan CLI Generators**: `slice:make`, `slice:seed`, `slice:toggle`, and `slice:wizard`.
- **Flutter & Mobile API Scaffolding**: Unified generation of typed web views and mobile REST API contracts.