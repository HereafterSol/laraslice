---
name: laraslice
description: Architectural standards, CLI commands, Declarative Schemas, multi-table aggregate patterns, cross-slice communication, and AI workflows for LaraSlice applications integrated with Laravel Boost.
---

# LaraSlice Framework & AI Assistant Skill

This skill provides comprehensive instructions for AI agents and developers working on **LaraSlice** applications. It covers the Three-Layer Packaging Model (ADR-001), Vertical Slice Architecture, Pure BlatUI tokens, Declarative Schema Builder, multi-table aggregate patterns, cross-slice communication, and deep integration with **Laravel Boost**.

---

## 1. Architectural Principles & Packaging (ADR-001)

1. **Three-Layer Packaging Model**:
   - **Layer 1: Core Engine (`vendor/hereafter/laraslice`)**: Shared lean engine containing base classes, slice discovery & caching (`slice:cache`), audit engine, RBAC gate bridge, declarative schema, and CLI generators. Zero vendor lock-in, zero runtime network/phone-home calls.
   - **Layer 2: Starter Template (`laraslice-starter`)**: Application shell, BlatUI components, theme tokens, and default starter slices (`Auth`, `Users`, `Roles`, `Settings`, `AuditViewer`). Cloned once per project; owned by the team after clone.
   - **Layer 3: Project Slices (`app/Slices/{SliceName}/`)**: Client domain features (e.g. `Invoices`, `HR`, `Procurement`). Generated using the generation gap pattern: generated base classes are safe to regenerate, while hand-written subclasses, contracts, and business logic are never overwritten.

2. **Vertical Slice Architecture (VSA)**:
   - Slices are **Domain Bounded Contexts**, NOT database tables. A single slice can own multiple aggregate tables.
   - Each slice owns its own `Models/`, `Migrations/`, `Controllers/`, `Services/`, `Contracts/` (Business Objects / DTOs), `Routes/` (`web.php`, `api.php`), `Resources/views/`, and `Schemas/`.
   - Manifest: `slice.yaml` is the version-controlled single source of truth for metadata, capabilities, navigation, and relations.

3. **No Livewire / No Flux / No Metronic**:
   - The stack is strictly **Pure Blade + Alpine.js + Tailwind CSS v4**.
   - Atomic UI elements use **BlatUI** (`<x-ui.card>`, `<x-ui.table>`, `<x-ui.input>`, `<x-ui.badge>`, `<x-ui.button>`, `<x-ui.label>`).
   - The application layout uses the pure BlatUI Dashboard-01 responsive shell (`layouts.app` / `<x-layouts.app>`). Pure Lucide icons (`<x-lucide-*>` and dynamic Lucide components). Zero Metronic dependencies (`ktui.min.js`, Keenicons, and `KTDrawer` are strictly prohibited).

4. **Design Tokens & Theme Consistency**:
   - Always use semantic CSS variables mapped to Tailwind utilities: `bg-background`, `bg-card`, `border-border`, `text-foreground`, `text-muted-foreground`, `text-primary`.
   - Never hardcode dark hex colors. Theme switches dynamically via Alpine.js `$store.theme` and `data-theme` tokens.

---

## 2. Declarative Schema Builder (`LaraSlice\Schema`)

Every slice defines a declarative schema in `app/Slices/{SliceName}/Schemas/{SliceName}Schema.php` extending `LaraSlice\Schema\SliceSchema`.

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

## 4. Multi-Table Slices vs Domain Suites (Real-World Architecture)

### A. Domain Slices with Cross-Slice Relationships (e.g. CRM: Companies & Contacts)
In real-world enterprise applications (Salesforce, HubSpot, Stripe), entities like **Companies** and **Contacts** are **autonomous, first-class vertical slices** within a shared **Domain Group**:

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
still gets inferred columns, status/priority quick filters, and
money summary cards in its manifest. Declared columns always win.

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

Document views (G2): declare
`ui.show.document: { title:, line_items: <relation|repeater>, totals: [fields] }`
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

Report views (G2): declare
`ui.directory.report: { group_by:, aggregates: [{fn: count|sum|avg, column?, label?}], date_field?:, title?: }`
and the slice gains `{index-uri}/report` — grouped rows with
computed aggregates and a totals row via
`DirectoryUi::reportFromManifest()` and the standard package report
template (slice-overridable). With `date_field`, `?month=&year=`
scopes the period. Declared-route registration reads the controller
class from the routes file's own `use` statement — never derive it
from the slice namespace (segment casing breaks PSR-4 lookups).

Show-stat filters (F3c extension): a stat's `filter:` values may be
`!`-prefixed to EXCLUDE (whereNotIn) — for definitions inclusion
cannot express, e.g. open tasks = `board_column_id: ['!<complete-id>']`
(the source application's own User::openTasks definition).

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

## 7. Working with CLI Commands & Laravel Boost

LaraSlice provides a comprehensive, standardized suite of console commands across the `slice:*` and `laraslice:*` namespaces:

### Discovery, Blueprint & Scaffolding
- `php artisan slice:list {--domain=}`: Display all registered slices with Domain, Models, Version, and Status.
- `php artisan slice:make <SliceName> [<SliceName2> ...] {--domain=} {--workflow} {--field=*} {--flutter}`: Scaffold complete end-to-end vertical slices.
- `php artisan slice:field <SliceName> [field]`: Enhance an existing slice with new field(s) in a single consolidated migration (October CMS Builder style).
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
- `php artisan slice:cache` / `slice:clear`: Cache or clear slice discovery manifests for production boot optimization.
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

## 12. Monolith Reverse-Engineering & Legacy System Porting (Step-by-Step AI Architecture Workflow)

When an AI agent or developer is tasked with porting an existing monolithic application (e.g. complaint/adjudication systems, multi-module ERPs, or any legacy Laravel/PHP application) into LaraSlice, the process must follow a rigorous, deterministic 7-step analysis workflow. 

### Porting foundation rules (ERP-class benchmark)

1. **Source of truth order**: DB schema + FKs → **real menu/nav** (`menu.blade.php` / `x-menu-item`) → routes/controllers → status enums → optional journey steps.
2. **Do not invent BRD essays** as journeys. Journeys must be operational: `persona → steps[{slice, action, route?}]` (`schema: laraslice.journeys.v2`). Admin / Employee / Client match typical multi-tenant ERP roles when an ERP signature is detected.
3. **Navigation compiler**: Prefer `parseXMenuItemBlade` / blade sidebars; map groups to Porting Studio `sidebar_preview` with modules/permissions hints. Never replace gated menus with generic table lists.
4. **Aggregates over table explosion**: `*_items` / `*_lines` are children; `*_settings` / `*_types` go to Settings Hub or taxonomy — not sidebar noise.
5. **Studios after port**: Blueprint/Slice Studio edit YAML (`ui.directory`, workflow, bulk_actions); Porting Studio shows real nav + slim journeys for customization before Apply.
6. **Every project differs**: Process-core domains differ from multi-module ERP suites. Detect signatures; do not force process-core packs onto ERP or vice versa.
7. **All domains use `laraslice.journeys.v2`**: ERP → admin/employee/client; process-core/adjudication → admin/complainant/mobile; other → operational nav-derived personas. No mermaid BRD novels in the port plan.
8. **100% port checklist (ERP benchmark)**: aggregates + settings hub (`type: settings_hub`) + gated sidebar + enum workflows + persona journeys + Studio-editable YAML + ETL order (taxonomies → settings → roots → children → pivots). Apply must leave Porting/Blueprint/Slice Studio useful for customization.
9. **Workflow bulk actions**: Seed `ui.directory.bulk_actions` from detected transitions; Studios remain the place to add/remove bulk and row actions.

Over **50% to 60% of an application's architecture can be accurately reverse-engineered directly from its database schema, foreign keys, and seeders**.

### Step 1: Database DDL & Schema Graph Extraction
1. **Catalog Every Table**: Extract all table names from `database/migrations` or direct database introspection.
2. **Analyze Foreign Key Chains**:
   - Tables with no inbound foreign keys and only `user_id` are primary **Aggregate Roots** (e.g., `complaints`, `requisitions`).
   - Tables with `onDelete('cascade')` or direct `parent_id` foreign keys are **Child Sub-Tables** (e.g., `accused`, `complaint_properties`).
   - Tables ending in `_types`, `_natures`, `_modes`, or `_statuses` are **Taxonomy & Master Data Catalogs** (e.g., `complaint_types`, `harassment_natures`).

### Step 2: Sibling Slices vs. Sub-Table Decomposition
Never cram 50+ tables into a single monolithic slice. Partition the database into a cohesive **Domain Suite** using these two rules:
- **Rule A (First-Class Sibling Slice)**: If an entity has independent business actions, dedicated navigation, its own calendar, or a distinct persona responsible for it (e.g., `Hearings` managed by Registrar/Advisor, `Notices` with postal tracking, `Orders` with judicial signatures), it MUST be an autonomous **Sibling Slice** within the shared Domain (e.g., `Adjudication/Hearings`, `Adjudication/Notices`).
- **Rule B (Composition Child Sub-Table)**: If an entity cannot exist without its parent and has no independent navigation (e.g., `accused` respondent details, `complaint_properties`), keep it as a **Child Table** on the parent Aggregate Root using LaraSlice's multi-table aggregate builder.

### Step 3: Workflow, Roles & State Machine Extraction
1. **Status Discovery**: Inspect status seeders and tables (e.g., `complaint_statuses`). Check if statuses differ by matter subtype (e.g., Workplace Harassment vs Women's Property Rights).
2. **Transition Log Discovery**: Inspect approval/audit tables (e.g., `complaint_approvals`). Identify:
   - State transition columns (`from_role_id`, `to_role_id`, `from_user_id`, `to_user_id`).
   - File lock mechanisms (`is_active = true` indicating active assignee in an electronic file movement system).
   - Sign-off requirements (e.g., Short Order vs Detailed Order branch).
3. **Map the Transition Matrix**: Define initial state, intermediate gates, permitted transition roles, and terminal states in `slice.yaml` / `workflowConfig`.

### Step 4: Route, Controller & Permission Decomposition
1. **Analyze Controllers & Request Guards**: Inspect `app/Http/Controllers/` to identify the functional boundaries:
   - Intake wizards (`storeStepOne`, `storeStepTwo`) -> `Complaints` slice.
   - Summons & templates -> `Notices` slice.
   - Cause lists & session sheets -> `Hearings` slice.
   - Order drafting & vetting -> `Orders` slice.
2. **Synthesize RBAC Capabilities**: Declare role permissions strictly scoped to their functional slice (e.g., `complaint.scrutiny` for Assistant Registrar, `hearing.schedule` for Registrar, `order.sign` for Ombudsperson).

### Step 5: Specialized Attributes & EAV Strategy
When a system supports multiple distinct statutory subtypes (e.g., Harassment with incident date and organization vs. Property Rights with khasra number and acquisition mode):
- Keep core dossier columns on the primary root table (`complaints`).
- Model specialized attributes as dedicated child tables or 1:1 relation tables (`complaint_attributes_harassments`, `complaint_attributes_properties`).

### Step 6: Auxiliary Services & Integration Subsystems
Map auxiliary models to their appropriate layer:
- **Summons/Notice Generation**: Store letter templates with dynamic placeholder tags (`notice_templates`) and tracking numbers (`tcs_consignment_no`).
- **External Field Inquiries**: Isolate third-party requisitions (DC, Police, Inquiry Committees) into a dedicated `FieldInquiries` slice.
- **Appeals & Representations**: Model appellate reviews (Presidential review under Section 9) in an `Appeals` slice.

### Step 7: Blueprint Synthesis & 1-Click Generation
1. Compile the findings into a canonical `domain.yaml` or use the Visual Blueprint Studio (`/laraslice/wizard/blueprint`).
2. Run the automated generation pipeline:
   ```bash
   php artisan slice:blueprint:apply domain.yaml --yes
   ```
3. Run migrations and relational seeders to verify 100% feature parity with zero code regressions.

### Step 7: Visual Studio Review & Complete Domain YAML Inspection
Before or after executing porting commands, developers can inspect the entire reverse-engineered architecture interactively in Blueprint Studio (`/laraslice/wizard/blueprint`):
1. **Interactive Review in Studio (`📥 Load into Studio`)**:
   - Loads all reverse-engineered slices (e.g. Complaints with 7 models and 93 fields, Organizations, Attachments, MasterData with 21 taxonomies) into live Blueprint Studio memory without touching disk.
   - Allows switching between slices via domain suite tabs to review models, field types, validation rules, child relations, roles, and workflow stages.
2. **Complete Domain Blueprint YAML**:
   - Blueprint Studio provides a toggle between **📄 Current Slice YAML** and **📦 Complete Domain YAML**.
   - Developers can view and copy the unified `domain.yaml` representing the entire Bounded Domain and all child slices simultaneously.

### Conversational AI Porting Protocol (Non-UI Execution)

When a developer asks to port or reverse-engineer an existing application in chat/CLI without using the Visual Studio (`/laraslice/wizard` or `/laraslice/wizard/blueprint`), the AI agent **MUST proactively prompt the user for input** before executing changes:

1. **Prompt 1: Legacy Source Path**:
   - Ask for the path or repository location (e.g. `/path/to/legacy-app` or `.`).
2. **Prompt 2: Target Domain Group**:
   - Ask what high-level Bounded Context / Domain to assign (e.g. `Adjudication`, `LegalCompliance`, `CRM`, `Procurement`).
3. **Prompt 3: Data Porting Mode**:
   - Offer the user a clear choice between two modes:
     - **Mode A (`system-base`) [Recommended for clean development]**: Extracts reference master taxonomies, 24 statuses, categories, and regional registries into clean seeders (`0 blank selects`, clean slate for new test petitions).
     - **Mode B (`full-data`) [For live production migrations]**: Full topological table data migration, preserving historical petition IDs, hearings, approvals, and copying physical file attachments from `storage/app/public`.
4. **Execution Command**:
   - Once the user confirms choices, execute:
     ```bash
     php artisan slice:port "path/to/legacy" --domain="DomainName" --mode=system-base
     # Or for full production migration:
     php artisan slice:port "path/to/legacy" --domain="DomainName" --mode=full-data --with-files
     ```
   - **N-tier targeting**: `--super-domain=ERP` nests slices as `app/Slices/ERP/<Domain>/<Module>/<Slice>` (3-tier). Without it, slices land in `app/Slices/<Domain>/<Module>/<Slice>`.
   - **Legacy DB credentials** (for `--mode=full-data` ETL): pass `--source-host`, `--source-port`, `--source-database`, `--source-username`, `--source-password`, or `--source-connection=<name>` referencing a `config/database.php` connection. Never hardcode credentials.
   - **Dry-run first**: always preview with `--dry-run` — it prints the table→slice mapping, multi-tenancy detection, and warns about unmapped pivot tables before writing anything.
   - **Scaffold errors are explicit**: per-slice failures print `[SliceName] reason` — never silently skipped.

---

## 13. Phased Monolith-to-Slice Migration Standard (Best Practices & Developer Experience)

When porting a complex legacy monolithic system (e.g. large complaint/adjudication systems or multi-module enterprise ERPs) to LaraSlice, **never attempt a "big-bang" single-pass port of 50 blade views, procedural controllers, and report scripts**. 

Follow the **LaraSlice Phased Strangler-Fig Migration Methodology**:

### Phase 1: Domain Architecture & System-Base Seeding (The Foundation)
1. **Reverse-Engineer Schemas**: Run schema introspection via CLI (`php artisan slice:port <path> --domain=Adjudication --mode=system-base`) or Blueprint Studio (`/laraslice/wizard/blueprint`).
2. **Classify Flat Tables**: Slices are partitioned into Aggregate Roots, Child Sub-tables, and Taxonomy Catalogs.
3. **Execute System-Base Seeders**: Extract reference lookup tables (all 24 statuses, categories, hearing types, regions) into `database/seeders/` so the application boots with **zero blank dropdowns**.

### Phase 2: Visual Studio Blueprint Refinement (Blueprint Studio)
1. **Load into Studio**: Use the **`📥 Load into Studio`** button or **`📥 Import YAML`** to inspect the entire domain architecture live in Studio memory.
2. **Review Models & Field Controls**: Verify parent-child aggregates (e.g. `Complaints` with `accused`, `properties`, `approvals`) and input widgets (text, textarea, select, switch, file dropzones).
3. **Visual Lifecycle Workflow**: Inspect and customize the statutory judicial stages (e.g. multi-stage statutory judicial milestones), transition action buttons, and remarks requirements (Note: Statutory judicial ombudsman bodies operate strictly under judicial procedure rules with zero automated SLA hour countdowns).
4. **Scoped Operational Roles & RBAC**: Review authentic operational personas (`Super Admin`, `Admin`, `Complainant`, `Assistant Registrar`, `Registrar`, `Ombudsperson`, `Advisor`, `Law Officer`) and capability gates (`complaints.hear`, `complaints.decide`).
5. **Two-Way Domain YAML**: Edit or paste unified `domain.yaml` with live bi-directional synchronization.

### Phase 3: Conversational AI-Assisted User Journey Porting (Step-by-Step)
Once the domain foundation and master seeders are booted, the developer and AI agent collaborate journey-by-journey:

```
Legacy Monolith Codebase
       │
       ▼
Phase 1: [System-Base Seeders & Slice Shells] ──► 0 Blank Dropdowns
       │
       ▼
Phase 2: [Blueprint Studio Visual Refinement] ──► Verified Models, Workflow & Roles
       │
       ▼
Phase 3: [Journey-by-Journey Functional Porting]
       ├── Journey A: Complainant Intake Portal (Multi-step petition form, uploads, tracking #)
       ├── Journey B: Registrar Scrutiny (Deficiency notices, maintainability)
       ├── Journey C: Hearing Proceedings (Summons dispatch, evidence recording)
       ├── Journey D: Ombudsperson Orders (Speaking orders, petition disposal)
       └── Journey E: Document Generation (PDF summons, SMS/Email alerts)
```

1. **Journey A: Complainant Public Intake Portal**:
   - AI inspects legacy public controllers and multi-step citizen forms.
   - Generates LaraSlice Web Controller actions + modern **BlatUI** public intake views with file upload dropzones and validation.
2. **Journey B: Registrar Scrutiny & Maintainability**:
   - AI inspects registrar verification logic.
   - Wires maintainability checks, deficiency notice issuance, and cause list queuing.
3. **Journey C: Judicial Hearing & Evidence Proceedings (Ombudsperson & Advisor)**:
   - Wires hearing calendar, summons generation, respondent notices, and evidence recording.
4. **Journey D: Ombudsperson Final Speaking Order & Law Officer Implementation**:
   - Wires reserved order desk, final speaking order attachments, and petition closure (`disposed_off`).
5. **Journey E: Document Generation & Integrations**:
   - Ports DomPDF/Snappy PDF summons templates, notice printouts, and automated email/SMS status notifications.

This phased roadmap eliminates regressions, keeps each stage immediately testable, and provides a delightful, rock-solid developer experience.

---

## 14. Pre-Suggestion Inspection Protocol (Seeders, Navigation Matrix & Controller Routing)

> [!IMPORTANT]
> **MANDATORY RULE FOR AI AGENTS**: Before providing ANY architectural suggestion, migration plan, route implementation, or code recommendation to the developer after domain/slice seeding, the AI **MUST** systematically inspect the source project's **Seeders**, **Sidebar Navigation Components**, and **Controller Routing Flow**. Never make assumptions or provide suggestions based on generic CRUD templates.

### The 4-Point Inspection Checklist (Run Before Suggesting Code):

1. **Point 1: Seeders & Master Ground Truth Data**
   - **Inspect**: `database/seeders/*` (e.g., `RolesSeeder.php`, `ComplaintStatusSeeder.php`, `ComplaintTypeSeeder.php`).
   - **Verify**: The exact role names (e.g., `Complainant`, `Assistant Registrar`, `Registrar`, `Ombudsperson`, `Advisor`, `Law Officer`, `Admin`, `Super Admin`), their numeric IDs, and statutory status stages.
   - **Zero Assumptions**: Strictly confirm whether SLA timers exist or not (e.g., statutory ombudsman courts have zero automated SLA hour countdowns).

2. **Point 2: Navigation Sidebar Matrix & Role Gates**
   - **Inspect**: Source blade layout sidebars (e.g., `components/admin/sidebar.blade.php`, `components/complainant/sidebar.blade.php`).
   - **Examine Role Gates**: Check every `@if(in_array($role, [...]))` block to determine:
     * Who accesses `Received Complaints` (Initial scrutiny by Assistant Registrar & Ombudsperson).
     * Who accesses `Open Complaints` (Active cases assigned to the user).
     * Who accesses `Hearing Management` (Registrar, Assistant Registrar).
     * Who accesses `Hearing Order Processing` (Advisor only).
     * Who accesses `Order & Case Files` (Registrar, Ombudsperson).
     * Who accesses `Decided Cases` (Registrar, Assistant Registrar, Ombudsperson).
     * Who accesses administrative/audit tools (Super Admin only).
   - **Two Distinct Portals**: Recognize when systems have separate administrative judicial portals (`prefix('system')`) and external citizen/complainant portals (`prefix('complainant')`).

3. **Point 3: Controller Routing & Query Filters**
   - **Inspect**: Legacy controllers (e.g., `Admin\ComplaintController.php`, `Complainant\ComplaintController.php`) and `routes/web.php`.
   - **Understand Queue Filtering**:
     * `received()`: Filters new petitions by region for Assistant Registrar.
     * `open()`: Filters active approvals where `to_user_id == current user`.
     * `hearings()`: Checks bench scheduling eligibility (`whereEligibleForHearingManagement()`).
     * `hearingOrders()`: Checks Advisor assignments forwarded from AR/Registrar.
     * `ordersCases()`: Tracks short orders (`is_short_order = 1`) and detailed orders (`is_short_order = 2`).
     * `decidedCases()`: Filters finalized decrees and rejected cases with remarks.

4. **Point 4: State Machine & Approval Hand-Off Logic**
   - **Inspect**: State transition methods (e.g. `updateStatus($id)`).
   - **Forwarding Rules**: How records pass between roles via approval tables (`complaint_approvals`), deactivating old assignments (`is_active = false`) and creating new ones.
   - **Order Flags**: Distinguish between interim orders (`is_short_order = 1`) and speaking decrees (`is_short_order = 2`).
   - **Notifications**: Automated system alerts triggered on status change or reassignment.

By following this protocol, LaraSlice AI ensures 100% architectural and operational fidelity with the legacy application.

---

## 15. Enterprise N-Tier Taxonomy, Multi-Tenancy & Universal Monolith Porting

### 15.1 Arbitrary N-Tier Taxonomy & Recursive Discovery
LaraSlice natively supports arbitrary directory nesting up to 6 levels deep:
- **3-Tier Enterprise Suite**: `app/Slices/{SuperDomain}/{Domain}/{Slice}` (e.g. `app/Slices/ERP/FinancialAccounting/Invoices`).
- **2-Tier Standard Domain**: `app/Slices/{Domain}/{Slice}` (e.g. `app/Slices/Adjudication/Complaints`).
- **1-Tier Flat Slice**: `app/Slices/{Slice}` (e.g. `app/Slices/AuditViewer`).

The engine dynamically infers `$slice->super_domain`, `$domain`, and `$sub_domain` and calculates the matching PSR-4 namespace (`getNamespace()`).

### 15.2 Pluggable Multi-Tenancy Subsystem (`LaraSlice\Core\Tenancy`)
Supports both single-tenant enterprise deployments and multi-tenant SaaS architectures:
1. **Model Integration**:
   ```php
   use LaraSlice\Core\Tenancy\Traits\HasTenant;

   class Invoice extends Model
   {
       use HasTenant; // Automatically applies TenantScope and assigns tenant key on create
   }
   ```
2. **Tenant Resolution**:
   - `TenantManager::setActiveTenant($id)` sets the active tenant context.
   - `TenantManager::withoutTenancy(fn() => ...)` bypasses tenant scoping for system background tasks, queues, or super-admin audits.
   - Middleware `IdentifyTenant` extracts tenant context from route parameters, headers (`X-Tenant-ID`), query string, or authenticated user session.
3. **Configuration**:
   In `config/laraslice.php`:
   ```php
   'tenancy' => [
       'enabled' => env('LARASLICE_TENANCY_ENABLED', false),
       'tenant_key' => env('LARASLICE_TENANT_KEY', 'company_id'),
   ],
   ```

### 15.3 Universal Monolith Reverse-Engineering (`php artisan slice:port`)
Re-architects legacy monolithic schemas (e.g., 200+ table ERPs) into domain-bounded vertical slices:
- **Dynamic Multi-Block Migration Parsing**: Extracts all `Schema::create` blocks, columns, and foreign keys even from massive consolidated migrations.
- **Dynamic In-Degree Foreign Key Graph Analysis**: Discovers primary aggregate roots and child tables based on relationship density, while excluding cross-cutting tenant/audit keys (`company_id`, `user_id`).
- **Automatic Multi-Tenancy Detection**: Automatically computes coverage of `company_id` / `tenant_key` and equips generated models with `HasTenant`.
- **Zero-Blank Dropdown Taxonomy Synthesis**: Generates deterministic MasterData seeders from source seeders or fallback baselines so UI forms render complete dropdowns.
- **Pivot tables are surfaced, never dropped**: detected pivots attach to their owning slice via FK affinity; unmapped ones are reported as warnings in CLI (`--dry-run`) and in the inspection payload (`unmapped_pivots`).
- **Complete manifests**: ported `slice.yaml` files include all aggregate child models with `hasMany`/`belongsTo` relations — the manifest always matches the generated code.
- **Honest UI**: the Porting Studio dialog never auto-runs Analyze, shows inline errors (no blocking popups), labels example content as examples, and binds all figures to the live plan. "Load into Studio" carries real inspected columns, not placeholders.
- **ETL safety**: source tables outside the priority list are reported as `skipped_tables` (never silently dropped); foreign-key handling is driver-aware (MySQL/SQLite/PgSQL); chunked reads are deterministic.

### 15.4 Enterprise UI/UX Navigation (Module Switcher Ribbon)
To prevent cognitive overload from dozens of vertical slices in enterprise apps:
- **Header Ribbon**: Displays horizontal active domain buttons (`[All] [Adjudication] [HumanCapital] [FinancialAccounting]`).
- **Scoped Sidebar**: Clicking a domain instantly filters the collapsible sidebar to display only slices belonging to that domain, with an active filter pill and 1-click reset in the sidebar header.

---

## 16. AI-Driven Legacy System Reverse-Engineering & Porting Protocol (Zero Hardcoding)

### 16.1 Core Mandate: Zero Hardcoding in Framework
**Never hardcode specific legacy project business logic, tables, or routes directly into the LaraSlice core framework.**
Instead:
1. The AI agent performs an exhaustive, in-depth architectural inspection of the legacy system.
2. The AI synthesizes a canonical JSON Architecture Plan (`porting-architecture-plan.json`) adhering to the official schema.
3. The developer or AI loads and previews the plan interactively in Blueprint Studio (`/laraslice/wizard/blueprint`) — validating the rendered sidebar preview tree, domain cards, JSON plan, and ETL streaming pipeline.
4. Execution is performed deterministically either with 1 click from the UI or via the CLI (`php artisan slice:port --plan=... --import-data`).

### 16.2 4-Point Legacy System Inspection Checklist
Before generating the Architecture Plan, the AI agent must inspect:
1. **Database Schema & Foreign Key Density**:
   - Query all tables and row counts (`SHOW TABLES`, `SELECT COUNT(*)`).
   - Compute incoming foreign key degrees to identify Canonical Aggregate Roots (e.g. `projects`, `invoices`, `tickets`) vs Child Tables (e.g. `project_milestones`, `invoice_items`).
   - Group auxiliary lookup/config tables (e.g. `currency_format_settings`, `slack_settings`, `theme_settings`) into a unified `Settings` hub to eliminate sidebar clutter.
2. **Multi-Tenancy & Audit Pattern Detection**:
   - Identify tenant scoping keys (`company_id`, `tenant_id`) and compute percentage coverage across tables.
   - Detect audit columns (`created_by`, `updated_by`, `deleted_at`) for automatic `auditStamps` macro application.
3. **Route Structure & Navigation Menus**:
   - Inspect legacy sidebar menu definitions (blade layouts, menu configs, controller actions).
   - Group raw table items into intuitive business modules (Work, SalesCRM, FinancialAccounting, HumanCapital, SupplyChain, ServiceDesk, Operations, Settings).
4. **ETL Relational Data Dependencies**:
   - Establish parent-to-child data migration sequence.
   - Stream operational records using chunked batches with `SET FOREIGN_KEY_CHECKS=0` and `insertOrIgnore` to preserve existing primary keys and foreign key integrity.

### 16.3 Standard `porting-architecture-plan.json` Schema
```json
{
  "$schema": "https://laraslice.dev/schemas/porting-architecture-plan.v1.json",
  "version": "1.0.0",
  "generated_at": "2026-10-14T20:45:00Z",
  "domain": "ERP",
  "source_application": "e:\\laragon\\www\\erp",
  "target_framework": "LaraSlice VSA (Laravel 12 + Blade + Alpine.js + Tailwind CSS v4 + BlatUI)",
  "summary": {
    "total_legacy_tables": 358,
    "total_populated_tables": 137,
    "total_canonical_slices": 23,
    "total_modules": 9,
    "total_records_detected": 15664,
    "multi_tenancy": {
      "enabled": true,
      "tenant_key": "company_id",
      "coverage_percentage": 82.4
    },
    "eliminated_sidebar_clutter": "Consolidated 73 auxiliary configuration tables into a single Unified Settings Hub."
  },
  "sidebar_preview": {
    "title": "ERP Enterprise Navigation",
    "theme": "BlatUI Dashboard-01",
    "groups": [
      {
        "name": "Work",
        "icon": "briefcase",
        "collapsible": true,
        "default_open": true,
        "items": [
          {
            "slice": "Projects",
            "label": "Projects",
            "icon": "briefcase",
            "route": "/erp/work/projects",
            "badge": 10,
            "badge_color": "primary",
            "tabs": ["Overview", "Milestones", "Tasks", "Team Members", "Time Logs", "Invoices", "Files"]
          }
        ]
      }
    ]
  },
  "modules": [
    {
      "name": "Work",
      "slug": "work",
      "icon": "briefcase",
      "slices": [
        {
          "name": "Projects",
          "handle": "projects",
          "root_table": "projects",
          "records_count": 10,
          "child_tables": [
            { "table": "project_milestones", "records": 100, "relation": "hasMany" },
            { "table": "project_members", "records": 50, "relation": "hasMany" }
          ],
          "permissions": ["view", "create", "edit", "delete", "export", "archive"],
          "workflow": { "has_state_machine": true, "field": "status", "states": ["not started", "in progress", "on hold", "canceled", "finished"] }
        }
      ]
    }
  ],
  "etl_data_pipeline": {
    "source_connection": "mysql (legacy database)",
    "target_connection": "mysql (current database)",
    "execution_strategy": "Foreign key checks disabled during streaming; records imported in dependency-aware batches with auto-id preservation."
  }
}
```

### 16.4 Dual Execution Pathways
1. **Interactive UI Workflow (Blueprint Studio)**:
   - Developer opens `/laraslice/wizard/blueprint` and clicks `🚀 Port Legacy App`.
   - Selects preset (e.g. `ERP Enterprise Suite`, `Dispute Adjudication`, or `Custom JSON Architecture Plan`).
   - Interactively previews:
     - `🖥️ Sidebar & Navigation Live Preview`: Real-time rendered BlatUI sidebar shell with live database record badges.
     - `🏗️ VSA Domain Architecture`: Visual cards showing modules, slices, roots, and child tables.
     - `📋 JSON Architecture Plan`: Syntax-highlighted JSON with "Copy JSON" and "Download JSON" buttons.
     - `⚡ ETL Data Pipeline`: Table list, row counts, and migration strategy.
   - Clicks `🚀 Execute 100% Usable Porting` to scaffold slices and stream operational data.
2. **Deterministic CLI Workflow**:
   ```bash
   # Dry-run review
   php artisan slice:port --plan=docs/porting-architecture-plan.json --dry-run

   # Full scaffolding and ETL data streaming
   php artisan slice:port --plan=docs/porting-architecture-plan.json --import-data --no-interaction

   # Register permissions and sync Super Admin
   php artisan slice:install --force
   ```

### 16.5 AI-First Source System Analysis (Before Porting)

**Never port blind.** Before generating any YAML or scaffolding any slice, the AI agent MUST analyze the source system — both its code AND its UI. This is the "AI First" principle: understand deeply, then generate completely.

**Phase A: Code Analysis**
1. **Inspect database schema**: Tables, columns, foreign keys, indexes (via `slice:port --dry-run`)
2. **Map controllers to slices**: Which controller actions belong to which aggregate? (e.g., `Invoices.php` has 16 actions → Invoices slice)
3. **Identify business logic**: Look for non-CRUD methods — `generatePdf()`, `sendEmail()`, `markAsPaid()`, `calculateTax()`. These are NOT portable as CRUD; they need manual implementation notes.
4. **Find Jobs/Events**: If the source uses Jobs (e.g., Akaunting's 92 Jobs), document which job encapsulates which business rule.
5. **Detect dead tables**: Tables with no active model/controller are legacy — flag them, don't port them as features.

**Phase B: UI Analysis**
1. **Find view templates**: Locate Blade/Vue/React templates for each controller action
2. **Extract form structure**: What fields are on the create/edit forms? How are they grouped? (sections, tabs, accordions)
3. **Extract table columns**: What columns are in the index/listing? What formatters? (badges, money, dates, avatars)
4. **Extract filters**: What search/filters exist? (status dropdown, date range, category filter)
5. **Map to v1.6.4 `ui:` schema**: Translate the source UI into declarative YAML:
   - Source table columns → `ui.directory.columns` with inferred formatters
   - Source form sections → `ui.forms.sections` with grid layout
   - Source filters → `ui.directory.quick_filters` / `sidebar_filters`

**Phase C: Generate Complete YAML**
Only AFTER Phases A and B, generate the `slice.yaml` with:
- `models:` from Phase A (tables + relations)
- `ui:` from Phase B (real columns, real form structure, inferred formatters)
- `porting_notes:` documenting what was NOT portable (business logic, jobs, custom actions)

**Example porting_notes:**
```yaml
porting_notes:
  - "Source controller Invoices.php has generatePdf() — implement manually in InvoiceService"
  - "Source has 10 custom routes (markSent, markCancelled, email) — add as slice actions"
  - "Tables invoice_histories is v1 legacy (2017), no active model — excluded from aggregate"
```

### 16.6 AI-Guided 4-Step Page & UI Porting Methodology (BlatUI Parity)
When transitioning from database/schema migration to full UI feature parity, follow the standardized **4-Step BlatUI Methodology** for every ported slice. Never generate ad-hoc HTML or non-BlatUI dependencies (0% Metronic, Livewire, or Flux):

1. **Step 1: Listing / Directory Architecture (`index.blade.php`)**:
   - **Executive Stats Ribbon**: Top KPI badge counters (`MetricCards`) indicating total records, active status, pending reviews, and overdue actions.
   - **Multi-Dimensional Filter Bar**: Responsive Alpine.js filter container with search input, status dropdowns, foreign key filters, date ranges, and a 1-click `Reset` pill.
   - **Data Table**: BlatUI responsive table with sortable column headers, state badges, avatar thumbnails, and inline row quick-action dropdowns (`View`, `Edit`, `Transition`, `Delete`).
   - **Bulk Actions & Pagination**: Multi-record selection checkboxes, bulk export, and paginated record navigation.

2. **Step 2: Show Workspace Architecture (`show.blade.php`)**:
   - **Master Entity Header**: Prominent record title, code badge, status transition dropdown, and action toolbar (`Edit`, `Print/PDF`, `Audit Log`, `Delete`).
   - **Sub-Tabs Consolidation (N Sub-Entities : 1 Master Workspace)**:
     - Replaces monolithic navigation clutter by managing all related child entities as responsive tabs inside the master entity workspace.
     - E.g., for `Projects`: `Overview`, `Milestones (100)`, `Tasks (50)`, `Team Members (50)`, `Time Logs (19)`, `Invoices`, `Files`.
     - Each tab contains an autonomous BlatUI CRUD grid or child item manager with inline modal triggers.

3. **Step 3: Create & Edit Form Architecture (`create.blade.php`, `edit.blade.php`)**:
   - **2-Column Responsive Fieldset Layout**: Grouped logical sections (e.g., `General Information`, `Schedule & Budget`, `Client Visibility`, `Deliverables`).
   - **Pure BlatUI Form Controls**: Standardized input fields, select dropdowns populated by master seeders, switches for boolean toggles, and file dropzones.
   - **Client + Server Validation**: Alpine.js real-time client feedback coupled with strict FormRequest validation rules on the backend.

4. **Step 4: Finite State Workflow & RBAC Parity (`BaseSliceWebController`)**:
   - **State Machine Transitions**: Driven by the slice's state column (e.g. `status`), enforcing allowed state transitions (e.g., `not started` → `in progress` → `on hold` → `finished`).
   - **Granular Capability Gates**: Gated by `@can('[slice].[action]')` (e.g. `@can('projects.edit')`, `@can('invoices.approve')`).
   - **Immutable Audit Trail**: State transitions and key field updates automatically record timestamps, user ID, remarks, and previous values in the activity history.

### 16.7 Legacy Route Mapping & Page Inventory Protocol
Before porting controllers, the AI agent must catalog legacy routes and systematically map them to Vertical Slice Architecture:
1. **Extract Route Inventory**: Run `php artisan route:list --json` or parse `routes/web.php` in the legacy application to discover total route count, URL patterns, controller actions, and middleware.
2. **Consolidate Monolithic Routes into Bounded Slices**:
   - Legacy monoliths often contain hundreds of fragmented routes (e.g. 447 routes across 101 raw table controllers in an ERP).
   - In LaraSlice VSA, these 447 routes are consolidated cleanly into 23 bounded slices. E.g.:
     - 23 legacy client-related routes → `app/Slices/ERP/SalesCRM/Clients/` (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`, `ajax-milestones`, `export`).
     - 26 legacy invoice-related routes → `app/Slices/ERP/FinancialAccounting/Invoices/`.
     - 20 legacy task-related routes → `app/Slices/ERP/Work/Tasks/`.
3. **Route Mapping Artifact**: Document the legacy-to-VSA route mapping in `porting-architecture-plan.json` under each slice's `pages` and `legacy_routes` arrays so both developers and AI assistants have 100% clarity on where every legacy URL is re-hosted.

### 16.8 Loading Ported Suite Directly into Blueprint Studio Canvas
Developers can inspect and edit the entire ported architecture interactively in Blueprint Studio (`/laraslice/wizard/blueprint`):
1. **Top Bar Button**: Click **`📥 Load Ported Suite (28 Slices)`** in the top navigation bar.
2. **Modal Action**: In the Porting Studio modal header or footer, click **`📥 Load into Blueprint Studio`**.
3. **Studio Canvas Experience**:
   - All 28 slices load into the **📦 Domain Suite Slices** top bar with their model and field counts.
   - Clicking between slices (`Projects`, `Tasks`, `Invoices`, `Employees`) instantly updates the canvas to show all root and child models, field types, validation rules, and relations.
   - The **📄 Page & UI Architecture Plan** card renders the 4-step BlatUI plan for that slice, with full stats ribbons, filter bar definitions, sub-tabs, form sections, and workflow state transitions.
   - Developers can edit any field or state directly in the Visual Designer or YAML editor, click **`Save Changes`**, and then click **`⚡ Run Migrations`** to scaffold and migrate.

### 16.9 Universal BRD-Grade Role & User Journey Standardization (Flows, State Transitions, Blade Views & Manifest Contracts)

**The core architectural foundation of LaraSlice is understanding HOW human actors and system agents flow through ANY business domain** — not merely what database tables or controller methods exist.

LaraSlice enforces a **100% Domain-Agnostic, Standardized Specification** for Business Requirements Documents (BRD), user journeys, role transitions, and operational step traces. Whether porting or scaffolding an **ERP, Healthcare (EHR/EMR), E-Commerce Marketplace, EdTech LMS, Logistics Fleet, or Fintech Core**: the engine, schema, and UI adapt dynamically with zero hardcoded domain constraints.

#### 1. Universal Standardized BRD Architecture
The framework classifies any enterprise software into 5 canonical operational tiers plus a master orchestration stream:

| Canonical Tier | Generic Persona Role | E-Commerce Example | Healthcare Example | ERP Operations Example |
|---|---|---|---|---|
| **Tier 1: Governance & Setup** | System Administrator (`admin`) | Merchant Storefront Config | Clinic & Provider Setup | Tenant Org & RBAC Setup |
| **Tier 2: Core Domain Operations** | Operational Lead (`operations`) | Catalog & Inventory Manager | Clinical Records & Triage | Project Charter & Milestones |
| **Tier 3: Stakeholder Collaboration** | External Stakeholder (`stakeholder` / `client`) | Buyer Checkout & Tracking | Patient Portal Intake | Client Proposal & Contract E-Sign |
| **Tier 4: Transaction & Settlement** | Financial / Billing (`finance`) | Gateway Order Settlement | Insurance Claim & Copay | Invoicing & Ledger Reconciliation |
| **Tier 5: Analytics & Intelligence** | Executive Analyst (`analyst`) | GMV & Conversion Telemetry | Patient Outcome Metrics | Cash Flow & Utilization BI |
| **🌐 Master Value Stream** | Master Orchestrator (`master`) | Complete Quote-to-Fulfillment | Complete Intake-to-Discharge | Complete Lead-to-Ledger |

#### 2. Canonical JSON / YAML Schema Contract

Every user journey in LaraSlice conforms to a strict, standardized specification at both the **Domain Suite Level** and **Slice Level**.

##### A. Domain Level Contract (`porting-architecture-plan.json` & `domain.slice.yaml`):
```yaml
user_journeys:
  - id: core_operations               # Canonical journey identifier
    name: "Clinical Patient Care Flow" # Human-readable lifecycle title
    title: "Clinical Patient Care Flow"
    persona: "Attending Physician"     # Specific business actor
    role: "physician"                  # RBAC role identifier
    icon: "🩺"                         # Contextual UI icon
    entry_point: "/emr/encounters"     # Entry URI route
    auth_flow: "Physician authenticates via MFA ➔ Redirects to Today Ward Round."
    blade_views:                       # Discovered or targeted Blade templates
      - resources/views/encounters/index.blade.php
      - resources/views/prescriptions/create.blade.php
    database_tables:                   # Mutated persistence tables
      - patient_encounters
      - clinical_notes
      - prescriptions
    mermaid_flow: |                    # Valid Mermaid flowchart sequence
      flowchart TD
          A["Encounter Intake<br/>(encounters/index.blade.php)"] -->|Triage Vitals| B["Clinical Notes<br/>(notes/create.blade.php)"]
          B -->|Order Diagnostics| C["Lab Orders<br/>(labs/ajax/create.blade.php)"]
          C -->|Formulate Rx| D["Prescription Dispatch<br/>(prescriptions/create.blade.php)"]
    legacy_friction: "Monolithic EncounterController executes 35 raw SQL queries with scattered patient data across 6 unindexed tables."
    modernization_gain: "Encounter Aggregate Root governing clinical notes and prescriptions with discrete BlatUI Action classes and async lab dispatch."
    steps:
      - title: "Encounter Intake & Vitals Recording"
        step: "Encounter Intake & Vitals Recording"
        blade_view: "resources/views/encounters/index.blade.php"
        trigger_ui: "<x-ui.button>Start Clinical Encounter</x-ui.button>"
        controller: "EncounterController"
        controller_action: "EncounterController@store"
        slice: "Encounters"
        persona: "Attending Physician"
        legacy_behavior: "Synchronous controller mutation saving vitals, history, and notes without atomic transactions."
        vsa_improvement: "StartEncounterAction with strict domain invariants and event-driven notifications."
```

##### B. Slice Level Contract (`app/Slices/{Domain}/{Module}/{Slice}/slice.yaml`):
Each slice manifest includes a localized `user_journeys:` block linking its specific models and UI triggers directly into the parent business workflow:
```yaml
name: Encounters
domain: Healthcare
module: Clinical
user_journeys:
  - journey: core_operations
    persona: Attending Physician
    role: physician
    entry_point: /healthcare/clinical/encounters/create
    blade_source: resources/views/encounters/index.blade.php
    triggers:
      - action: start_encounter
        ui: "<x-ui.button>Start Clinical Encounter</x-ui.button>"
        mutation: "INSERT INTO patient_encounters (patient_id, physician_id, chief_complaint, status)"
        emits_event: "EncounterStarted"
      - action: finalize_diagnosis
        ui: "Diagnosis Sheet -> Save Diagnosis Button"
        mutation: "UPDATE patient_encounters SET status = 'finalized', icd10_code = ?"
        emits_event: "EncounterFinalized"
    state_machine:
      field: status
      states: [scheduled, in_triage, in_consultation, lab_pending, finalized, discharged]
      transitions:
        - from: in_consultation
          to: lab_pending
          trigger: order_lab_tests
        - from: in_consultation
          to: finalized
          trigger: record_prescription
    modernization_gain: "Replaces monolithic god-controller with Encounter Aggregate Root, discrete BlatUI Action classes, and strict ICD-10 validation rules."
```

#### 3. Automatic Heuristic Discovery for Any Legacy Project
When running `slice:port` or analyzing an unknown legacy application in Porting Studio:
1. **Dynamic Scanner**: Introspects routes (`routes/web.php`, `routes/api.php`), controller directories, route middleware (`role:*`, `can:*`, `guard:*`), and Blade files.
2. **Dynamic Clustering**: If a domain preset is specified (`erp`, `crm`, `lms`), it utilizes verified domain blueprints; for ANY bespoke project, it dynamically clusters controllers into the 5 universal tiers and generates valid Mermaid `flowchart TD` sequences.
3. **Master Value Stream Synthesis**: Automatically generates `master_value_stream` connecting Tier 1 through Tier 5 to provide the comprehensive "Big Picture" process diagram required for enterprise BRD signoff.
4. **Interactive Porting Studio**:
   - Persona Filter Bar dynamically discovers all roles (`availableRoles()`) with zero hardcoded role names.
   - Flowcharts are rendered in-browser via Mermaid.js with Zoom In (`+`), Zoom Out (`-`), and Reset (`⟲`) controls.
   - Interactive Node Clicking (`onNodeClick`): Clicking any node highlights the corresponding operational step card below and displays the active Blade view, UI trigger element, controller endpoint, and VSA improvement.

### 16.10 AI-First Porting Intelligence (Dead Tables, Business Logic, Workflows)

The porter includes four AI analyzers that run automatically during `slice:port`:

| Analyzer | What it detects | Output |
|----------|----------------|--------|
| `detectDeadTables()` | Tables with no active Eloquent model (legacy v1 tables) | Warning in CLI; excluded from slice mapping |
| `detectBusinessLogic()` | Non-CRUD controller methods (`generatePdf`, `sendEmail`, `markSent`) | `porting_notes` in `slice.yaml` with manual implementation guidance |
| `analyzeSourceUi()` | Form fields from Blade templates, modal patterns | Feeds into `ui:` schema generation |
| `detectWorkflows()` | State machines from `mark*` methods | `workflow` config in `slice.json` manifest |

**Example `porting_notes` in `slice.yaml`:**
```yaml
porting_notes:
  - "Source controller Invoices.php has custom method `pdfInvoice()` — implement manually in InvoicesService"
  - "Source generates PDF documents — implement PDF generation manually"
  - "Source sends emails — implement mail integration manually"
```

### 16.11 Dual-Source Table Discovery (Migrations + Live Database)

The porter handles both types of projects:

**Projects WITH migrations** (standard Laravel):
- Parses `database/migrations/` for `Schema::create()` calls
- Extracts columns, types, and foreign keys from migration code

**Projects WITHOUT migrations** (legacy, or DB-first):
- Connects to the live source database via `--source-*` options
- Uses `SHOW TABLES` / `information_schema` as the source of truth
- Works with MySQL, PostgreSQL, and SQLite

**When both exist** (recommended):
```bash
php artisan slice:port /path/to/source \
  --source-host=127.0.0.1 \
  --source-database=legacy_erp \
  --source-username=root \
  --source-password=secret
```
The porter reconciles both sources:
- **Live DB is the source of truth** for which tables exist
- **Migrations provide metadata** (column types, FK constraints, migration filenames)
- Tables in DB but not migrations → marked `source_database_only` with a porting note
- Tables in migrations but not DB → marked `migrations_only` (possibly pending or dead)

Each table in the analysis tracks its `table_source`:
- `source_database` — from live DB only
- `migrations` — from migrations only  
- `source_database+migrations` — confirmed in both
- `source_database_only` — manual/legacy table, no migration
- `migrations_only` — defined but not in live DB

### 16.12 Data Porting (Slice-Aware)

Data porting is **slice-aware** — it uses the slice mapping to determine which tables to import, not a hardcoded list.

**Three data sources:**

1. **Live database tables** (`importForSlices()`):
   - Streams data from source DB → target DB
   - Uses slice mapping to find root tables, child tables, and pivots
   - Chunked (250 rows) with FK constraints disabled during import
   - Sanitizes invalid timestamps (`0000-00-00` → null)
   - Only imports columns that exist in both source and target

2. **Source seeders** (`analyzeSeeders()`):
   - Parses `database/seeders/` for `DB::table()->insert()` and `Model::create()` calls
   - Maps seeder tables to slices
   - Flags reference data (currencies, countries, roles, tax rates) as safe to auto-import
   - Generates per-slice seeder recommendations

3. **Legacy priority list** (`importFromDatabase()`):
   - Deprecated — uses hardcoded table list
   - Kept for backwards compatibility only

**Framework Auth Protection (v1.6.4+):**

The ETL **never wipes** framework auth tables by default. `roles`, `permissions`, `permission_role`, and `role_user` are skipped with a `skipped_system_auth` status. `users` are merged by email — existing users are never deleted or overwritten, new emails are inserted with fresh IDs.

To explicitly import legacy auth data (opt-in):
```bash
php artisan slice:port --import-auth
```
With `--import-auth`, legacy roles/permissions are **normalized and merged** (never wipe-and-replace):
- Legacy roles without `slug` get slugified; reserved slugs (`super-admin`, `super-dev`) are remapped to `legacy-*`
- Legacy permissions are mapped to framework permission slugs where possible
- `role_user` pivots are remapped via natural keys (role slug + user email)
- Post-import reconciliation restores any missing framework roles/permissions

```php
// Programmatic
$portService->executeFromPlan($plan, importData: true, importAuth: true);
```

**Usage:**
```php
// Slice-aware import (recommended)
$dataService = app(SliceDataPortService::class);
$result = $dataService->importForSlices($sliceMapping, 'laraslice_source');

// Analyze seeders for reference data
$seeders = $dataService->analyzeSeeders('/path/to/source', $sliceMapping);
// Returns: ['CurrencySeeder' => ['tables' => ['currencies'], 'slices' => ['Settings'], 'is_reference_data' => true]]
```

### 16.13 Developer Role & RBAC Hardening (v1.6.4+)

**`super-dev` role** — the framework developer persona with whole-system bypass:
- `Access::isSuperDev()` checks `roles.slug = 'super-dev'`
- `Access::allows()` grants everything to super-dev (like super-admin)
- `HasSlicePermissions::hasPermission()` treats super-dev as privileged
- Created by `slice:install` and by migration `2026_10_17_000000_add_super_dev_role` for existing installs
- Protected from deletion in the Roles UI and from ETL wipes
- Sync via `SliceManager::syncPermissions()` assigns all permissions to both super-admin and super-dev

### 16.14 The Code-First Ground Truth Principle & Zero-Infrastructure Clutter Standard

In enterprise modernization and reverse-engineering, **the codebase is the single indisputable source of ground truth**.

#### 1. The Code-First Principle ("Code is Reality, BRD is Contextual Reference")
- **BRDs Drift; Code Runs**: Business Requirements Documents (BRDs), architecture PDFs, and planning specs represent the initial aspirational vision. Over years of active production operation, features are refactored, edge cases are patched, approval chains change, and real routes diverge from the original documentation.
- **Verification Workflow**: When porting any legacy application to LaraSlice Vertical Slice Architecture (VSA), AI assistants and developers must prioritize **live code analysis** (routes in `routes/web.php`, middleware guards, controller actions, Blade navigation templates, and active database tables) over static documentation.
- **Discrepancy Resolution**: When a discrepancy arises between the BRD and the code (e.g. BRD mentions a role or stage not present in the code, or the code introduces role-gated menus like `Hearing Order Processing` for Advisors), **the code wins**. The BRD provides semantic context and user intentions; the code dictates operational realities.

#### 2. The Zero-Infrastructure Clutter Standard
- Framework plumbing and internal queuing/caching tables (`password_reset_tokens`, `job_batches`, `failed_jobs`, `jobs`, `cache`, `cache_locks`, `sessions`, `migrations`, `personal_access_tokens`, `telescope_*`, `pulse_*`, `horizon_*`) must **never** be treated as business slices, nor dumped onto the proposed sidebar or navigation!
- These tables are automatically intercepted and quarantined by `SlicePortService::FRAMEWORK_INFRASTRUCTURE_TABLES`. They are excluded from table analysis and vertical slice generation.

#### 3. Authentic Navigation Discovery from Blade Components
- The porting engine inspects source Blade navigation components (`resources/views/components/**/sidebar*.blade.php`, `layouts/navigation*.blade.php`, `partials/sidebar*.blade.php`).
- Rather than guessing labels from raw route slugs (`system.complaints.received` -> "System complaints received"), the parser extracts:
  - **Real Human Anchor Text**: Inside `<span>...</span>` or anchor body (`"Received Complaints"`).
  - **Authentic Role Gates**: Preceding `@if(in_array($role, [...]))`, `@role(...)`, or `@can(...)` directives.
  - **Icon Telemetry**: Image assets or SVG tags mapped directly to standardized BlatUI / Lucide icons (`inbox`, `folder-open`, `file-signature`, `calendar-check`, `bell`, `users`).
  - **Context Separation**: Distinguishing administrative back-office portals (e.g. `Judicial Secretariat`) from public stakeholder portals (e.g. `Citizen / Complainant Portal`).

#### 4. Domain Bounded Aggregates vs. Reference Taxonomies
- In relational databases, lookup/taxonomy tables (e.g., `provinces`, `districts`, `regions`, `tehsils`, `genders`, `statuses`, `types`, `organizations`) naturally have high incoming reference counts (in-degrees) because almost every business entity points to them.
- High in-degree must **never** promote a lookup table into an independent top-level root slice.
- All lookup and taxonomy tables are consolidated into a unified `MasterData` (for Adjudication/Legal) or `ReferenceData` (for ERP/Commerce) slice, preventing sidebar pollution.
- Core operational entities with dates, notes, and lifecycle events (e.g. `Hearings`, `Notices`, `Representations`, `Complaints`) are first-class aggregate roots, never misclassified as many-to-many pivots.

---


## 17. Declarative UI Schema, TypeSafe Jev UI Synthesis & Modular YAML Standards

### 17.1 The Workday / Jev UI Synthesis Paradigm
Rather than having an LLM slowly generate raw HTML/CSS strings on the fly (which takes 20-30 seconds, creates hallucinations, causes CSS leaks, and breaks responsive design), LaraSlice enforces the **TypeSafe Jev component selection paradigm**:
1. **Catalog Selection instead of Generation**: The AI engine (`JevDecisionService`) and code generator select exclusively from pre-designed, WAI-ARIA accessible **BlatUI components** (`<x-ui.*>`).
2. **"A Shape Nobody Anticipated Cannot Appear on Screen"**: Every table column, filter, form section card, and action button is picked from a deterministic set of typed primitives.
3. **Sub-Second Execution (70–500ms)**: Fast classification and schema composition guarantees real-time UI synthesis without latency.
4. **"Cage with a Door" Fallback**: High-confidence choices render the exact BlatUI widget immediately. If confidence drops below 50%, it falls back cleanly to an interactive prompt or standard fallback view.

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

## Branch notes (`feat/workflow-bulk-actions`) — keep in sync with code

### Slice YAML v0.1
- Docs: `docs/SLICE_YAML_V0.1.md`, `docs/SLICE_YAML_STRATEGY.md`
- Machine schema: `schemas/slice-v0.1.json`
- `php artisan slice:validate {path|path.yaml}` or `--all` (CI exit 1)

**Closed formatters:** text, text_with_badge, status_pill, date_time, date_overdue, money_breakdown, progress_bar, code_badge, composite_user, number

**`type` vs `input`:** `type` = storage; `input` = form widget (`select`, `textarea`, …). Never use `type: select`.

### Phase 2 DataTable parser
`LaraSlice\Porting\DataTableUiParser` — static classification of source `*DataTable.php` into the formatter vocabulary.

```bash
php artisan slice:repair --re-infer-ui --source=/path/to/erp
```

### Runtime directory columns
`LaraSlice\Support\DirectoryUi::columnsForSlice($name, $fallback)` resolves `slice.json` from the **registered** slice path (Composer path-repo, vendor, or `app/Slices`). Index blades call this at runtime — do not rely on `__DIR__` or paths baked only at repair time.

```bash
php artisan slice:repair          # re-inject DirectoryUi loader; merge config gaps
php artisan slice:repair --publish-ui
php artisan view:clear
```

### MFA local toggle
```env
LARASLICE_MFA_ENABLED=false   # default true — never disable in production
```
`slice:repair` merges missing keys (e.g. `auth.mfa_enabled`) into published `config/laraslice.php`.

---

## Porting non-Laravel sources (e.g. Dolibarr)

`slice:port` / Porting Studio accept **any project tree**, not only Laravel.

### What is analyzed

| Source signal | Used when |
|---------------|-----------|
| Live DB via `--source-*` | **Preferred** — real tables + row counts |
| `database/migrations` | Laravel projects |
| `*.sql` dumps under `install/`, `sql/`, `schema/`, … | Dolibarr, WordPress dumps, mysqldump |
| Target app DB | **Never by default** (was a bug). Opt-in: `LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=true` |

### Required for non-Laravel (Dolibarr)

Point at the **legacy database** (or ship SQL schema files in the tree):

```bash
# Clone or path to Dolibarr code
php artisan slice:port /path/to/dolibarr \
  --domain=ERP \
  --source-host=127.0.0.1 \
  --source-port=3306 \
  --source-database=dolibarr \
  --source-username=root \
  --source-password=secret \
  --dry-run

# Or named connection in config/database.php
php artisan slice:port /path/to/dolibarr \
  --source-connection=dolibarr_legacy \
  --dry-run
```

If you only have the code tree and **no** DB and **no** `CREATE TABLE` SQL dumps, the command **fails with a clear error** instead of reading LaraSlice’s own SQLite/MySQL.

### GitHub URL (Porting Studio)

1. Shallow clone (`git clone --depth 1`)
2. Static analysis (migrations / SQL dumps / optional live DB)
3. Draft `slice.yaml` via Phase 1+2 inference

No LLM required for the draft. AI helps later in Studio refinement.

### Env

```env
LARASLICE_PORT_SOURCE_CONNECTION=   # optional default connection name
LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=false  # keep false in real ports
```

### Table prefix stripping

Default prefixes stripped from slice names: `llx_`, `wp_` (config `laraslice.porting.strip_table_prefixes`).

```bash
php artisan slice:port /path/to/dolibarr --strip-prefix=llx_ --dry-run --no-interaction
# llx_categorie_warehouse → CategorieWarehouse
```

`--dry-run` and `--no-interaction` default `--mode=system-base` and do not prompt.

### Product examples (this branch)

| File | Use |
|------|-----|
| `blueprints/examples/kitchen-sink.slice.yaml` | All v0.1 formatters — primary UI QA |
| `blueprints/examples/suites/crm-*.slice.yaml` | CRM suite |
| `blueprints/examples/suites/hr-*.slice.yaml` | HR suite |
| `blueprints/examples/suites/shop-*.slice.yaml` | E-commerce suite |

```bash
php artisan slice:validate blueprints/examples/kitchen-sink.slice.yaml
```


## YAML-only manifests (Phase 4)

Manifests are `slice.yaml` only. JSON-only slices fail to load with a hint:

```bash
php artisan slice:migrate-json-to-yaml --dry-run
php artisan slice:migrate-json-to-yaml
php artisan slice:migrate-json-to-yaml --delete
```

Studio Save shows a YAML diff and writes only after confirm. CLI equivalent:

```bash
php artisan slice:diff Tasks
```
