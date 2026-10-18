# LaraSlice Foundation Roadmap (YAML-centric porting + DX)

Last updated: 2026-10-18  
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
| A10 | Re-Analyze ERP → verify group counts vs demo | NEXT | Validate after pull + remap |

---

## Phase B — Slice Studio DX (most-used surface)

| # | Item | Status | Notes |
|---|------|--------|-------|
| B1 | Dual write `slice.json` + `slice.yaml` | DONE | `ManifestRepository::writeBoth` |
| B2 | Studio Config & GitOps panel (roles, views, bulk) | DONE | |
| B3 | `save-studio-config` + `sync-manifest` APIs | DONE | |
| B4 | version_history on every studio save | DONE | |
| B5 | Bulk / row action editors | DONE | basic toggles |
| B6 | Workflow transition editor in Studio | NEXT | edit states/transitions in YAML UI |
| B7 | Domain group picker (Work/Finance/…) in Studio | NEXT | avoid free-text wrong groups |
| B8 | Slice Studio UX polish (density, empty states) | NEXT | |

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
| C9 | Dashboard widgets from `ui.dashboard` | NEXT | render KPI strip on index |
| C10 | Show-page aggregate tabs from relations | NEXT | |

---

## Phase D — Pilot ERP (Projects / Tasks / Invoices)

| # | Item | Status | Notes |
|---|------|--------|-------|
| D1 | Pilot YAML fixtures | DONE | `resources/pilot/*.slice.yaml` |
| D2 | Apply pilot + migrate + seed | NEXT | local: blueprint apply |
| D3 | 3-persona login smoke (admin/employee/client) | NEXT | nav + index views |
| D4 | Page-by-page vs demo.worksuite.biz | IN PROGRESS | IA fixed; density still open |
| D5 | Re-scaffold after workflow fix | NEXT | `slice:port --scaffold` |

---

## Phase E — Hardening

| # | Item | Status | Notes |
|---|------|--------|-------|
| E1 | Port Execute should optionally scaffold (UI parity with CLI) | NEXT | Studio green ≠ files written |
| E2 | Regression test: buildUiFromInspectedFields without workflow | NEXT | |
| E3 | Regression test: slice:repair --remap-nav | NEXT | |
| E4 | CONTRIBUTING / fork URL | DONE | |

---

## Immediate next steps (this session → next)

1. **DONE this turn:** Fix `$changed` before `--remap-nav` in `slice:repair`.
2. **NEXT:** Wire Porting Studio **Execute** to optional scaffold (or clear UI copy: Analyze ≠ scaffold).
3. **NEXT:** Domain group picker + primary-only nav defaults in Slice Studio.
4. **NEXT:** Dashboard widgets from YAML on generated index.
5. **NEXT:** Local pilot re-apply + 3-persona checklist vs demo.

---

## Operator commands (after pull)

```bash
git pull origin feat/workflow-bulk-actions
php artisan slice:repair --remap-nav --publish-ui
php artisan slice:sync
# optional full scaffold after workflow fix:
# php artisan slice:port <path> --domain=ERP --scaffold
```
