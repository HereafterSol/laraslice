# Porting reference — enterprise analysis & data porting

Sections 15–16 of the porting skill: N-tier taxonomy, multi-tenancy, the zero-hardcoding mandate, AI-first source analysis, data porting, and RBAC hardening. Verbatim; the core SKILL.md routes here.

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
- **Relations resolve to real models**: an FK's target comes from schema evidence (live `information_schema` constraints, or `foreign(...)->on(...)` / `foreignId(...)->constrained(...)` in migrations), never from the column's name alone — `department_id` references `teams`, and a name-derived `Department` relation throws at first use. When the referenced table's model differs from the column stem, the field carries `relation: {model: <target>}` and the generator emits against it.
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

### 16.5.1 Post-Analyze Original Fidelity Mandate (follow the source — mandatory for replicas)

**Purpose:** Analyze tells you *what exists* in the database; it does **not** license shipping the default porter output (one navigable slice per table, generic CRUD labels, BlatUI shell only). For any port that targets **behavioural or visual parity** with a legacy system, the AI agent MUST treat Analyze as **Phase 0 input** and execute this mandate **before** claiming Gate F/T progress or stopping work.

**When this applies:** ERP / multi-module suites, explicit "replica" or "match original" goals, or any project with `porting/REPLICA-STATUS.md`. Skip only when the owner documents **greenfield** (no source UI to follow).

**Hard stops (do not declare port "done" if any are true):**

| Signal | Meaning |
| --- | --- |
| Sidebar item count ≫ source primary menu count | Table explosion still visible — run nav compile + `visible: false` on children |
| Nav label is table name (`Employee Details`) not source label (`Employees`) | Remap `navigation.label` / Mind map from source menu |
| Create is per-child slice while source uses one composed form | Missing §22 Phase 0 composition (account-backed directory) |
| `ui.show.tabs` has only Overview + Activity on a source **hub** | Hub parity not started — §16.6 Step 2 + §19 |
| No `porting/source-nav-inventory` or equivalent artifact | Analyze was not tied to real menu |

**Phase D — Original fidelity (run in order after §16.5 Phases A–C):**

1. **Source nav inventory (authoritative over slice discovery)**
   - Extract the gated menu from `menu.blade.php`, `x-menu-item`, layout sidebars, and module permission keys — same order as §12 rule 1.
   - Write `porting/source-nav-inventory.json` (or Mind `navigation:` in `system.yaml`) with: `group`, `label`, `legacy_route`, `permission`, `children[]`.
   - **Sidebar groups** use demo-aligned names only: Work, Finance, HR, CRM, Orders, Tickets, Events, Messages, Settings (`docs/PORTING_FOUNDATION_RULES.md`).

2. **Composition map (§22 Phase 0 — before celebrating schema)**
   - For each primary nav item, name the **owner slice** and every model the source **create / show / edit** touches (e.g. Employees = `users` + `employee_details` + role attach; Invoice document = header + items + totals).
   - Record in `porting/composition-map.json` or the port plan `compositions[]`. Child tables NEVER get their own sidebar row unless the source menu has a dedicated link.

3. **Nav convergence on Hearts**
   - Primary roots: `navigation.visible: true`, `navigation.label` = source label, `navigation.group` = compiled group, `navigation.roles` / `personas` from source guards.
   - Auxiliary / child / `*_items` / `*_lines` / taxonomies / histories: `navigation.visible: false`; expose via parent `ui.show.tabs`, Settings hub, or document workspace.
   - Run: `php artisan slice:repair --remap-nav --publish-ui` then `php artisan slice:cache`.
   - **Port-tree helper (example):** policy YAML + `porting/apply-phase-d-nav.php` on benchmark ports (e.g. `erp-port-v201` HR group) — replicate pattern per `navigation.group`.
   - **Acceptance:** navigable slice count in sidebar ≈ source primary menu (± Settings/Studio entries), not "one row per migrated table".

4. **Page-level fidelity per primary surface (original behaviour, BlatUI presentation)**
   - **Directory:** Match source columns, filters, bulk actions, and row actions in `ui.directory` — not only DB `show_in_list` flags.
   - **Create / edit:** One transaction when the source uses one form (`ui.forms` sections + account-backed create in owner slice); map modal vs full-page from source (`ui.presentation`).
   - **Show / hub:** `ui.show.tabs` for every sub-entity the source shows as tabs on the profile/workspace (§16.6 Step 2, §19).
   - **Dialogs / AJAX:** Map to BlatUI drawer/modal patterns; document unmigrated legacy JS in `porting_notes` and Gate T matrix — do not silently drop Invite / Import / custom row actions.

5. **Evidence & gates**
   - Update `porting/REPLICA-STATUS.md`: lowest gate wins; name **T** and **V** explicitly for nav/hub/CRUD gaps.
   - Pair flagship surfaces (side-by-side URLs or PNG pairs); classify deltas **D / M / S** per §22 Gate V.
   - Run Gate T journeys from the composition map (full form fill, same DB outcome in source and port).

**AI workflow rule:** After Porting Studio **Analyze** or chat-based schema review, the **next** user-visible plan MUST include Phase D steps 1–5 — not "apply migrations" alone. If the user asks only for data import, still flag nav/composition debt in `REPLICA-STATUS.md`.

**Relationship to skills vs core:** LaraSlice **core** supplies gated nav, Body `ui:`, hub tabs, and repair commands. **Failure to run this mandate** is an agent/port-programme gap (erp-port-v201 lesson: 148 slices, 0 `visible: false`). **BlatUI chrome** vs legacy theme remains Gate **S** unless appearance tier is in scope.

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
