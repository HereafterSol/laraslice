# Porting reference — extraction workflow

Sections 12–14 of the porting skill: the step-by-step reverse-engineering workflow, the phased migration standard, and the pre-suggestion inspection protocol. Verbatim; the core SKILL.md routes here.

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
10. **Parity claim language (24 Oct 2026)**: A port claim must name its tiers — data, availability, structure, behaviour, appearance. Structural/behavioural gates passing does NOT license "100% replica" in any release, doc, or landing copy unless the appearance tier (source design tokens + form presentations, captured by `slice:port --anatomy` design capture and checked by the visual gate) also passes or its deviations are recorded and approved. When in doubt, write "structural replica" — it is the stronger claim because it is the true one.
11. **Post-analyze original fidelity (mandatory)**: Initial Analyze / `slice:port --dry-run` / schema scaffolding is **not** a finished port. For benchmark replicas (ERP-class), the agent MUST run **§16.5.1** immediately after Analyze — compile nav and compositions from the **source** menu, routes, and pages; hide child slices; map create/edit/show/hub/dialog patterns to Body `ui:` — before treating list CRUD as parity. Stopping at "N slices generated" with every table in the sidebar is a **process failure**, not a framework limitation.

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
3. **Write the behaviour inventory (mandatory recon artefact)**: Schema and templates do not carry behaviour — controllers do. For every controller, enumerate its non-CRUD behaviour: custom actions (convert / send / remind / approve / generate), store/update side effects (computed totals, status defaults, multi-model writes), validation beyond presence, document builders (line items, taxes, discounts), and background effects. Land **one row per behaviour** in the port's `porting/port-matrix.csv` (priority by user impact; evidence = `source: <Controller>@<method>` until a gate measures the port). A behaviour with no declaration home is entered in the deviation register NOW — priced at recon, not discovered at Gate T. Method: read the method, not the route name — a `store()` that also writes items, stamps a status and mails a link is three behaviours and three rows. Full method: `references/port-matrix.md`.

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
