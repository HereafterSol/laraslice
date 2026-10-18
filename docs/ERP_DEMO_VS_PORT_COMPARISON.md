# ERP Demo vs LaraSlice Port — Page-by-Page Foundation Gaps

**Demo:** https://demo.worksuite.biz (Admin / Employee / Client)  
**Our side:** Port plan from `erp.zip` + Porting/Blueprint Studio on `feat/workflow-bulk-actions`  
**Note:** Full Apply of 222 tables was not executed end-to-end; comparison is **demo UX vs what the port foundation currently produces** (plan, journeys, YAML, generated BlatUI patterns).

---

## 1. Three personas — sidebar reality

### Admin (`admin@example.com`)
| Area | Demo live | Our port plan |
|------|-----------|---------------|
| Dashboard | Private + Advanced | Captured |
| CRM | Leads (Contact, Deals, Forms), Clients | Captured |
| HR | Employees, Leaves, Shift, Attendance, Holiday, Designation, Department, Appreciation | Captured |
| Work | Contracts, Projects, Tasks, Timesheet, Roadmap | Captured (Roadmap often missing as product module) |
| Finance | Proposal → Bank Account (7 items) | Captured |
| Other | Orders, Tickets, Events, Messages, Notice, KB, Assets*, Biometric*, Letter*, Monitor*, Payroll* | Core yes; *addons sparse in zip |

### Employee (`employee@example.com`)
| Demo shows | Demo hides | Our employee journey |
|------------|------------|----------------------|
| Dashboard, Calendar, Leads (contact only), HR (Leaves, Attendance, Holiday, Appreciation), Work (Projects, Tasks, Timesheet), Finance (**Expenses only**), Tickets, Events, Messages, Notices | Clients, Orders, full Finance, Designation/Department admin, Assets | Tasks, Projects, TimeLogs, Attendances, Tickets, Leaves, Notices, Events, Messages — **good direction**; still not a **role-filtered sidebar artifact** |

### Client (`client@example.com`)
| Demo shows | Demo hides | Our client journey |
|------------|------------|-------------------|
| Dashboard, Calendar, Work (Contracts, Projects, Tasks, Timesheet), Finance (Estimates, Invoices, Payments, Credit Note), Orders, Tickets, Events, Notices, Settings | HR, Leads, Clients admin, Messages, full Settings hub | Projects, Invoices, Contracts, Tickets, Payments, Estimates, Orders — **aligned**; again **plan-only**, not runtime nav |

**Foundation gap #1 — Persona nav is first-class product behavior, not post-hoc journey text.**  
Demo enforces modules via `user_modules()` / permissions at menu compile time. LaraSlice must emit **`navigation.roles` / `modules` gates on every sidebar item** and resolve them in `layouts.app`, not only in `journeys.v2`.

---

## 2. Page-by-page UX comparison

### Login
| Demo | LaraSlice today |
|------|-----------------|
| Clear 3-persona demo credentials table | Generic login; MFA can block DX |
| Simple email/password | Stronger security (good) but needs **dev/demo persona switcher** |

**Improve:** Studio “Demo personas” seed (admin/employee/client) + one-click switcher for ported apps.

### Dashboard
| Demo | LaraSlice today |
|------|-----------------|
| KPI cards (Tasks pending/overdue, Projects in progress) | Generic empty dashboard after scaffold |
| Week Timelogs strip + Clock In | Not generated from port |
| Employee Shift roster widget | Not generated |
| Charts (client) | Not generated |
| Persona-specific widgets | Single layout for all roles |

**Foundation gap #2 — Declarative dashboard widgets in `slice.yaml` / domain blueprint.**  
Need `ui.dashboard.widgets[]` (stat, chart, list, clock-in) bound to slice queries and `roles: []`.

### Index (Invoices)
| Demo | LaraSlice data-table |
|------|----------------------|
| Status badge Paid/Unpaid | Badge column possible |
| Money breakdown Total/Paid/Unpaid in cell | Single column only unless custom |
| Date range + Client filter + search | quick_filters partial |
| Bulk select + Export + Recurring + TimeLog Invoice | bulk_actions (export/delete/workflow) — **missing domain actions** |
| Row ⋮ menu | row_actions dropdown |

**Foundation gap #3 — Composite column types + domain bulk actions.**  
Support `Column::moneyBreakdown()`, `Column::avatarRelation()`, and port-detected bulk actions from controller methods (`recurring`, `export`).

### Index (Tasks)
| Demo | LaraSlice |
|------|-----------|
| Priority badge in title cell | Separate column |
| **Inline status dropdown** in grid | Status is display-only unless edit |
| View switcher table / board / calendar | Table only |
| “My Tasks” / Recurring / Export | Partial |
| Hide completed quick filter | Possible via quick_filters |

**Foundation gap #4 — Inline cell editors + multi-view directory.**  
`ui.directory.views: [table, board, calendar]` and `column.editable: status` → workflow transition.

### Detail / Show (inferred from ERP patterns)
| Demo | LaraSlice |
|------|-----------|
| Project tabs: Overview, Tasks, Files, Members, Time | Children as separate routes or repeater |
| Activity / comments timeline | Audit log viewer exists; not embedded |
| Right-rail summary | Limited |

**Foundation gap #5 — Aggregate show layout with tabs from child relations.**  
`ui.show.tabs[]` auto from `hasMany` children + files + activity.

### Settings
| Demo | LaraSlice |
|------|-----------|
| Gear → profile / company settings shell | Settings Hub slice (34 tables) — **correct clustering** |
| Not 80+ sidebar links | Risk if Apply lists every root in sidebar |

**Foundation gap #6 — Apply must never dump taxonomy/settings into main nav.**  
Enforce `navigation.visible: false` for settings_hub children and taxonomies.

---

## 3. What our port foundation already got right

1. **Real menu compile** from `x-menu-item` (groups match demo: Dashboard, HR, Work, Finance…).  
2. **`laraslice.journeys.v2`** admin / employee / client after DX fix.  
3. **Settings Hub** (34 tables) instead of sidebar explosion.  
4. **ETL phases** taxonomies → settings → roots → children → pivots.  
5. **Workflow bulk_actions** seeded from transitions.  
6. **Multi-tenant** `company_id` detection.

---

## 4. Core foundation improvement backlog (priority)

| P | Item | Why (from demo) |
|---|------|-----------------|
| P0 | **Role-scoped sidebar at runtime** from ported `modules`/`permissions` | Employee/client menus are the product |
| P0 | **Persona dashboard widgets** in blueprint | First screen after login |
| P1 | **Inline status + multi-view index** | Tasks board is daily UX |
| P1 | **Composite columns** (money split, avatar+name, priority-in-title) | Invoice/task density |
| P1 | **Show tabs from aggregate children** | Project hub |
| P2 | **Domain bulk actions** discovery from controllers | Recurring invoice, etc. |
| P2 | **Demo persona seeder** for ported ERP | QA three roles in one click |
| P2 | **Module accordion** for 80+ roots in Studio | Developer cognitive load |
| P3 | Wire **source DB** (`erp_db.sql`) for row counts + real enums | Closer to production port |

---

## 5. Verdict

| Question | Answer |
|----------|--------|
| Can we **analyze** ERP into a usable Studio plan for 3 personas? | **Yes** (after journey/nav fixes). |
| Does the **ported app look/behave like the demo** page-by-page? | **Not yet** — plan ≠ applied product UX. |
| Biggest foundation hole? | **Runtime persona navigation + dashboard/index density**, not more YAML fields. |

Next engineering slice: implement **role-gated `navigation` resolution** + **`ui.dashboard.widgets`** + **inline status on directory**, then re-Apply a thin pilot (Projects + Tasks + Invoices) and re-compare against demo screenshots.

---

## Implementation status (foundation pass)

| Item | Status |
|------|--------|
| Role-scoped sidebar (`navigation.roles`) | **Implemented** in SliceManager + port mapping |
| Settings/taxonomy `visible: false` | **Implemented** |
| `ui.directory.views` table/board/calendar | **Implemented** in port UI builder |
| `ui.directory.inline_edit` status | **Implemented** (YAML) |
| `ui.dashboard.widgets` | **Implemented** (YAML) |
| `ui.show.tabs` from relations | **Implemented** |
| money_breakdown / priority badge columns | **Implemented** |
| Full Apply pilot + live UI parity test | Pending (next: Apply Projects+Tasks+Invoices) |

