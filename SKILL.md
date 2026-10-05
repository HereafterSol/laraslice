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
1. **Fork the Repository**: [github.com/AbdurRehman712/laraslice](https://github.com/AbdurRehman712/laraslice)
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
