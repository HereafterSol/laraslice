# ERP Porting DX — Improvement Plan (from live ERP analysis)

**Source:** extracted ERP monolith (`erp.zip`) — 222 tables, multi-tenant `company_id` (64% coverage).  
**Branch:** `feat/workflow-bulk-actions`  
**Date:** 2026-10-18

## What worked (usable for 3 personas)

| Check | Result |
|-------|--------|
| Schema inspect | 222 tables → 86 slices in ~0.2s |
| Settings Hub | 34 config tables → one `Settings` slice (`settings_hub`) |
| ETL order | taxonomies → settings → roots → children → pivots |
| Journeys schema | `laraslice.journeys.v2` |
| Admin journey | 24 nav-driven steps (CRM, HR, Work, Finance) |
| Employee journey | Tasks, Projects, TimeLogs, Attendances, Tickets, Leaves… |
| Client journey | Projects, Invoices, Contracts, Tickets, Payments… |
| Studio API | `POST /laraslice/wizard/port-plan` returns plan + inspection |
| Competitor names | None in presets / journeys / official surfaces |

## DX issues found (and status)

| # | Issue | Impact | Status |
|---|--------|--------|--------|
| 1 | Employee journey only 1 step (`Attendance`) | Employee persona unusable | **Fixed** — priority pillars + HR group filter |
| 2 | Client journey mixed with admin CRM (Leads/Deals) | Client portal wrong | **Fixed** — exclude CRM/HR; prioritize portal slices |
| 3 | `Leaves` → slice `Leaf` (over-singularize) | Broken slice labels | **Fixed** — `routeBaseToSliceName()` canonical map |
| 4 | Duplicate Dashboard sidebar groups | Cluttered preview | **Fixed** — dedupe groups by name |
| 5 | `role_user` as aggregate root | Noise in VSA map | **Fixed** — explicit pivot list |
| 6 | 83 aggregate roots still high | Sidebar/VSA cognitive load | **Open** — further cluster by module in Studio UI |
| 7 | Profile/theme noise in sidebar preview | Non-nav items | **Open** — filter settings-sidebar fragments |
| 8 | Apply/port-execute not smoke-tested end-to-end | Full port path | **Open** — next: apply subset (Settings + Projects) |
| 9 | No live DB for ERP row counts | ETL top_tables sparse | **Open** — wire `erp_db.sql` as source connection |
| 10 | Studio bulk_actions only visible after slice select | Discoverability | **Open** — show sample bulk bar on plan Page & UI tab |

## 3-persona usability verdict

After fixes, **yes — the port plan is usable for all three ERP personas**:

1. **Admin** — full gated menu mirror (dashboard → CRM → HR → work → finance → settings).  
2. **Employee** — work + self-service HR (tasks, projects, time, attendance, tickets, leaves).  
3. **Client** — portal finance/work (projects, invoices, contracts, tickets, payments).

Gates are declared on journeys (`role:admin`, `role:employee`, `role:client`) for Studio/RBAC wiring.

## Recommended next iterations

1. **Module accordion in Porting Studio VSA tab** — collapse 83 roots into the 10 modules already computed.  
2. **Import `erp_db.sql`** into a source connection for real row counts + enum status discovery.  
3. **Apply pilot** — Settings hub + Projects aggregate only, then open Blueprint Studio on generated YAML.  
4. **Persona preview toggle** — switch sidebar/journey preview by admin/employee/client without re-analyze.  
5. **Filter settings-sidebar noise** (`Profile dark theme`, invite member) from `sidebar_preview`.

## How to reproduce

```bash
# with LaraSlice app + this branch
php artisan serve
# Porting Studio → preset ERP → source /path/to/erp → Analyze
# or: POST /laraslice/wizard/port-plan { source, domain: ERP, preset: erp }
```
