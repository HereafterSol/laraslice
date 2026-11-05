---
name: laraslice
description: Architectural standards, CLI commands, declarative schemas, multi-table aggregate patterns, cross-slice communication, and AI workflows for LaraSlice applications. Use when building, extending, or reviewing a LaraSlice (Laravel) app. The core skill of the family — load `laraslice-start` for new projects, `laraslice-port` for migrations.
---

# LaraSlice Framework & AI Assistant Skill

> **Release baseline:** Prefer **`hereafter/laraslice` v2.0.1+** ([Verification Patch](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.1)) for ports and replicas. Use `--import-auth` on data import, run `slice:drift` (zero errors) before parity claims, and maintain `porting/REPLICA-STATUS.md` per §22 (lowest gate = status). **Do not confuse framework architecture with a finished replica:** `docs/ARCHITECTURE-VS-PORT-TRUTH.md`. **Vision & benchmark crucible:** `docs/VISION.md`. **Naming:** `docs/NAMING.md` (no competitor product names in public docs). Framework backlog: `docs/BACKLOG.md` in the package repo.

### Port 100% programme & Studio (owners + agents)

- **Ordered playbook:** `docs/PORT-100-PLAYBOOK.md` — Analyze → Phase 0 composition → Phase D nav → Gates S/D/F/T/V → Studio handoff; replica **100%** = lowest gate PASS on signed scope; greenfield branch in the same doc (Skill §24).
- **Architect port mode (page clone vs Heart/Body/Kit):** `docs/PORTING-DECISION-100-REPLICA.md` — decision tree, gate tradeoffs, SDLC checklist; **never** claim 100% from Studio alone.
- **AI token budget (read order):** `docs/FOUNDATION-EXECUTION-TRACKER.md` → `docs/PROGRAMME-STATUS-2026-11-05.md` (or latest programme status) → `docs/ENTERPRISE-UX-STANDARD.md` → `docs/PORT-100-PLAYBOOK.md` → host `porting/REPLICA-STATUS.md`; details in `docs/AI-FOUNDATION-OPTIMIZATION.md`.
- **Reference index:** `docs/SHARED-REFERENCES.md` · maturity pillars: `docs/FOUNDATION-MATURITY-ROADMAP.md`.
- **Non-technical users:** Plan mode Mind is the requirements contract (plain language, human sign-off; drafts never self-promoted). Operate mode is run-state (health, access review, operator manual). Do not quote replica % from chat — point to `REPLICA-STATUS.md` (ports) or release checklist (greenfield). Standard pages stay YAML + Studio — not ad-hoc Blade tours.
- **JEV honesty:** Internal UI synthesis is Body→kit (`docs/KIT-CONTRACT.md`), not the TypeSafe Jev product API; optional TypeSafe closed-choice helper is backlog-only and separate from `JevDecisionService` (see `docs/BACKLOG.md` UI/JEV items).

This skill provides comprehensive instructions for AI agents and developers working on **LaraSlice** applications. It covers the Three-Layer Packaging Model (ADR-001), Vertical Slice Architecture, Pure BlatUI tokens, Declarative Schema Builder, multi-table aggregate patterns, cross-slice communication, and AI-assisted workflows.

---

## START HERE — pick your door (read this first)

This document is a reference, not a novel: thousands of lines,
most of them for porting and parity work. You only need one
door today.

- **Starting a NEW project?** Load the sibling skill
  **`laraslice-start`**: its §28 (the pre-build dossier — six
  named slots, one page) and §24 (the two doors) are the whole
  on-ramp. **The one first step, every time: the Mind before
  the first slice** — purpose, actors, journeys, success
  metrics in `system.yaml`, signed off by a human. Everything
  else is reference you grow into, section by section, as the
  work demands it.
- **Running a system that already launched?** Load the
  sibling skill **`laraslice-operate`**: customize, optimize,
  improve, operate, upgrade — the whole SDLC tail, from the
  CLI, the Studio, or an AI assistant.
- **Porting an existing system?** Load the sibling skill
  **`laraslice-port`**: the porter (§12/§16), composition
  (§16.5.1), and the Replica Protocol (§22 — gates in plain
  names: Structure · Data · Furniture · Tasks · Visual).
- **Looking something up?** §23 is the concepts map — every term
  defined in one line. §7 is the command catalogue.
- **Arriving from a spec-driven toolkit?** §27 names the shared
  six-step path.

---

## 1. Architectural Principles & Packaging (ADR-001)

1. **Three-Layer Packaging Model**:
   - **Layer 1: Core Engine (`vendor/hereafter/laraslice`)**: Shared lean engine containing base classes, slice discovery & caching (`slice:cache`), audit engine, RBAC gate bridge, declarative schema, and CLI generators. Zero vendor lock-in, zero runtime network/phone-home calls.
   - **Layer 2: Application Shell (shipped in the package)**: The BlatUI shell, layouts, theme tokens, and default starter slices (`Auth`, `Users`, `Roles`, `Settings`, `AuditViewer`), published into the app by `slice:install` and owned by the team from that moment. (ADR-001's original text described a separately cloned starter repository; that flow is superseded — the shell ships inside the package and §24 is the install truth. The ADR file remains as decision history.)
   - **Layer 3: Project Slices (`app/Slices/{SliceName}/`)**: Client domain features (e.g. `Invoices`, `HR`, `Procurement`). Generated using the generation gap pattern: generated base classes are safe to regenerate, while hand-written subclasses, contracts, and business logic are never overwritten.

2. **Vertical Slice Architecture (VSA)**:
   - Slices are **Domain Bounded Contexts**, NOT database tables. A single slice can own multiple aggregate tables.
   - Each slice owns its own `Models/`, `Migrations/`, `Controllers/`, `Services/`, `Contracts/` (Business Objects / DTOs), `Routes/` (`web.php`, `api.php`), `Resources/views/`, and `Schemas/`.
   - Manifest: `slice.yaml` is the version-controlled single source of truth for metadata, capabilities, navigation, and relations.

3. **No Livewire / No Flux / No legacy admin-kit JS**:
   - The stack is strictly **Pure Blade + Alpine.js + Tailwind CSS v4**.
   - Atomic UI elements use **BlatUI** (`<x-ui.card>`, `<x-ui.table>`, `<x-ui.input>`, `<x-ui.badge>`, `<x-ui.button>`, `<x-ui.label>`).
   - The application layout uses the pure BlatUI Dashboard-01 responsive shell (`layouts.app` / `<x-layouts.app>`). Pure Lucide icons (`<x-lucide-*>` and dynamic Lucide components). Prohibited: legacy kit scripts (`ktui.min.js`, Keenicons, `KTDrawer`, and similar vendor drawer stacks).

4. **Design Tokens & Theme Consistency**:
   - Always use semantic CSS variables mapped to Tailwind utilities: `bg-background`, `bg-card`, `border-border`, `text-foreground`, `text-muted-foreground`, `text-primary`.
   - Never hardcode dark hex colors. Theme switches dynamically via Alpine.js `$store.theme` and `data-theme` tokens.

---

## 2. Declarative Schema Builder (`LaraSlice\Schema`)

> **Fields — the one story (read this before §2, §7 or §17
> contradict it in your head).** A slice's fields are declared
> in exactly ONE place: the Heart, `slice.yaml` (§23: the sole
> runtime truth). Everything else is a pen or a printout:
> - `slice:make --field=name:type` and `slice:field` are PENS —
>   they write the fields into `slice.yaml` for you.
> - `app/Slices/{SliceName}/Schemas/{SliceName}Schema.php` is a
>   PRINTOUT — the generator emits it FROM the Heart. Read it,
>   extend it in a subclass (generation gap, §1), never treat it
>   as the source: regeneration overwrites the generated base.
> - This section (§2) documents the printout's PHP API — the
>   typed contract the generated code and your subclasses use.

Every generated slice carries a declarative schema class in `app/Slices/{SliceName}/Schemas/{SliceName}Schema.php` extending `LaraSlice\Schema\SliceSchema` — emitted from the Heart's `fields:` by the generator.

### Defining Form Fields & Table Columns
```php
namespace App\Slices\Products\Schemas;

use LaraSlice\Schema\SliceSchema;
use LaraSlice\Schema\Field;
use LaraSlice\Schema\Column;
use LaraSlice\Schema\Relation;

class ProductSchema extends SliceSchema
{
    public static function fields(): array
    {
        return [
            Field::text('title')->label('Product Title')->required()->placeholder('e.g. MacBook Pro'),
            Field::decimal('price')->label('Price ($)')->prefix('$')->placeholder('0.00'),
            Field::text('sku')->label('SKU / Barcode')->placeholder('e.g. MBP-001'),
            Field::number('stock')->label('Inventory Stock')->default(0),
            Field::textarea('description')->label('Description'),
            Field::select('status', [
                'draft'    => 'Draft',
                'active'   => 'Active',
                'archived' => 'Archived',
            ])->label('Status')->default('draft'),
        ];
    }

    public static function columns(): array
    {
        return [
            Column::text('id')->label('ID'),
            Column::text('title')->label('Title')->searchable()->sortable(),
            Column::badge('status')->label('Status'),
            Column::money('price', '$')->label('Price'),
            Column::text('sku')->label('SKU'),
            Column::text('stock')->label('Stock'),
        ];
    }

    public static function relations(): array
    {
        return [
            Relation::hasMany('variants', ProductVariantSchema::class)
                ->label('Product Variants')
                ->foreignKey('product_id')
                ->icon('layers'),
        ];
    }
}
```

### Exporting for Mobile & AI
Call `ProductSchema::toJson()` or `toArray()` to produce structured schema metadata. This is consumed by:
- Flutter mobile code generators for forms and state classes (`php artisan slice:export:flutter {Slice}`).
- AI agents to understand the aggregate structure and auto-generate views/migrations.

---

## 3. Cross-Slice Communication Standards & Boundary Enforcement

When Slice A needs data from Slice B (e.g. `Invoices` needs `Users`):

1. **Strict Boundary Enforcement (Tested in CI)**:
   - Slices must **NEVER** directly import another slice's internal Eloquent models (`use App\Slices\Users\Models\User` is forbidden in `Invoices`).
   - This rule is automatically verified by automated architectural boundary tests (`tests/Architecture/SliceBoundaryTest.php`).

2. **Typed Contracts & Business Objects (DTOs)**:
   - Slices expose public readonly DTOs under `Contracts/` (e.g. `UserSummaryBusinessObject`).
   - Slices inject domain services (e.g., `UserSliceService`) rather than executing raw SQL or cross-slice model queries:
   ```php
   class InvoiceSliceService
   {
       public function __construct(
           private readonly UserSliceService $userService
       ) {}

       public function createInvoice(InvoiceFormBusinessObject $dto): Invoice
       {
           $customer = $this->userService->getPublicSummary($dto->customerId);
           ...
       }
   }
   ```

3. **Typed Domain Events (`afterCommit`)**:
   - Decoupled asynchronous side-effects use typed Laravel events dispatched after database transaction commits:
   ```php
   Event::dispatch(new InvoicePaidEvent($invoiceSummary));
   ```

4. **Manifest Dependencies**:
   - In `app/Slices/Invoices/slice.yaml`:
   ```yaml
   name: Invoices
   version: 1.0.0
   dependencies:
     - Users
     - Settings
   ```

---

## 4. Multi-Table Slices vs Domain Suites (summary)

A slice is a bounded context, not a table: one slice may own
several aggregate tables (header + lines + totals), and a
domain suite groups sibling slices that share a menu and a
vocabulary. The full architecture — aggregate vs child vs
sibling decisions, worked shapes, and the boundary law — lives
in `references/multi-table-architecture.md` and is load-bearing
reading before modelling any multi-table slice.

## 5. Enterprise Transactional Integrity & Audit Logging

For mission-critical ERP, banking, and government applications, models and actions maintain regulatory compliance trails:

1. **Plug-and-Play Model Auditing (`AuditableSlice`)**:
   ```php
   namespace App\Slices\Products\Models;

   use Illuminate\Database\Eloquent\Model;
   use LaraSlice\Core\Audit\Traits\AuditableSlice;

   class Product extends Model
   {
       use AuditableSlice;

       // Sensitive attributes (passwords, tokens) are automatically redacted
       protected array $auditExclude = ['internal_cost'];
   }
   ```
   - Automatically records `created`, `updated`, and `deleted` actions in `laraslice_audit_logs`.
   - Captures actor email, ID, IP address, user-agent, and before/after state diffs.
   - Inspectable live via the Slice Studio Audit & Activity Viewer.

2. **Transactional Mutations (`TransactionalAction`)**:
   ```php
   namespace App\Slices\Payroll\Actions;

   use LaraSlice\Core\Actions\TransactionalAction;

   class ProcessSalaryAction extends TransactionalAction
   {
       protected string $slice = 'Payroll';

       protected function handle(SalaryDto $dto): SalaryRecord
       {
           // Automatically executed inside a DB::transaction
           return SalaryRecord::create($dto->toArray());
       }
   }
   ```

---

## 5.5 Standard UI/UX (core foundation — study before generating any UI)

The standard UI is the parity vehicle: slices that use only the
standard patterns match a mature ERP's density and interactions by
default. NEVER invent per-slice layouts or CSS for standard screens.
Full standard: `docs/UI_UX_STANDARD.md`.

**Density tokens:** base font 14px; page title 16px/500; buttons
and inputs ~39px; card radius 4px; table rows 42–61px (generated
lists use the data-table's compact density); page padding ~24px;
full-width content. Status colors are semantic and global (red
overdue/incomplete, amber to-do, blue doing, green done/paid).
People render as avatars, never bare ids.

**The nine standard patterns** (declare them; do not hand-build):
P1 List (attached filter strip, view switchers, one card =
table + pagination, inline status select, avatar stacks, kebab
row actions) · P2 Detail hub (tab bar under the header,
`ui.show.tabs`/`ui.show.stats`/`ui.show.widgets` — gauge =
filtered aggregate over a max, chart = grouped relation
counts with declared labels/order) · P3 Form
(one sectioned card, labels above inputs, quick-add selects,
in-card save bar) · P4 Dashboard (`ui.dashboard.widgets`
per slice — counter/list/week/chart — aggregated into the
standard dashboard; designed empty state) · P5 Report, P6 Document, P7 Matrix
(G2 declarations: `ui.directory.report`, `ui.show.document`,
`ui.directory.matrix`) · P8 Views (`ui.directory.views`
list/board/calendar) · P9 Settings hub (every feature's
settings in ONE searchable hub, declared via `settings:` in
the slice manifest and aggregated by the framework; a
section may declare `bind: {table: …}` to read/write a
single-row settings table's columns — the ported-system
form, where `*_settings` tables become hub forms instead
of CRUD directories; unbound sections use the core
key-value store) · P10
Bulk entry (`ui.directory.bulk_entry` — one-date grid over
the row key's relationship options with per-row fields, at
`{index}/bulk-entry`; the Mark Attendance pattern. `range: true`
plus `holidays {slice, date_field}` extends it to bulk-mark
across a date range, skipping holidays — and weekends with
`skip_weekends: true` — as declared) · P11
Record timer (`ui.show.timer` — standard timer card on the
detail page writing session rows via a hasMany relation;
one active timer per user across the log table, a second
start refused; stop computes `total_hours`/`total_minutes`
when the log table carries them).

**Mind · Body · Heart.** Every system is three files: the
Mind (`system.yaml` — purpose, journeys, roles as permission
clubs with per-person overrides, navigation, flows,
integrations, operations), the Body (`*.ui.yaml` per slice —
the page-level UI spec), and the Heart (`slice.yaml` — the
only file that runs). Full guide: `docs/MIND_BODY_HEART.md`.
The Mind and Body are checked against the Heart; the soul
(design identity) is declared in the Mind, never a fourth file.

**The Body's two doors** (one file, two origins): on a NEW
project you design the Body up front — it is the page-level
spec written in Plan/Design mode that the generator works
from. On a PORT, the Body is extracted from the source's
rendered pages instead. Either way the Body is a spec the
Heart is checked against; the Heart alone runs at runtime.

**The fidelity stage (porting).** A port is functional by
default; REPLICATION is a separate stage. Flow: (1) EXTRACT the
source app's rendered pages (toolbar actions, filter selects
WITH their default values, inputs, tabs, table columns) into a
per-slice **UI spec** (`porting/ui-spec/<slice>.ui.yaml` — a
porting-time artifact, never loaded at runtime); (2) give every
spec item a decision — declare / standard-replaces / skip /
accepted-difference; (3) TRANSLATE decisions into the manifest
(the single runtime heart — the spec never duplicates it);
(4) re-extract and diff as the verifier. Fidelity vocabulary:
filter `default:` on quick/sidebar filters (applies only when
the request never mentions the handle; `!value` excludes;
`$user` = current user; the All tab clears via the `__all__`
sentinel); declared quick-filter `options:` (value/label) beat
`options_from: distinct:`; `ui.directory.actions[]` — context
buttons beside Add (`kind: filter` with criteria, `kind: route`,
`kind: url`); `type: relation_exists` sidebar filters
(`relation` + `key`) for pivot-backed dimensions like assignees
(criteria-only with `ui: false`, or a drawer select when the
relation is `users`); column `label_table`/`label_column` when
the label table breaks the FK naming convention; the `date`
formatter. Do NOT add summary cards the source list does not
have, and match the source's column set — extra columns are
deltas too. Declared patterns that close the last list gaps: **P13
import** (`ui.directory.import` — CSV header→field map, optional
upsert `key`; standard import page + template download),
**P14 invite** (`ui.directory.invite` + an `actions[]` entry of
kind `invite` — provisions users for records whose link field is
empty: account + role club + activation email), **P15 reference
actions** (`ui.directory.reference_sets` + `actions[]` kind
`seed` — idempotent upserts from the slice's Resources/data
file, e.g. Mark Default Holidays), **P16 cross-slice create**
(`actions[]` kind `route` with `params_from_filter` — carries
the current filter into another slice's create page, e.g.
Create Timelog Invoice). A range may declare `placement: strip`
(renders in the list's top strip, not the drawer) and a
`columns` + `column_param` + `default_column` triple — the
date_filter_on choice: the request picks WHICH date column the
range applies to. A column may declare `formatter: timer`
(driven by BO-exposed timer URLs/state) and `always_visible:
true` (the wide-table column discipline never hides it). The
filters drawer itself is the package partial
`laraslice::directory.filters-drawer` — one maintenance point
for every slice. More vocabulary: range filters declare
`default_from`/`default_to` (applied while neither range key
is in the request) and render as `number_range` or
`date_range` in the drawer; the bulk-entry toolbar button
takes its label from the declaration's `title` (Mark
Attendance); a calendar-default list renders its month grid
even with zero records instead of the empty state.

**Components:** BlatUI first — see `docs/UI-COMPONENT-MAPPING.md`
(`data-table`, `stat`, `tabs`, `chart`, `kanban`, `gantt`,
`scheduler`, `repeater`, `rich-text-editor`, `avatar-group`,
`status-pill`, `filter-tabs`, `empty`, `drawer`). Foundation
additions in progress: gauge, status-select, widget-card,
week-strip, filter-strip, save-bar, kebab-actions, section-card,
tab-bar, view-switcher. Rollout phases S-UI 1–5; verify every
generated screen side-by-side against the reference application
before calling it done.

## 6. Layout Guidelines (`layouts.app`)

All administrative slice views extend `layouts.app` / `<x-layouts.app>` (Dashboard-01 BlatUI layout):
```blade
@extends('layouts.app')

@section('content')
<div class="max-w-[1600px] mx-auto space-y-6">
    <!-- Breadcrumbs -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('dashboard') }}" class="hover:text-primary">Dashboard</a>
        <span>/</span>
        <span class="text-foreground font-semibold">Products</span>
    </div>

    <!-- UI Cards with BlatUI -->
    <x-ui.card class="bg-card border border-border p-6 rounded-2xl shadow-xs">
        <x-ui.table>
            ...
        </x-ui.table>
    </x-ui.card>
</div>
@endsection
```

- Layout shell uses fixed `lg:ps-[280px]` offset on desktop and Alpine.js slide-over drawer on mobile.
- Zero Metronic JavaScript. Modals and drawers are powered purely by Alpine.js.

---

## 7. Working with CLI Commands

LaraSlice provides a comprehensive, standardized suite of console commands across the `slice:*` and `laraslice:*` namespaces:

### Discovery, Blueprint & Scaffolding
- `php artisan slice:list {--domain=}`: Display all registered slices with Domain, Models, Version, and Status.
- `php artisan slice:make <SliceName> [<SliceName2> ...] {--domain=} {--workflow} {--field=*} {--flutter}`: Scaffold complete end-to-end vertical slices.
- `php artisan slice:field <SliceName> [field]`: Enhance an existing slice with new field(s) in a single consolidated migration (October CMS Builder style). It writes the fields into the slice's Heart (`slice.yaml`) — fields have one home; see §2's "Fields — the one story".
- `php artisan slice:ai "<prompt>" {--flutter}`: Natural language AI generator that automatically infers slice models, fields, and workflows.
- `php artisan slice:blueprint:validate <path>`: Validate versioned YAML/JSON blueprints.
- `php artisan slice:blueprint:plan <path> {--format=table|json}`: Show deterministic schema and file plan before writing code.
- `php artisan slice:blueprint:apply <path> {--plan-hash=} {--yes}`: Apply a reviewed blueprint with SHA-256 plan hash verification.
- `php artisan slice:export:flutter <SliceName>`: Scaffold native Flutter models, typed HTTP client, and responsive CRUD views.

### Lifecycle, Data & Studio Operations
- `php artisan slice:seed {slice?} {--domain=} {--count=10}`: Seed realistic relational demo data for an individual slice or entire domain.
- `php artisan slice:toggle {slice?} {--domain=} {--enable} {--disable}`: Toggle activation status (shows or hides in navigation, enables/suspends routes).
- `php artisan slice:wipe {slice?} {--domain=} {--force}`: Safely truncate/wipe all records from tables of a slice or domain while preserving code files.
- `php artisan slice:destroy {slice?} {--domain=} {--mode=complete} {--force}`: Safely tear down a slice or domain (`complete`, `code_only`, `db_only`, `wipe_data`).
- `php artisan slice:ui:prune {--force} {--dry-run}`: Identify and prune unused BlatUI components to keep assets lean.
- `php artisan slice:wizard {--web}`: Interactive console wizard or one-click web browser launcher for Slice Studio.
- `php artisan slice:sync <SliceName>`: Detect database schema drift and synchronize fields back to `slice.yaml`.
- `php artisan slice:cache` / `slice:clear`: Cache or clear slice discovery manifests for production boot optimization. Paths are stored **relative to `base_path()`** so a copied port tree does not keep booting slices from another install's absolute paths. After **any** filesystem clone of an app, run `slice:clear` then `slice:cache` (or `slice:repair`, which clears cache). Legacy absolute caches rebase via `app/Slices` / vendor slice suffixes when possible. Parsed manifest data is cached with the same keys (mtime+size freshness).
- `php artisan slice:publish {slice?} {--all} {--force}`: Publish core slices into `app/Slices/` for full customization.

### Enterprise Governance & AI Commands
- `php artisan laraslice:audit:prune {--days=90} {--slice=} {--force}`: Prune old enterprise audit log records dynamically using `settings` table retention.
- `php artisan laraslice:mcp {--transport=stdio|sse} {--test}`: Run the LaraSlice Model Context Protocol (MCP) server for Cursor, Antigravity, and Claude AI agents.
- `php artisan laraslice:skill:publish {--force}`: Publish LaraSlice AI skills to `.agents/skills/laraslice/SKILL.md`, `.cursor/rules/laraslice.mdc`, and `.cursor/mcp.json`.

---

## 8. Declarative Blueprint Studio & Visual Designer

Every vertical slice maintains a version-controlled `app/Slices/{SliceName}/slice.yaml` single source of truth.
- **Blueprint Studio UI**: Available at `/laraslice/wizard/blueprint`.
- **ONE Studio (canonical developer surface)**: All studios live in ONE shell at `/laraslice/studio` with modes `?mode=port|design|create|manage` (Schema Studio is the Design sub-view `&view=schema`). Legacy URLs (`/laraslice/wizard`, `/wizard/studio`, `/wizard/blueprint`, `/wizard/porting`, `/wizard/schema-studio`) redirect into the shell. The Studio Home (no `?mode`) is the front door: BRD/AI brief, port a legacy app, design a domain, or manage existing slices. Panels are lazy-mounted; POST endpoints are unchanged.
- **Modes**:
  - `🎨 Visual`: Interactive entity modeler with Statamic field widths (`33%`, `50%`, `100%`).
  - `👁️ Preview`: Real-time interactive BlatUI component and data table mockup.
  - `⚡ Split`: Side-by-side bidirectional visual and YAML live editing.
  - `📝 YAML`: Syntax-highlighted declarative source.
  - `✨ Add from DB`: Database table introspection.

---

## 9. Core Customization & Upstream Updates Strategy

### How to Customize a Core Slice in Your Project
LaraSlice's discovery system is explicitly designed so that **any slice in `app/Slices/` overrides the core slice of the same name**.
To customize a core slice:
```bash
php artisan slice:publish Users
```
*(or `php artisan slice:publish --all`)*

This copies the core slice from the framework into `app/Slices/Users`. Once published, it becomes first-class code in your repository: you can edit models, controllers, DTO contracts, and views, and commit them to git. Files in `vendor/` are untouched, and Composer updates will **never** overwrite your custom code.

### How Developers Contribute to Core Slices

#### 1. Open Source Contributions to Framework Core
1. **Fork the Repository**: [github.com/HereafterSol/laraslice](https://github.com/HereafterSol/laraslice)
2. **Edit the Core Slice**: Make enhancements directly in `src/Slices/{SliceName}` (e.g., `src/Slices/Users`, `src/Slices/Roles`, `src/Slices/Settings`).
3. **Run the Test Suite**:
   ```bash
   ./vendor/bin/phpunit tests
   ```
4. **Open a Pull Request**: Submit your PR on GitHub against the `master` branch.

#### 2. Local Development / Package Development
To test framework edits in real time in a host Laravel test project:
In the test project's `composer.json`, add a local path repository:
```json
"repositories": [
    {
        "type": "path",
        "url": "../LaraSlice",
        "options": { "symlink": true }
    }
]
```
Then run `composer update hereafter/laraslice`. This creates a symlink/junction, allowing developers to test their framework edits in real time before pushing to GitHub.

---

## 10. Enterprise Dual-Layer Auditability, Full Lifecycle Stamps & Device Security

LaraSlice enforces regulatory compliance (SOX, GDPR, ISO 27001) using a **Two-Tier Audit Model**:

### 1. Intrinsic Full-Lifecycle Record Stamps (Tier 1)
Every slice migration includes the `$table->auditStamps()` blueprint macro:
```php
$table->auditStamps();
// Expands to:
// - $table->timestamps();         (created_at, updated_at)
// - $table->userstamps();         (created_by, updated_by)
// - $table->softDeletes();        (deleted_at)
// - $table->softUserstamps();     (deleted_by)
```

In slice models, use `AuditableSlice` and `SoftDeletes`:
```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LaraSlice\Core\Audit\Traits\AuditableSlice;

class Invoice extends Model
{
    use AuditableSlice, SoftDeletes;

    protected $fillable = [
        'title',
        'amount',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
```
* **Auto-Stamping**: Automatically stamps `created_by` on creation, `updated_by` on update, and `deleted_by` before soft-delete using `auth()->id()`.
* **Relationships**: Exposes `$model->creator`, `$model->updater`, and `$model->deleter` `BelongsTo` relationships resolving to `App\Models\User`.

### 2. Immutable Event Ledger & Scaling (Tier 2)
The `laraslice_audit_logs` table records granular field diffs (`old_values`, `new_values`), actor ID, email, IP, and User-Agent.

* **Audit Configuration** (`config/laraslice.php`):
  ```php
  'audit' => [
      'enabled'        => env('LARASLICE_AUDIT_ENABLED', true),
      'retention_days' => (int) env('LARASLICE_AUDIT_RETENTION_DAYS', 90),
      'auto_prune'     => env('LARASLICE_AUDIT_AUTO_PRUNE', false), // By default NO logs delete automatically
  ],
  ```
* **Pruning Command**:
  ```bash
  php artisan laraslice:audit:prune             # Retains default retention_days (90)
  php artisan laraslice:audit:prune --days=30    # Custom retention window
  php artisan laraslice:audit:prune --slice=users --force
  ```
* **Slice Studio Audit Search**: The Studio Audit Trail UI (`/laraslice/wizard`) features real-time search, action filters (`All`, `Created`, `Updated`, `Deleted`), date ranges, and on-demand modal pruning.

### 3. Enterprise Identity & Device Trust (Enterprise Architecture Parity)
LaraSlice integrates 10 dedicated security tables for hardware device binding and multi-factor defense:
1. `user_devices`: Active device sessions, platform (`Web`/`App`), browser & OS fingerprinting (`Chrome`, `Edge`, `Safari`, `Firefox`), IP, geolocation, `is_trusted` flag, and persistent `laraslice_device_token` cookie for multi-browser recognition.
2. `user_connect`: **Device Enrollment Codes** (`DEV-XXXXXX`), 15-minute TTL, single-use, admin or self-issued for pairing and authorizing new computers/browsers.
3. `user_creds` & `user_passkeys`: **WebAuthn / FIDO2 Passkeys** credentials, public keys, and authenticator counters.
4. `user_factors`: **TOTP Authenticator App** secrets (encrypted) for Google/Microsoft Authenticator.
5. `user_codes` & `user_recovery_codes`: **Single-use Recovery Backup Codes** (SHA-256 hashed).
6. `user_attempts`: Brute-force tracking, IP & User-Agent forensics, and lockout thresholds.
7. `user_checks`: Ephemeral MFA login challenges and pending step tokens.
8. `user_push_devices`: FCM Push Notification tokens for web and mobile devices.
9. `user_resets`: Password reset tickets with channel (Web vs App), IP, and audit tracking.
10. `user_tokens`: Persistent Remember-Me split tokens (selector + verifier hash).

All relationships are natively exposed on `App\Models\User`:
`$user->devices`, `$user->deviceCodes`, `$user->passkeys`, `$user->totpFactors`, `$user->recoveryCodes`, and `$user->attempts`.

### 4. Enterprise Enrollment & Multi-Browser Flows
* **First-Time / Post-Reset Enrollment (`login.mfa.enroll`)**:
  - Automatically branches by role policy: Privileged users (Admin, Manager, Director) default to **Biometric Passkey (Windows Hello / Touch ID / Hardware Key)** enrollment to physically bind the device, with a one-click tab to switch to **Authenticator App (TOTP)**.
  - Automatically provisions 8 emergency recovery codes (`user_codes` / `user_recovery_codes`) and sets current workstation as `is_trusted = true` in `user_devices` with secure `laraslice_device_token` cookie.
* **New Browser / Device Detection (`login.mfa.challenge`)**:
  - Checks if incoming request has a valid `laraslice_device_token` cookie matched to `user_devices`.
  - If unrecognized (e.g. logging in from Microsoft Edge after enrolling in Chrome):
    - Prominently alerts: **"New Browser / Workstation Detected: Edge on Windows"**.
    - Directly offers **Device Enrollment Code (`DEV-XXXXXX`)** authorization (redeemed via `user_connect` table).
    - Or verifies with Authenticator App / Hardware Security Key, binds the new browser to `user_devices` (`is_trusted = 1`), and issues the device token cookie.




---

## 11. Model Context Protocol (MCP) Server & AI Copilot Architecture

LaraSlice features a native **Model Context Protocol (MCP)** server and an integrated **Context-Aware AI Copilot**.

### 1. Connecting AI Agents via MCP (`laraslice:mcp`)
IDEs such as Cursor, Antigravity, and Claude Code connect directly to LaraSlice via JSON-RPC 2.0 stdio:
```json
{
  "mcpServers": {
    "laraslice": {
      "command": "php",
      "args": ["artisan", "laraslice:mcp"]
    }
  }
}
```

### 2. Available MCP Tools in LaraSlice
| Tool Name | Parameters | Description |
| :--- | :--- | :--- |
| `list_slices` | `domain?` | List all discovered slices, domain groups, status, version, and models. |
| `toggle_slice` | `slice?`, `domain?`, `action` | Enable or disable an individual slice or entire domain group. |
| `seed_slice` | `slice?`, `domain?`, `count?` | Seed realistic relational dummy data for a slice or domain. |
| `wipe_slice_data` | `slice?`, `domain?`, `force?` | Truncate records for a slice or domain while keeping code intact. |
| `destroy_slice` | `slice?`, `domain?`, `mode`, `force?` | Complete teardown: drop tables, clean migrations, delete files. |
| `get_slice_schema` | `slice` | Inspect declarative schema, columns, child tables, and relationships. |
| `scaffold_slice` | `name`, `domain?`, `fields?`, `workflow?`, `flutter?` | Generate an end-to-end vertical slice with models, views, and routes. |
| `query_database_metrics` | *(none)* | Live telemetry: total users, active users, roles, audit counts, table list. |
| `prune_audit_logs` | `days?`, `slice?` | Prune security and audit log entries older than retention period. |
| `get_page_context` | `path` | Deep inspection of any screen/route (models used, permissions, controls). |

### 3. OpenCode Free Model & Floating AI Copilot Bubble
- **Zero API Key Requirement**: Default provider is **OpenCode Free** (powered by open weights models like Llama 3.3 70B & Qwen 2.5 Coder + LaraSlice Native Cognitive Engine). Works out of the box with zero paid API keys.
- **Floating Copilot Bubble (`<x-ui.ai-copilot-bubble />`)**:
  - Displays as a non-intrusive floating bubble on any screen.
  - Automatically detects the current page route and URL hash (e.g. `/admin/users/settings#mfa`).
  - Answers live operational questions with real database facts (*"How many users do we have?"*, *"Explain this page"*, *"What is our audit retention?"*).
  - Fully aware of Slice Studio actions: toggling slices, wiping slice data, wiping domain data, and adding fields.
- **Custom Provider Extensibility**:
  - Supports OpenAI, Google Gemini, Anthropic Claude, OpenRouter, and local Ollama via the UI switcher or `settings` table keys (`ai.openai_api_key`, `ai.gemini_api_key`, etc.).


### 4. LaraSlice Copilot Slash (`/`) Commands & MySQL Column Introspection (v1.2.0)
The in-browser AI Copilot provides live conversational commands tailored to the active screen:
- `/fields [table]`: Live introspects column schema (`Field`, `Type`, `Nullable`, `Key`, `Default`) via native MySQL queries.
- `/add-field [table] [field]`: Previews inferred column types and opens 1-click migration execution.
- `/relations`: Displays the aggregate parent-child entity tree and foreign keys (e.g. `user_id -> users.id`).
- `/permissions`: Inspects declared slice capabilities (`users.view`, `users.create`, `users.mfa`, etc.).
- `/nav`: Displays live sidebar navigation configuration, group order, and routes.
- `/logs`: Displays migration version trail and security audit retention statistics.
- `/suggest-fields [table]`: Catalog of enterprise fields tailored to the table context.
- `/seed [slice]`: Seeds realistic, relationally-consistent demo data in 1-click.
- `/wipe [slice]`: Safely wipes and truncates slice-specific data with cascade protections.

### 5. Conversational Field Migrations & Schema Evolution
When a user asks: `"add [field] in [table]"`, the AI Copilot:
1. Evaluates table compatibility and column presence.
2. Infers appropriate SQL type (`string`, `text`, `integer`, `decimal`, `boolean`, `date`, `timestamp`, `json`).
3. Enforces `nullable: true` by default to prevent breaking existing production rows.
4. Renders an interactive card in the chat window with enterprise recommendations chips and a single-click button: **`⚡ Apply Migration & Add Field Now`** which executes `POST /laraslice/wizard/add-field` directly.

---

## 23. Concepts Map — teach from this

One line per concept; the section named is its law.

- **Mind** (`porting/system.yaml`) — the system's intent:
  actors, roles as permission clubs, journeys, navigation,
  flows, integrations. Never executes; never holds code.
  (§18, `docs/MIND_BODY_HEART.md`)
- **Body** (`porting/ui-spec/<slice>.ui.yaml`) — one
  slice's pages as a contract: list, form (with field
  classification + `system_fields`), detail, access per
  club. A list-only Body is an unfinished draft. (§18, §21)
- **Heart** (`slice.yaml`) — the manifest; the SOLE runtime
  truth the generator reads. Mind and Body inform it; when
  they disagree with it, Heart wins and the others are
  stale. (§21)
- **Soul** — the design identity (kit, density, voice),
  declared in the Mind; it is not a fourth file.
- **Slice** — a vertical unit: manifest + models + BOs +
  services + controllers + views + migrations. Slices own
  their tables and never reach into another slice's.
  (§1, §3, §4)
- **Business Object (BO)** — the typed shape data takes
  between layers (form / listing / filter); the contract a
  service validates. (§2, §5)
- **Directory** — the standard list surface: columns,
  facets, strips, drawers, bulk + record actions, declared
  in the Heart, rendered by the kit. (§20, §21)
- **Hub** — the record page: header identity, tabs
  (fields / relations / audit), declared widgets with
  main/rail placement. (§19, §21)
- **Pattern** — a named, YAML-declared composition the
  standard layer renders (matrix, bulk entry, document,
  conversation, keyed-rows settings, import, invite,
  create_from, account-backed create…). Ports declare
  patterns; they never hand-build screens. (§19–§22)
- **Porter** — the pipeline that turns a source system
  into slices: discovery (code or SQL dump) → anatomy →
  declarations → generation → repair. (§12, §16, §21)
- **Anatomy** — the extracted truth of a source page
  (columns, actions, cards, forms), the evidence ports
  are declared from — never from taste. (§21)
- **Repair rules** — idempotent generator-level fixes
  applied fleet-wide; a defect is fixed in the generator
  or a repair rule, never patched slice by slice. (§21)
- **Kit** — the rendered design system. Ports may carry a
  replica kit matching the source's look; the standard kit
  is the default and the escape hatch. (§5.5, §21)
- **Studio** — the developer surfaces over the same
  declarations: Plan (Mind/Body editing), Operate (live
  system state), Dev (slice tooling). Modes are
  destinations, not steps. (§8, §18)
- **Drift** (`slice:drift`) — access reconciliation:
  Mind clubs vs granted permissions; zero errors is a
  release gate. (§18)
- **Conformance** — the measured comparison of port vs
  source: Gate F (furniture) and Gate T (tasks), recorded
  in the port's register. (§21, §22)
- **Replica status** — the port's `REPLICA-STATUS.md`:
  the four gates' measured results, deviations, and the
  NOT-covered list. It is the only source an AI quotes
  when asked how complete a port is. (§22)

## 26. The runtime family — portability is the horizon

LaraSlice is the reference implementation of a standard, not
the whole ambition (`docs/RUNTIMES.md`). The trilogy files,
the Dev Studio Standard, the Standard UI patterns, and this
skill are written runtime-neutral so sibling implementations
(Node, Java, .NET, Python, React/Next/Angular/Astro, plain
HTML, Flutter, Android) can inherit them whole. Two working
rules follow for everything built here:

- **The Heart's vocabulary never leaks one stack's idioms.**
  Storage types are families; UI is patterns, not components;
  stack specifics live in the runtime's mapping layer. A
  declaration only one runtime could honor is a standard
  defect, however convenient locally.
- **A cross-runtime move is a port** — verified by the same
  five gates (§22), with the Mind untouched: it was never the
  old runtime's property.

Sibling runtimes are roadmap, not shipped goods; never
describe them as existing. The permanence principles behind
the vision — a standard not a feature, knowledge in files,
domain-shaped so it travels beyond web software — are
written out in `docs/RUNTIMES.md` ('Built to last'). The sequencing stands: mature
this foundation, ship the flagship demo, then the family.

## 27. Spec-driven toolkits — the named path (two doors)

Teams arriving from a spec-driven toolkit (a constitution + specify/plan/tasks
workflow) do not need a second process. LaraSlice is the runtime door to the
same work: the spec becomes executable declarations, and convergence is proven
by gates, not by self-report. The named path, both doors:

| Step | Spec door (documents) | LaraSlice door (executable) |
| --- | --- | --- |
| constitution | a principles document | a per-project one-pager derived from the Mind (`system.yaml`) — principles as declarations |
| specify | a feature spec | the slice's Body (`*.ui.yaml`) + Heart (`slice.yaml`) declarations |
| plan | an implementation plan | the port/plan artefacts + Replica Protocol gates S·D·F·T·V |
| tasks | a task list | the generated surfaces + the drift report (`slice:drift`) |
| build | agent-written code | generation from declarations (`slice:make` scaffolds from the Heart; Dev Studio and the generator regenerate; `slice:repair` reconciles older output) |
| converge | review | `slice:drift` at zero errors + per-domain real-usage journeys (admin AND employee, values asserted) |

Rules: one source of truth per layer (documents propose, declarations
dispose); converge never accepts a self-reported status — it re-runs the
gates; a project may enter through either door and switch at any step,
because both doors name the same six steps.

## 28. Architect port mode — 100% replica decision tree

Full tradeoffs, gates table, and SDLC checklist: **`docs/PORTING-DECISION-100-REPLICA.md`**.

**Compressed tree:**

1. Signed scope in `porting/REPLICA-STATUS.md` (NOT covered list non-empty until owner waives).  
2. **Analyze + §16.5.1** before scaffold parity language.  
3. **Multi-model surfaces** → Phase 0 composition + Heart/generator — not permanent page clone.  
4. **Gate V M/S** after Kit attempt → time-boxed page clone for evidence; fold pattern back to Body.  
5. **100%** only when S→D→F→T→V PASS on same run and `slice:drift` → 0 errors — quote status file only.

Page-by-page usage clone is a **Gate V tactic**, not the strategic port definition.

## 29. AI token budget — agent read order

Optimization notes: **`docs/AI-FOUNDATION-OPTIMIZATION.md`**.

**Default boot (5 files max):**

1. **`docs/FOUNDATION-EXECUTION-TRACKER.md`** — sprint board + git handoff for Muse.  
2. Latest **`docs/PROGRAMME-STATUS-*.md`** in the framework repo (or port-only: skip to #4).  
3. **`docs/ENTERPRISE-UX-STANDARD.md`** when touching shell, Studio, or BlatUI chrome.  
4. **`docs/PORT-100-PLAYBOOK.md`** for the phase you are executing.  
5. Host **`porting/REPLICA-STATUS.md`** — lowest gate wins; never replace with chat %.

Add **`docs/PORTING-DECISION-100-REPLICA.md`** when choosing clone vs Kit. Routing P0: **`docs/ROUTING-TRUTH-ALIGNMENT.md`**. Activate **`laraslice-port`** / **`laraslice-start`** task skills instead of loading the entire core skill. MCP/CLI: Skill §11 route index — prefer tools over re-embedding command lists.

## Branch notes (`feat/workflow-bulk-actions`) — keep in sync with code

---

## Annexes (in this skill's references/)

- `references/multi-table-architecture.md` — §4 in full.
- `references/workflow-branch-notes.md` — workflow bulk-actions
  branch notes, kept in sync with the code.
