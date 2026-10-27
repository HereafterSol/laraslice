<p align="center">
  <a href="https://hereaftersol.com" target="_blank">
    <img src="art/hsol-logo.svg" alt="Hereafter Solutions (H. Sol)" width="280">
  </a>
</p>

<h1 align="center">🍰 LaraSlice Framework</h1>

<p align="center">
  <strong>Clean Architecture & Autonomous Vertical Slice Modular Framework for Laravel & Flutter</strong><br>
  <em>Engineered with purpose by <a href="https://hereaftersol.com" target="_blank">Hereafter Solutions (H. Sol)</a> • Digital Marketing & Growth by <a href="https://brandup247.com" target="_blank">BrandUp</a></em>
</p>

<p align="center">
  <a href="https://packagist.org/packages/hereafter/laraslice"><img src="https://img.shields.io/badge/composer-hereafter%2Flaraslice-orange.svg" alt="Composer Package"></a>
  <a href="https://github.com/hereaftersol/laraslice"><img src="https://img.shields.io/badge/release-v2.0.1-amber.svg" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-red.svg" alt="Laravel Version"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-blue.svg" alt="PHP Version"></a>
  <a href="https://github.com/hereaftersol/laraslice/actions/workflows/tests.yml"><img src="https://github.com/hereaftersol/laraslice/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://flutter.dev"><img src="https://img.shields.io/badge/Flutter-Cross--Platform-cyan.svg" alt="Flutter Support"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="License"></a>
  <a href="https://skills.sh/HereafterSol/laraslice"><img src="https://skills.sh/b/HereafterSol/laraslice" alt="skills.sh"></a>
</p>

<p align="center">
  <code>composer require hereafter/laraslice</code> &bull; <code>npx skills add HereafterSol/laraslice</code>
</p>

---

## Mind · Body · Heart

Every LaraSlice system is described by three plain files anyone can read:

- **Mind** (`system.yaml`) — *why* the system exists: purpose, user journeys, roles (clubs of permissions, with per-person overrides), navigation, workflows, integrations, templates, and operations like backups and sync. A non-technical owner can read and sign this.
- **Body** (`*.ui.yaml`, one per slice) — *what every page looks like*: toolbars, filters with their defaults, columns, tabs, dialogs. Extracted from a legacy app when porting; designed up front when planning; audited against the running app.
- **Heart** (`slice.yaml`, one per slice) — *what runs*: data, fields, relations, UI declarations, workflows, settings. The only runtime truth; the Mind and Body are checked against it, never the reverse.

The **soul** — your design identity (design system, density, refinement) — is declared in the Mind and realized by the Heart's runtime. Porting drafts all three files from the old system; a fresh project writes the Mind first and generates the rest. Full guide: [docs/MIND_BODY_HEART.md](docs/MIND_BODY_HEART.md).

> **Framework vs a finished replica.** LaraSlice’s architecture (Mind · Body · Heart, VSA, gated ports) is separate from **any one legacy port**. Quote replica status **only** from that app’s `porting/REPLICA-STATUS.md` — the **lowest unverified gate** is the truth. Template: [docs/templates/REPLICA-STATUS.md](docs/templates/REPLICA-STATUS.md). Read: [docs/ARCHITECTURE-VS-PORT-TRUTH.md](docs/ARCHITECTURE-VS-PORT-TRUTH.md).

## 🆕 What's new in v2.0.1 — The Verification Patch

v2.0.1 is what an independent verification programme found in v2.0.0 — the full suite on a second platform, a fresh flagship install, and a from-scratch re-port of a production application — fixed:

- **Porting that cannot fail silently.** Combined scaffold + import migrates before it imports and reports skipped tables by name; porting without auth import warns instead of leaving every user permission-less.
- **Porter output that renders.** Generated manifests declare their root model; generated dashboard widgets are real `counter`/`chart` definitions, own-scoped where the slice declares its scope.
- **Fully curatable hubs.** Tab exclusion declarations, audit tabs that render the record's trail, and declared fields-tab labels.
- **Portal scoping, field-validated.** Own-scoped dashboard counters read the source's per-person figures exactly on an independent port. Full suite: 555 tests green on two platforms.

## 🆕 What's new in v2.0.0 — The Foundation Release

v2.0.0 turns the scaffold into a foundation. Everything below was proven live against a running port of a production ERP, compared page-by-page with its source:

- **Mind · Body · Heart** (above) becomes the working model: intent, declared surface, and runtime truth — drafted automatically when porting, written first when planning.
- **Standard UI/UX system.** Lists, forms, hubs, documents, matrices, reports, dashboards, and settings behave properly *by declaration* — the basics work by default, specialized screens are standard YAML patterns (P1–P16), never per-page hacks or per-slice CSS.
- **The layout kit.** A screenshot-read audit found that token-level styling could not see composition, so the kit became a layout kit: the source's shell model on ported trees, a single fused filter band on directories, a declared **hub composition** (`ui.show.composition: overview` — record title in the topbar, fused tab strip, widgets-led overview), and **portal dashboards** (welcome header with live clock + attendance punch, profile card, dual-figure stat cards, own-scope tables, a month-grid calendar).
- **Dev Studio: six modes.** Plan (the Mind, with diff-before-save) and Operate (health, backups, access review, operator manuals) join Port, Design, Create, and Manage — modes are destinations, so from-zero, brief-first, legacy-port, and team workflows are all first-class.
- **The 100% Replica Protocol.** Porting is gated, not vibes: schema parity, join-verified data truth, furniture parity, task conformance, and visual conformance read from paired screenshots — every port keeps a `REPLICA-STATUS.md` and quotes its lowest gate.
- **Foundation hardening.** Own-scope data access with per-person overrides, form hygiene by default, drift audit + reconciliation (`slice:drift`), a fleet-wide idempotent `slice:repair`, document forms with real line-item arithmetic, workflow notifications, and declared patterns for import, invite, seed actions, clock punch, keyed-rows settings, and a server-rendered conversation surface.

Full notes: [CHANGELOG.md](CHANGELOG.md) · [Vision](docs/VISION.md) · Concepts: [docs/MIND_BODY_HEART.md](docs/MIND_BODY_HEART.md) · Protocol: [docs/REPLICA-PROTOCOL.md](docs/REPLICA-PROTOCOL.md) · Lifecycle: [docs/SDLC.md](docs/SDLC.md) · Portability: [docs/RUNTIMES.md](docs/RUNTIMES.md) · Backlog: [docs/BACKLOG.md](docs/BACKLOG.md)

## 🌿 Vision, Mission & Philosophy

> *"Sowing Seeds of Innovation, Harvesting Compassion: Bridging Today’s Technology with Tomorrow’s Generosity."*

**LaraSlice** is proudly conceived, engineered, and maintained by **[Hereafter Solutions (H. Sol)](https://hereaftersol.com)** as an open-source gift to the global developer community.

* **🎯 Our Philosophy**: H. Sol delivers purpose-driven technological solutions to societal and enterprise challenges, blending modern engineering precision with compassion at its core.
* **🔭 Our Vision**: To close the divide between cutting-edge technology and non-tech startups — empowering tomorrow through open tools where innovation, high performance, and collective good unite.
* **🤲 The 1/3 Model (Inspired by Sahih Muslim 7473)**: In the prophetic tradition of the blessed gardener who divides his yield into three equal portions:
  1. **1/3 for Sustenance & Family**: Supporting the dedicated engineers, creators, and operational viability of our collective.
  2. **1/3 Reinvested into Innovation & Tools**: Pouring back into open, robust, enterprise-grade foundations like **LaraSlice** so every developer and startup builds on solid rock.
  3. **1/3 for Sadqah-e-Jariyah as a Service (SJaaS)**: Dedicated unconditionally to charitable initiatives, educational access, and social impact.

LaraSlice is the direct fruit of that 1/3 innovation reinvestment.

---

## 🚀 What is LaraSlice?

**LaraSlice** is an open-source, enterprise-grade application framework that blends **Clean Architecture** with **Vertical Slices** and **Domain-Driven Design (DDD)** for PHP and Dart. 

Instead of scattering a single business capability across fragmented `Controllers/`, `Models/`, and `Views/` folders, LaraSlice organizes your application into **self-contained, feature-centric slices** (`app/Slices/{Feature}`). Each slice encapsulates everything it needs:
- **Contracts (DTOs)**: Read-only, typed Form, Listing, and Filter business objects that define clean boundaries between slices.
- **Data Service**: Dedicated business logic and queries with lifecycle hooks (`beforeSave`, `afterSave`, `validate`).
- **Native RBAC & Security**: Declared capabilities matrix automatically synchronized to database gates (`@can`).
- **Enterprise Audit Trail**: Plug-and-play regulatory compliance tracking (`AuditableSlice`) capturing actor attribution and before/after diffs.
- **REST API Controller**: Pre-wired endpoints for Mobile, Web, and External integrations.
- **Web Controller**: Interactive web views built with **[BlatUI](https://blatui.remix-it.com/)** (Tailwind CSS v4 + Alpine.js + Blade).
- **Flutter Client Slice**: Auto-scaffolded Dart models, typed HTTP clients, and native Flutter mobile/desktop screens.
- **State Machine Workflows**: Declarative state transitions (`Draft -> Pending -> Approved -> Published`) with transition guards.
- **AI & MCP Support**: Native Model Context Protocol (MCP) server for IDE agents (Antigravity, Cursor, Claude).

---

## 🏛️ The Three-Layer Packaging Model (ADR-001)

LaraSlice operates on a pragmatic **Three-Layer Hybrid Model** ([ADR-001](docs/adr/ADR-001-laraslice-packaging-model.md)) that solves the dilemma between upstream framework updates and complete client project ownership:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                  LARASLICE THREE-LAYER ARCHITECTURE (ADR-001)                │
├──────────────────────────────────────────────────────────────────────────────┤
│ Layer 1: hereafter/laraslice (Composer package in vendor/)                   │
│ • Lean shared engine: Slice discovery, caching, audit engine, RBAC bridge,   │
│   declarative schema, generators & CLI. Zero vendor lock-in.                 │
│ • Updated safely via standard Composer and CI/CD pipelines. No web shell.    │
├──────────────────────────────────────────────────────────────────────────────┤
│ Layer 2: laraslice-starter (Template repository cloned once)                 │
│ • Application shell, layout, BlatUI components, theme tokens, and default    │
│   starter slices (Auth, Users, Roles, Settings, Audit Viewer).               │
│ • Owned by the client/agency after initial clone. No upstream merge risk.    │
├──────────────────────────────────────────────────────────────────────────────┤
│ Layer 3: app/Slices/* (Project-owned business slices)                        │
│ • Custom domain slices (Invoices, HR, Procurement, Catalog).                 │
│ • Generation gap pattern: Generated base classes are safe to regenerate;     │
│   hand-written subclasses and contracts are never overwritten.               │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 🌟 Key Capabilities

| Capability | Description |
| :--- | :--- |
| **Vertical Slices (DDD)** | Slices are **Bounded Contexts**, not database tables. Each slice owns its domain aggregate roots and child tables. |
| **Strict Boundary Enforcement** | Automated architecture tests (`tests/Architecture`) verify that slices never import internal models of other slices. Communication occurs strictly via `Contracts/` (DTOs) and public services. |
| **Native Slice RBAC** | Lightweight, zero-vendor-lockin permission engine bridged directly into Laravel's native `Gate::before`. Super-admin possesses default root access avoiding 403 lockouts. |
| **Enterprise Audit Engine** | Immutable compliance tracking in `laraslice_audit_logs`. `AuditableSlice` Eloquent trait records actor email, IP, user-agent, and before/after JSON diffs with sensitive field redaction. |
| **High-Performance Discovery** | `php artisan slice:cache` creates a compiled manifest map for production environments, eliminating filesystem scanning overhead on boot. |
| **BlatUI Frontend** | Pure Blade + Alpine.js + Tailwind CSS v4 design system. 156+ accessible shadcn-styled components. Zero Metronic dependencies. |
| **Flutter Mobile Generator** | Scaffolds native Flutter Dart models, client services, and CRUD UI views with one command (`php artisan slice:export-flutter`). |
| **Declarative Blueprint Studio** | Visual designer and round-trip `slice.yaml` editor with visual field widths (`33%`, `50%`, `100%`) and real-time UI mockups. |
| **Native AI & MCP Server** | Built-in Model Context Protocol server (`/.well-known/mcp`) allowing AI coding agents to inspect schemas and co-author slices. |
| **Slice YAML v0.1** *(new in 1.7.0)* | Declarative manifest spec with closed vocabularies (8 field types, 10 UI formatters). YAML describes intent; the fixed component library (BlatUI, shadcn-based) decides rendering. Machine-readable JSON Schema + `php artisan slice:validate`. |
| **Formatter Pipeline** *(new in 1.7.0)* | Directory columns carry `formatter` metadata YAML → pixels: status pills, progress bars, money breakdowns, overdue dates, code badges — rendered from `slice.yaml` with no per-page code. |
| **Legacy Porting** *(new in 1.7.0)* | `php artisan slice:port` reverse-engineers legacy systems into slices: Laravel monoliths (migrations), non-Laravel PHP (SQL dumps), or live databases. Hard-fails on ambiguous source DB instead of guessing. |
| **Dev Studio Standard** *(new in 1.7.0)* | Unified Dev Studio + CLI contract (`docs/DEV_STUDIO_STANDARD.md`) — the design system every future Slice runtime (NetSlice, PythonSlice, JavaSlice, FlutterSlice) inherits. |
| **Standard UI/UX System** *(new in 2.0.0)* | The basics work by default: directories with fused filter chrome, overlay/page forms, hubs, documents with line-item arithmetic, matrices, reports, and settings hubs — all declared in the Heart, rendered by one standard layer. |
| **Layout Kit & Hub Composition** *(new in 2.0.0)* | Ported trees render under a replica shell + single-band directory chrome; `ui.show.composition: overview` gives detail pages the source's hub composition; portal dashboards compose a personal workspace (profile, dual-figure stats, own-scope tables, calendar). |
| **100% Replica Protocol** *(new in 2.0.0)* | Five porting gates — schema, data (join-verified), furniture, tasks, visual (screenshot-read) — with a per-port `REPLICA-STATUS.md`. A token probe is not a visual gate. |
| **Studio: Plan & Operate** *(new in 2.0.0)* | The ONE Studio grows to six modes: Plan drafts and edits the Mind with diff-before-save; Operate runs health checks, backups, access reviews, and operator-manual generation. |

---

## 📁 Anatomy of a Slice

```
app/Slices/Products/
├── slice.yaml                          # Canonical Slice Manifest
├── Contracts/                          # DTOs & Public Boundary Contracts
│   ├── ProductFormBusinessObject.php
│   ├── ProductListingBusinessObject.php
│   └── ProductSummaryBusinessObject.php
├── Models/                             # Eloquent Models (Aggregate Root & Children)
│   ├── Product.php                     # uses AuditableSlice
│   └── ProductVariant.php
├── Migrations/                         # Database Migrations
│   ├── 2026_01_01_000000_create_products_table.php
│   └── 2026_01_01_000001_create_product_variants_table.php
├── Services/                           # Business Logic & Public Queries
│   └── ProductSliceService.php
├── Controllers/                        # Dual API & Web Controllers
│   ├── ProductApiController.php        # JSON endpoints for Mobile / Flutter
│   └── ProductWebController.php        # BlatUI Blade views for Web
├── Routes/                             # Localized Routing
│   ├── web.php                         # /products/*
│   └── api.php                         # /api/products/*
└── Resources/views/                    # BlatUI Blade Components
    ├── index.blade.php                 # Responsive listing table & search
    └── form.blade.php                  # Create/Edit form
```

---

## 📋 System Prerequisites

> [!IMPORTANT]
> **LaraSlice is a modular package for existing or newly created Laravel applications.**  
> You must have an active **Laravel 11, 12, or 13** project installed before requiring this package.

| Requirement | Supported Versions | Notes |
| :--- | :--- | :--- |
| **PHP** | `^8.2 \| ^8.3 \| ^8.4` | Full typed properties, match expressions, and readonly support |
| **Laravel Framework** | `^11.0 \| ^12.0 \| ^13.0` | Fresh or existing enterprise applications |
| **Database** | MySQL, PostgreSQL, SQLite, MariaDB | Standard Eloquent PDO database drivers |
| **Frontend Assets** | Tailwind CSS v4 + Alpine.js | Embedded natively via BlatUI; zero Node build lock-in |

### Don't have a Laravel project yet?
Create one in seconds using the official Laravel installer or Composer:
```bash
# Via Composer
composer create-project laravel/laravel my-app
cd my-app

# Or via Laravel Installer
laravel new my-app
cd my-app
```

---

## ⚡ Quick Start

**Two doors, one system.** After installing, you can start through the **Studio door** — open the Dev Studio, write the Mind in Plan mode, scaffold slices in Create, shape them in Design — or through the **AI door** — hand your AI assistant the shipped skill (`npx skills add HereafterSol/laraslice`) and brief it with the Mind; it drafts, scaffolds, and verifies by the same declarations. Both doors produce the same Mind · Body · Heart files, and teams mix them freely. From-zero and brief-first starts are first-class; porting an existing system is simply the third door (see the Replica Protocol). Full guide: the framework skill, §24.

### 1. Installation & Setup
```bash
composer require hereafter/laraslice
php artisan slice:install
```
`slice:install` publishes the landing page, runs all core database migrations, and seeds the default Super Admin user (`admin@laraslice.com` / `password`).

### 2. Scaffold Vertical Slices & Domain Suites
Generate a single feature slice or an entire multi-slice domain suite with full CRUD, BlatUI views, and Flutter mobile clients:
```bash
# Scaffold a single slice
php artisan slice:make Invoice --flutter

# Scaffold an entire CRM Domain Suite (multi-slice Bounded Context)
php artisan slice:make Companies Contacts Deals --domain=CRM

# Scaffold an E-Commerce Suite with Flutter mobile clients
php artisan slice:make Products Orders Customers Categories --domain=E-Commerce --flutter
```

This instantly creates:
- `app/Slices/{Domain}/{Slice}/` (Full vertical slices with DTO contracts, models, controllers, and BlatUI views)
- Unified REST APIs and BlatUI collapsible domain sidebar navigation
- `flutter_app/lib/slices/{slice}/` (Optional native Flutter Dart models, API clients, and mobile screens)

### 3. Production Boot Optimization
In production environments, cache discovered slices just like `route:cache`:
```bash
php artisan slice:cache
```
To clear the cache during development:
```bash
php artisan slice:clear
```

### 4. The Dev Studio — six modes, one surface
The ONE Studio (`http://localhost:8000/laraslice/wizard`) is organized as destinations, not steps:
- **Plan** — the Mind made visible: purpose, actors, journeys, and operations as cards, editable in plain-language forms with diff-before-save; `slice:plan:draft` drafts a Mind + Bodies from discovery.
- **Port** — reverse-engineer a legacy system into slices (anatomy capture, twin-port namespacing, per-slice porting notes).
- **Design** — the declarative `slice.yaml` / Body designer with field widths and instant component preview.
- **Create** — scaffold slices and domain suites from presets, blueprints, or a brief.
- **Manage** — lifecycle: seed, toggle, wipe, destroy, repair — mirrored by the CLI.
- **Operate** — health checks, backups against the Mind's declared policy, the audit trail, an access review that flags risky grants, and one-click operator-manual generation.

### 5. Domain Lifecycle & Data Management CLI
Manage slice lifecycle, seeding, and teardown directly from the command line:
```bash
# List all slices with domain grouping
php artisan slice:list
php artisan slice:list --domain=CRM

# Seed realistic demo data into slice tables
php artisan slice:seed Contacts --count=20
php artisan slice:seed --domain=CRM --count=5

# Toggle slice/domain activation (hide/show in navigation)
php artisan slice:toggle Contacts --disable
php artisan slice:toggle --domain=CRM --enable

# Wipe (truncate) all records from slice tables
php artisan slice:wipe Tests --force
php artisan slice:wipe --domain=CRM --force

# Destroy a slice or domain (drop tables, clean migrations, delete files)
php artisan slice:destroy Tests --mode=complete --force
# Modes: complete | code_only | db_only | wipe_data
```

All lifecycle actions are also available in the **Slice Studio UI** (`/laraslice/wizard`) via domain accordion dropdown menus and per-slice action buttons.

---

### 6. Monolith Reverse-Engineering & Legacy System Porting (`slice:port`)

Porting a legacy monolithic Laravel application (such as statutory dispute adjudication portals, education management systems, or internal enterprise ERPs) to clean vertical slices? LaraSlice provides a reverse-engineering engine that automates schema introspection, table classification, taxonomy seeder extraction, and production data migration.

#### Dual-Mode Porting Strategy:
1. **🌱 System Base Mode (`--mode=system-base`) [Clean Development Slate]**:
   - Inspects legacy database schemas and extracts reference master tables (e.g. `complaint_statuses`, `categories`, `regions`, `hearing_types`).
   - Generates deterministic seeders into `database/seeders/` to guarantee **0 blank dropdowns** and an immaculate development slate for testing new workflows.
2. **📦 Full Production Data Mode (`--mode=full-data`) [Production Cutover]**:
   - Topologically migrates all historical records (petitions, notices, hearings, evidence files, approvals) while preserving parent-child IDs and foreign keys.
   - Use `--with-files` to automatically copy physical uploads and document attachments from legacy `storage/app/public`.

#### CLI Execution:
```bash
# Reverse-engineer and port with clean reference seeders
php artisan slice:port e:/laragon/www/legacy-project --domain=Adjudication --mode=system-base

# Full production migration with files and attachments
php artisan slice:port e:/laragon/www/legacy-project --domain=Adjudication --mode=full-data --with-files

# Preview classification and table-to-slice mappings without writing changes
php artisan slice:port e:/laragon/www/legacy-project --domain=Adjudication --dry-run
```

#### Visual Studio GUI (Wizard & Blueprint Studio):
- Launch from `/laraslice/wizard` or `/laraslice/wizard/blueprint` using the **🚀 Port Legacy App** button.
- Provide source project root path and target domain name.
- Click **🔍 Analyze & Preview Mapping** to review the live side-by-side Table-to-Slice Architecture comparison table (Aggregate Roots, Child Tables, Taxonomies, and Pivots).
- Select between **System Base Only** or **Full Production Data**, toggle file attachment copying, and execute in 1 click.

#### Autonomous AI Agent Interaction:
- If working with an AI coding assistant (Antigravity, Cursor, Claude Code) without the UI, the LaraSlice skill instructs the AI to proactively prompt you for:
  1. *Legacy Project Path*
  2. *Target Domain / Bounded Context*
  3. *Data Mode (`system-base` clean slate vs `full-data` production migration)*


## 🛡️ Enterprise Compliance & Audit Logging

Simply attach `AuditableSlice` to any slice model:

```php
namespace App\Slices\Products\Models;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Audit\Traits\AuditableSlice;

class Product extends Model
{
    use AuditableSlice;

    // Passwords, tokens, and sensitive fields are automatically redacted
    protected array $auditExclude = ['internal_cost'];
}
```

Every `create`, `update`, and `delete` operation automatically logs immutable compliance entries in `laraslice_audit_logs`. View the full audit trail and before/after diffs in the Slice Studio Audit & Activity Viewer or query programmatically:

```php
use LaraSlice\Core\Audit\AuditLogger;

$logs = AuditLogger::forSlice('products', limit: 20);
```

---

## 📱 Matching Flutter Client SDK

Every slice generated by LaraSlice generates an identical, type-safe Flutter client:

```dart
// Fetch paginated products from Laravel slice
final apiService = ProductApiService(baseUrl: 'https://api.yourdomain.com');
final products = await apiService.getList(search: 'Laptop', page: 1);

// Render in native Flutter UI
Navigator.push(
  context,
  MaterialPageRoute(builder: (_) => ProductListingView(apiService: apiService)),
);
```

---

## 🧪 Testing & Architectural Enforcement

LaraSlice comes with comprehensive test coverage:

```bash
# Run all unit, feature, and architectural boundary tests
php vendor/phpunit/phpunit/phpunit tests
```

- **Architecture Boundary Tests**: Slices are prevented from coupling to internal models of other slices. Violations fail CI automatically.
- **Audit Engine Tests**: Verifies fail-safe logging and diff generation.
- **RBAC Tests**: Verifies role matrix and Gate bridge behavior.

---

## ❓ Frequently Asked Questions (Developer Guide)

### 1. Where and how do I customize a core slice in my project?
LaraSlice's discovery system is designed so that **any slice in `app/Slices/` automatically overrides the core framework slice of the same name**. 

To customize a core slice, run:
```bash
php artisan slice:publish Users
```
*(or publish all starter slices at once: `php artisan slice:publish --all`)*

This copies the core slice from the framework into `app/Slices/Users`. Once published, it becomes first-class code in your repository: you can edit the models, contracts (DTOs), controllers, and views, and commit them to git. Changes in `vendor/` are never required, and Composer updates will **never** overwrite your published slices.

### 2. Can customized core modules still receive upstream updates?
**Yes.** LaraSlice decouples the framework engine from slice code:
- **Engine Updates**: Base classes (`BaseSliceWebController`, `BaseSliceService`, `SliceSchema`), RBAC bridges, audit engines, and performance caching reside in `vendor/hereafter/laraslice` and update cleanly via `composer update`.
- **Generation Gap Pattern**: Scaffolding separates base definitions in `Generated/Base*.php` from hand-written subclasses in `Models/`.
- **Upstream Starter Remote**: If the LaraSlice team adds new features to starter slices (e.g. 2FA), simply pull from the starter remote (`git fetch upstream && git merge upstream/main`).

### 3. How does LaraSlice compare to conventional plugin models and flat scaffolding?
- **vs. plugin/monolith models**: runtime extension and monkey-patching break IDE auto-completion and static analysis (PHPStan) and tangle debugging. LaraSlice enforces compile-time type safety via clean DTO contracts and Bounded Contexts, with every surface declared in YAML instead of hooked at runtime.
- **vs. flat scaffolding**: starter scaffolding dumps code into monolithic `app/Http/Controllers/` and `app/Models/`. LaraSlice encapsulates complete vertical slices (`Contracts/`, `Models/`, `Migrations/`, `Views/`, `Routes/`, and Flutter clients) under cohesive bounded contexts.

---

## 🤝 Contributing & Team Onboarding

We welcome contributions from fellow developers!

### How do other developers contribute to core slices?

#### 1. Open Source Contributions to Framework Core
1. **Fork the Repository**: [github.com/hereaftersol/laraslice](https://github.com/hereaftersol/laraslice)
2. **Edit the Core Slice**: Make your enhancements directly in `src/Slices/{SliceName}` (e.g. `src/Slices/Users`, `src/Slices/Roles`, `src/Slices/Settings`).
3. **Run the checks**: the test suite, Pint and Larastan must pass (CI runs the same):
   ```bash
   composer test
   composer lint
   ```
4. **Submit a Pull Request**: Push your branch to your fork and open a Pull Request on GitHub.

#### 2. Local Development / Package Testing Workflow
To test changes to the framework live in a host Laravel application before publishing:
1. In your test application's `composer.json`, register LaraSlice as a local path repository:
   ```json
   "repositories": [
       {
           "type": "path",
           "url": "../LaraSlice",
           "options": { "symlink": true }
       }
   ]
   ```
2. Run `composer update hereafter/laraslice`. This creates a symlink/junction, allowing developers to test their framework edits in real time before pushing to GitHub.

---

## 🤝 Partners & Ecosystem

<table width="100%">
  <tr>
    <td width="50%" align="center" valign="top">
      <a href="https://hereaftersol.com" target="_blank">
        <img src="art/hsol-logo.svg" alt="Hereafter Solutions" width="180"><br><br>
        <strong>Hereafter Solutions (H. Sol)</strong>
      </a>
      <p><em>Creator, Lead Architect & Maintainer</em></p>
      <p>Purpose-driven software collective bridging today's technology with tomorrow's generosity. Home of the 1/3 sustainable innovation model & SJaaS.</p>
      <a href="https://hereaftersol.com">hereaftersol.com</a>
    </td>
    <td width="50%" align="center" valign="top">
      <a href="https://brandup247.com" target="_blank">
        <img src="art/brandup-logo.svg" alt="BrandUp" width="160"><br><br>
        <strong>BrandUp</strong>
      </a>
      <p><em>Official Digital Marketing & Growth Partner</em></p>
      <p>Empowering digital growth, strategic brand scaling, performance marketing, and creative presence for modern businesses and tech products worldwide.</p>
      <a href="https://brandup247.com">brandup247.com</a>
    </td>
  </tr>
</table>

**Core Leadership & Engineering**:
- **Abdur Rehman** ([@AbdurRehman712](https://github.com/AbdurRehman712)) — Lead Architect & Creator

**Contributors**:
- **Muhammad Adnan** ([@adnanansari901](https://github.com/adnanansari901)) — Passkeys on HTTP dev domains, user devices schema, host User model & BlatUI Tailwind v4 install fixes

---

## 📄 License

LaraSlice is open-source software licensed under the **[MIT License](LICENSE)**. Free for personal, commercial, and government use.

### Local development: MFA

```env
LARASLICE_MFA_ENABLED=false
```

Skips MFA enrollment and challenge after password login. **For local development and testing only — never disable in production.** Default is `true`.

After upgrading LaraSlice, either run `php artisan slice:repair` (merges missing config keys such as `auth.mfa_enabled`) or re-publish:

```bash
php artisan vendor:publish --tag=laraslice-config --force
```

Published `config/laraslice.php` does not auto-update on composer update.
