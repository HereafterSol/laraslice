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
