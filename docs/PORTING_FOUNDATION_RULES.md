# Porting foundation rules (source of truth)

Official repo: https://github.com/HereafterSol/laraslice  
Branch policy: feature branches + PR to `master` only (never push `master` directly).

### Porting foundation rules (ERP-class benchmark)

1. **Source of truth order**: DB schema + FKs → **real menu/nav** (`menu.blade.php` / `x-menu-item`) → routes/controllers → status enums → optional journey steps.
2. **Do not invent BRD essays** as journeys. Journeys must be operational: `persona → steps[{slice, action, route?}]` (`schema: laraslice.journeys.v2`). Admin / Employee / Client match typical multi-tenant ERP roles when an ERP signature is detected.
3. **Navigation compiler**: Prefer `parseXMenuItemBlade` / blade sidebars; map groups to Porting Studio `sidebar_preview` with modules/permissions hints. Never replace gated menus with generic table lists.
4. **Aggregates over table explosion**: `*_items` / `*_lines` are children; `*_settings` / `*_types` go to Settings Hub or taxonomy — not sidebar noise.
5. **Studios after port**: Blueprint/Slice Studio edit YAML (`ui.directory`, workflow, bulk_actions); Porting Studio shows real nav + slim journeys for customization before Apply.
6. **Every project differs**: Process-core domains differ from multi-module ERP suites. Detect signatures; do not force process-core packs onto ERP or vice versa.
7. **All domains use `laraslice.journeys.v2`**: ERP → admin/employee/client; process-core/adjudication → admin/complainant/mobile; other → operational nav-derived personas. No mermaid BRD novels in the port plan.
8. **100% port checklist (ERP benchmark)**: aggregates + settings hub + gated sidebar + enum workflows + persona journeys + Studio-editable YAML + ETL order. Apply must leave Porting/Blueprint/Slice Studio useful for customization.

## Runtime persona foundation (demo parity)

9. **`navigation.roles` / `navigation.personas`** on every slice (YAML): list of role slugs (`admin`, `employee`, `client`). `SliceManager::getNavigableSlices()` hides items the current user cannot access. Super-admin bypasses.
10. **`navigation.visible: false`** for `settings_hub`, taxonomies, and pure config slices — never dump 80 roots into the main sidebar.
11. **`ui.directory.views`**: `table` always; add `board` when status/workflow exists; add `calendar` for date-heavy slices (Tasks/Events parity).
12. **`ui.directory.inline_edit`**: e.g. `['status']` for workflow status pills (inline transition like ERP Tasks).
13. **`ui.dashboard.widgets`**: YAML-declared KPI/stat/board_preview widgets with optional `roles` gates.
14. **`ui.show.tabs`**: overview + hasMany relation tabs + activity (Project hub parity).
15. **Column formatters**: `money_breakdown` (total/paid/unpaid), `status_pill`, `code_badge`, `text_with_badge` (priority on title).

All of the above must survive Blueprint Apply → `slice.json` / `slice.yaml` GitOps so Studios remain the customization surface.


## Rule: Sidebar module groups (demo parity)

- **Domain namespace** (e.g. `ERP`) is the code/folder boundary — not every sidebar section.
- **`navigation.group`** must use **short demo-aligned labels**: Work, Finance, HR, CRM, Orders, Tickets, Events, Messages, Settings.
- Never stamp long internal names (`FinancialAccounting`, `HumanCapital`, `ServiceDesk`) as sidebar groups.
- **Primary roots only** in the sidebar: Projects, Tasks, Invoices, Leads, Employees, …
- **Auxiliary / child / tax / history** tables: `navigation.visible: false` (Settings hub or parent tabs).
- Porting Analyze must compile groups from source `menu.blade.php` / x-menu-item **and** the module map; Slice Studio can override group/roles in YAML.
- After upgrading the package: `php artisan slice:repair --remap-nav --publish-ui`

## Post-analyze mandate (AI agents — follow the original)

After **initial Analyze** (`slice:port --dry-run`, Porting Studio Analyze, or §16.5 Phases A–C), agents MUST execute **SKILL §16.5.1** before treating scaffolding as parity:

1. **Nav inventory from source** → `porting/source-nav-inventory.json` or Mind `navigation:` (menu beats table discovery).
2. **Composition map** (§22 Phase 0) → one owner slice per primary menu surface; no child tables in the sidebar unless the source links them.
3. **Hide aux slices** → `navigation.visible: false` on children, taxonomies, lines, histories; primary labels match source (`Employees`, not `Employee Details`).
4. **Repair nav** → `php artisan slice:repair --remap-nav --publish-ui` + `slice:cache`.
5. **Body UI** → directory columns/filters/actions, hub `ui.show.tabs`, unified create/edit where the source uses one form; record gaps in `REPLICA-STATUS.md` (Gates **T** / **V**).

Stopping at "N slices generated" with a flat table list in the sidebar is **not** an ERP-class port complete state.

## Account-backed directory create + Phase D nav (ERP benchmark)

When the source exposes **one operational form** that creates both the login account and the domain row (e.g. Worksuite `POST /account/employees`), the **primary directory slice** should own unified create — not a separate Users slice plus a `user_id` picker on create.

- **Pattern:** extend the slice `*SliceService` `beforeSave` / `afterSave` to create `users`, attach `role_user` (or `roles()->sync()`), then persist the aggregate row (`employee_details`, etc.). Gate the create form with account fields only on **create** (`accountBackedCreate` in the web controller).
- **Gate T evidence:** erp-port-v201 journey **(a)** — port **pass** via `POST …/employee_details`; source `:7001` may still **fail** on legacy `attachRole()` — record as a **port-only** deviation, not a port regression (`porting/deviations.yaml` `gate-t-employee-create`).
- **Phase D nav:** after Analyze + hide aux slices, apply sidebar policy in batches — `php porting/apply-phase-d-nav.php --group=all`, then `sync-visible-nav-roles.php` and `slice:cache`. Full runbook: **erp-port-v201** `porting/PHASE-D-ALL.md` (policy YAMLs per module group; ~27 visible roots; Messages stays hidden until a port slice exists — see `gate-d-messages-nav`).
