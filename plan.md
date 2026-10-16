# LaraSlice Enterprise N-Tier Architecture, Multi-Tenancy & Scalable VSA Plan

## 1. Executive Summary & Goals
Transformed LaraSlice from a 2-tier framework (`Domain -> Slice`) into an **Enterprise-Scale N-Tier Vertical Slice Architecture** capable of hosting massive applications (such as 227-table ERPs, multi-tenant SaaS suites, and government platforms) with zero cognitive clutter, high performance, and flexible developer choice.

---

## 2. Core Architectural Pillars & Implementation Status

### Pillar 1: N-Tier Taxonomy & Recursive Slice Discovery (Completed ✅)
- **Hierarchy Tiers:**
  - **Tier 1: Super-Domain / Macro-Suite** (`super_domain`): e.g. `ERP`, `CustomerPortal`, `CorePlatform`.
  - **Tier 2: Bounded Domain / Business Module** (`domain`): e.g. `HumanCapital`, `SalesCRM`, `ProjectExecution`, `FinancialAccounting`, `SupplyChain`, `ServiceDesk`, `Administration`.
  - **Tier 3: Sub-Domain / Slice** (`slice`): The autonomous Vertical Slice with its Aggregate Root and Child tables (e.g. `Invoices`, `Attendance`, `Workforce`, `Leads`, `Tickets`).
- **Engine Implementation:**
  - Upgraded `SliceManager::discover()` to perform **recursive directory discovery** up to 6 levels deep (`app/Slices/{SuperDomain}/{Domain}/{Slice}` or `app/Slices/{Domain}/{Slice}` or `app/Slices/{Slice}`).
  - Added `super_domain`, `domain`, `sub_domain`, `table_prefix`, `multitenant`, and `tenant_key` metadata to `SliceManifest`, `slice.json`, and `slice.yaml`.
  - Added dynamic PSR-4 namespace calculation (`getNamespace()`) reflecting exact folder taxonomy.
  - Added `getSuperDomains()`, `getDomains(?string $superDomain = null)`, and `getHierarchicalNavigation()` in `SliceManager`.

### Pillar 2: Enterprise UI/UX Navigation (App Switcher + Scoped Sidebar) (Completed ✅)
- **Top Bar App Launcher / Module Switcher Ribbon:**
  - Horizontal interactive ribbon of active Bounded Domains (`[All] [Adjudication] [HumanCapital] [FinancialAccounting] [SalesCRM]`).
  - Active button highlighted with primary token, smooth transition and localStorage persistence.
- **Domain-Scoped Sidebar:**
  - Selecting a domain instantly scopes the collapsible sidebar to display only slices belonging to that domain.
  - Active domain indicator pill with 1-click `(x)` clear button in the sidebar header.
- **Global Command Palette (`Ctrl+K`):**
  - Instantly search and jump across all super-domains, domains, and slices.
- **Verified via Chrome Browser Subagent:**
  - Rendered at `http://localhost:7000/adjudication/master_data` and verified 1-click filtering.

### Pillar 3: Pluggable Multi-Tenancy & SaaS Isolation (Completed ✅)
- **Dual Mode Support:**
  - Single-Tenant Enterprise Mode (zero overhead, standard queries).
  - Multi-Tenant / SaaS Mode (column-based `company_id` / `tenant_id` query scoping and runtime tenant switching).
- **Core Abstractions Implemented in `LaraSlice\Core\Tenancy`:**
  - `TenantManager`: Singleton managing active tenant context, identification, and `withoutTenancy(callable $callback)` bypass for background jobs and super-admins.
  - `HasTenant` trait: Eloquent trait with automatic `TenantScope` registration and automatic tenant key assignment during model `creating()`.
  - `TenantScope`: Global query scope applying `where($table.$tenantKey, $tenantId)`.
  - `IdentifyTenant`: HTTP middleware resolving tenant from route params, query string, request headers, or authenticated user's company context.
  - Configured in `config/laraslice.php` under `'tenancy'` key.

### Pillar 4: Flexible Database Table Naming Strategy (Completed ✅)
- **Support both:**
  - **Flat Legacy Tables** (e.g. `invoices`, `employees`, `tasks`, `projects`) for zero-friction monolith porting.
  - **Domain-Prefixed Tables** (e.g. `fin_invoices`, `hrm_employees`, `crm_leads`) for greenfield enterprise apps to eliminate naming collisions and organize large databases.
- Exposed via `table_prefix` in `SliceManifest` and explicit `$table` properties on models.

### Pillar 5: Universal Monolith Reverse-Engineering Engine (Completed ✅)
- **Intelligent Dynamic Graph Analysis (`SlicePortService`):**
  - Completely purged hardcoded legacy entity strings (`complaints`, `hearings`, `notices`, `ComplaintStatusSeeder`).
  - Implemented multi-block `Schema::create` migration parsing (accurately introspects consolidated initial migrations like `create_worksuite_new_table.php`).
  - In-degree foreign key graph analysis with tenant/audit key exclusion so that business slices remain pure and autonomous.
  - Auto-detection of enterprise multi-tenancy: checks frequency of `company_id` and `tenant_id` across all database tables.
  - Dynamic seeder scanner: dynamically discovers source seeders, extracts arrays, and synthesizes fallback deterministic baseline seeders with zero blank dropdowns.

---

## 3. Real-World Benchmark: `e:\laragon\www\erp` (227 Tables Tested)
Successfully introspected `e:\laragon\www\erp` via both CLI `php artisan slice:port "e:/laragon/www/erp"` and Blueprint Studio UI:
- **Discovered Tables:** 227 total database tables.
- **Multi-Tenancy Detection:** `company_id` detected on 142 tables (62.6% database coverage).
- **Auto-Equipped:** Slices automatically tagged with `multitenant: true, tenant_key: company_id`.
- **Primary Aggregate Roots Discovered:**
  - `Projects` (18 attached child tables: `project_milestones, project_files, project_time_logs, project_members, project_ratings...`)
  - `Leads` (8 attached child tables: `lead_files, lead_follow_up, lead_notes, proposals, deal_notes...`)
  - `Estimates` (4 attached child tables: `accept_estimates, estimate_items, estimate_item_images...`)
  - `Orders` (2 attached child tables: `order_items, order_item_images`)
  - `EmployeeShifts` (4 attached child tables: `attendances, employee_details, employee_shift_schedules...`)
  - `InvoiceSettings / Invoices` (5 attached child tables: `invoice_items, invoice_files, recurring_items...`)
  - `MasterData` (14 taxonomy tables: `client_categories, contract_types, designations, leave_types, unit_types, pipeline_stages...`)

---

## 4. Phase Execution Summary

- [x] **Phase 1: Recursive Discovery & Manifest Taxonomy**
  - Upgraded `SliceManager::discover()` to scan recursive directory depths.
  - Extended `SliceManifest.php` with `super_domain`, `domain`, `sub_domain`, `table_prefix`, and `multitenant`.
  - Added dynamic namespace resolution and hierarchical navigation.

- [x] **Phase 2: Enterprise Navigation UI/UX**
  - Implemented top-bar Domain / Module Switcher Ribbon component in `app.blade.php`.
  - Implemented domain-scoped sidebar rendering with active domain indicator and clear button.
  - Added global command palette (`Ctrl+K`) and dark/light mode toggle.
  - Verified in Chrome browser subagent.

- [x] **Phase 3: Multi-Tenancy Architecture**
  - Implemented `TenantManager`, `TenantScope`, `HasTenant` trait, and `IdentifyTenant` middleware.
  - Added tenant configuration in `config/laraslice.php`.

- [x] **Phase 4: Monolith Reverse-Engineering & Porting Engine**
  - Upgraded `SlicePortService.php` to universal dynamic graph analysis with zero hardcoded strings.
  - Added multi-block migration parser and dynamic taxonomy seeder generator.
  - Tested against `e:\laragon\www\erp` (227 tables) with 100% success.

- [x] **Phase 5: Dual Synchronization & Testing**
  - Maintained 100% sync between `E:\Apps\message\LaraSlice` and `E:\laragon\www\fresh-laraslice\vendor\hereafter\laraslice`.
  - Tested in Chrome browser at `http://localhost:7000/laraslice/wizard/blueprint` and `http://localhost:7000/adjudication/master_data`.

- [x] **Phase 6: Full Clean-Slate End-to-End Monolith Porting & Dual System Validation**
  - Wiped existing test slices and performed fresh migration on `fresh-laraslice-new`.
  - Scaffolded **101 physical vertical slices** across 10 bounded modules (`ProjectExecution`, `SalesCRM`, `FinancialAccounting`, `HumanCapital`, `Operations`, `ReferenceData`, `SupplyChain`, `ServiceDesk`, `Administration`).
  - Migrated 101 slice database tables with defensive `hasTable` guards.
  - Seeded standard reference data and taxonomies with zero blank selects.
  - Executed `php artisan slice:install` to sync **471 RBAC permissions** and assign `super-admin` role to `admin@laraslice.com`.
  - Validated 707 active routes across all slices.
  - Completed end-to-end CRUD verification in browser on Projects slice (`Alpha Enterprise Modernization`).
  - Tested instant top-ribbon module switching (`SalesCRM`, `FinancialAccounting`, `All`).
  - Logged into legacy ERP (`http://localhost:9026/account/dashboard`) with `admin@example.com` / `123456` and performed side-by-side UX evaluation.
  - Published comprehensive SWOT analysis and backlog in [improvement.md](file:///E:/Apps/message/LaraSlice/improvement.md).
