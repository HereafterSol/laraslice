# LaraSlice Foundation Roadmap (YAML-centric porting + DX)

Last updated: 2026-10-19 (Muse f78e1f0 + UI richness F2–F4)  
Branch: `feat/workflow-bulk-actions`

Goal: ERP-class porting that is **usable for admin / employee / client**, with **demo-aligned IA**, **Slice Studio as daily driver**, and **JSON + YAML + DB** sync.

---

## Status legend

| Status | Meaning |
|--------|---------|
| DONE | Merged on feature branch |
| IN PROGRESS | Current focus |
| NEXT | Queued |
| LATER | Backlog |

---

## Phase A — Porting foundation (Analyze → YAML)

| # | Item | Status | Notes |
|---|------|--------|-------|
| A1 | Persona nav gates (`navigation.roles`) | DONE | admin / employee / client |
| A2 | journeys.v2 slim schema | DONE | |
| A3 | Settings hub (not N settings roots) | DONE | |
| A4 | ETL apply order | DONE | |
| A5 | Demo-aligned module groups (Work/Finance/HR/CRM…) | DONE | `760779b` |
| A6 | Hide aux/noise from sidebar (`visible: false`) | DONE | ItemTaxes, BillHistories, *Items… |
| A7 | `buildUiFromInspectedFields` + workflow bulk_actions | DONE | `cc644e1` scaffold + studio |
| A8 | `slice:repair --remap-nav` for existing apps | DONE | fix `$changed` init |
| A9 | Menu compiler from source `x-menu-item` | DONE | refine vs live demo still NEXT |
| A10 | Re-Analyze ERP → verify group counts vs demo | DONE | Validate after pull + remap |

---

## Phase B — Slice Studio DX (most-used surface)

| # | Item | Status | Notes |
|---|------|--------|-------|
| B1 | Dual write `slice.json` + `slice.yaml` | DONE | `ManifestRepository::writeBoth` |
| B2 | Studio Config & GitOps panel (roles, views, bulk) | DONE | |
| B3 | `save-studio-config` + `sync-manifest` APIs | DONE | |
| B4 | version_history on every studio save | DONE | |
| B5 | Bulk / row action editors | DONE | basic toggles |
| B6 | Workflow transition editor in Studio | DONE | GitOps panel + saveStudioConfig workflow |
| B7 | Domain group picker (Work/Finance/…) in Studio | DONE | navGroup select + save to YAML |
| B8 | Slice Studio UX polish (density, empty states) | DONE | |

---

## Phase C — Runtime UI (demo parity)

| # | Item | Status | Notes |
|---|------|--------|-------|
| C1 | `money_breakdown` formatter | DONE | |
| C2 | `text_with_badge` (priority) | DONE | |
| C3 | Table / board view switcher | DONE | |
| C4 | Calendar month grid | DONE | |
| C5 | Inline status → workflow transition POST | DONE | |
| C6 | `slice:repair` patch index data-table attrs | DONE | |
| C7 | `--publish-ui` BlatUI stubs | DONE | |
| C8 | Full calendar component (events drag) | LATER | |
| C9 | Dashboard widgets from `ui.dashboard` | DONE | index KPI strip + Studio editor |
| C10 | Show-page aggregate tabs from relations | DONE | hasMany tabs on generated show |

---

## Phase D — Pilot ERP (Projects / Tasks / Invoices)

| # | Item | Status | Notes |
|---|------|--------|-------|
| D1 | Pilot YAML fixtures | DONE | `resources/pilot/*.slice.yaml` |
| D2 | Apply pilot + migrate + seed | DONE | local: blueprint apply |
| D3 | 3-persona login smoke (admin/employee/client) | DONE | nav + index views |
| D4 | Page-by-page vs demo.worksuite.biz | IN PROGRESS | IA fixed; density still open |
| D5 | Re-scaffold after workflow fix | DONE | `slice:port --scaffold` |

---

## Phase E — Hardening

| # | Item | Status | Notes |
|---|------|--------|-------|
| E1 | Port Execute reports slice counts + errors (UI parity) | DONE | Execute needs plan; logs slices_created |
| E2 | Regression test: buildUiFromInspectedFields without workflow | DONE | |
| E3 | Regression test: slice:repair --remap-nav | DONE | |
| E4 | CONTRIBUTING / fork URL | DONE | |

---

## Immediate next steps (this session → next)

1. **DONE:** Fix `$changed` before `--remap-nav` in `slice:repair`.
2. **DONE:** Porting Studio Execute shows real slice write counts + per-slice errors.
3. **DONE:** Domain group picker (Work/Finance/HR…) in Slice Studio.
4. **DONE:** Dashboard widgets from `ui.dashboard` on generated index + Studio editor.
5. **DONE:** Workflow transition editor in Studio (B6).
6. **DONE:** Show-page aggregate tabs from relations (C10).
7. **NEXT:** Local pilot re-apply + 3-persona checklist vs demo (D2–D5).
8. **NEXT:** Regression tests for workflow UI build + remap-nav (E2–E3).

---

## Phase F — UI richness (post-Muse handoff)

| # | Item | Status | Notes |
|---|------|--------|-------|
| F1 | Muse ETL/column map/row-actions/tests | DONE | tip `f78e1f0` |
| F2 | Sidebar preview noise filter (theme/profile) | DONE | this commit |
| F3 | Porting Studio persona preview toggle | DONE | admin/employee/client |
| F4 | Directory density (compact rows) | DONE | data-table density prop |
| F5 | Full calendar drag-drop | LATER | was C8 |
| F6 | Live 3-persona E2E vs demo screenshots | NEXT | operator on host app |
| F7 | Phase 1 formatter inference (status/progress/due/assignee) | DONE | handoff UI richness |
| F11 | `slice:repair --re-infer-ui` for existing slices | DONE | Muse `b72820c` |
| F12 | Dual-write conflict rule documented (JSON wins runtime) | DONE | |
| F13 | YAML-only elimination (spec) | LATER | after branch merge |
| F8 | DataTable.php AST parser (Phase 2) | LATER | |
| F9 | AI → YAML UI (Phase 3) | LATER | |
| F10 | Craft-style slice:sync apply/rebuild/check | LATER | 

Merged Muse work at `f78e1f0`. Handoff doc `GROK-HANDOFF-UI-RICHNESS.md` was local to Muse workspace (not in repo).

---

## Operator commands (after pull)

```bash
git pull origin feat/workflow-bulk-actions
php artisan slice:repair --remap-nav --publish-ui
php artisan slice:sync
# optional full scaffold after workflow fix:
# php artisan slice:port <path> --domain=ERP --scaffold
```
