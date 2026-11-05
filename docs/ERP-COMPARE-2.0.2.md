# Three-way ERP comparison — source vs flagship demo vs erp-port-v201

**Purpose:** Comparison only (no zero-port). Aligns with `source-nav-inventory.json` and each repo’s `porting/REPLICA-STATUS.md` executive truth.

| Leg | Path | URL | Login |
| --- | --- | --- | --- |
| **A — Source benchmark** | `E:\laragon\www\erp` | http://127.0.0.1:7001 | admin@example.com / 123456 |
| **B — Flagship demo (v2.0.2 zip)** | `E:\laragon\www\flagship-erp-v202-demo` | http://127.0.0.1:7002 | `admin@laraslice.com` / `employee@example.com` (see register); legacy `admin@example.com` |
| **C — Programme port (v2.0.1 + candidate)** | `E:\laragon\www\erp-port-v201` | http://127.0.0.1:7004 | admin@example.com / 123456 |

**References:** `erp-port-v201/porting/source-nav-inventory.json` · `flagship-erp-v202-demo/porting/REPLICA-STATUS.md` · `erp-port-v201/porting/REPLICA-STATUS.md` · `erp-port-v201/porting/PHASE-D-ALL.md` · **Validation:** [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md) ([v2.0.2 demo + roles QA](3d1da053-82cb-4f13-b26b-459bfdd712df))

**Legend (Gate-style notes):** **D** = data / count drift · **M** = missing or weaker widget/surface · **S** = style/chrome/composition only · **—** = parity or N/A

**Measured:** 5 Nov 2026 · Port nav: `php porting/count-visible-nav.php` · Dashboard counters: `php porting/measure-dashboard.php` (copied to flagship for B)

---

## Executive gate truth (lowest gate wins)

| Gate | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **S — Schema** | 358 tables (reference) | **MEASURED PARTIAL** — 62 matched tables lack source columns (classified in `GATE-S-DIFF-2026-10-26.txt`) | **MEASURED ~99.4%** name-match + port-local closure migration; **not** a shipped v2.0.2 framework guarantee |
| **D — Data (benchmark entities)** | Reference DB | **PARTIAL** — audited domains clean; long tail open | **STRONG** — 0 drift errors; benchmark row counts 100% on core entities |
| **F — Furniture (directories + pilot hubs)** | Reference UX | **100%** on paired menu scope (25 Oct crawl); invoice twin fixed QA8 | **~80%+** on 5 entities / 8 surfaces; employee + project hubs pipeline-derived |
| **V — Visual matrix** | Worksuite theme | **CONVERGED** replica kit (26 Oct QA9) with tab-set + timelog residuals | **8 / 17** mapped surfaces captured (`gate-v-2026-10-27/`) |
| **T — Journeys** | Full product | **Per-domain verified**, not one full nav matrix | **Sampled** — employee unified create port-only pass documented |

---

## 1. Sidebar (admin primary nav)

**Source inventory:** 29 admin-visible roots in `source-nav-inventory.json` (English labels from `menu.blade.php`; plugins omitted).

| Dimension | A Source | B Flagship (:7002) | C erp-port-v201 (:7004) |
| --- | --- | --- | --- |
| **Visible slice roots** | **29** (inventory) | **114** (`count-visible-nav` on `app/Slices`) | **27** (`count-visible-nav.php`) |
| **Phase D policy** | — | Not applied — taxonomy + duplicates stay visible | Applied — `phase-d-*-nav.yaml` + `PHASE-D-ALL.md` |
| **Group shape vs inventory** | Dashboard, CRM, HR (8 incl. **Department**), Work (4), Finance (7), Orders (2), Tickets (2), Events, **Messages**, Settings | Extra roots: Demo Tasks, Kitchen Sink, Notice Board, 40× Operations, duplicate Finance/Invoices/Companies, CRM lead plumbing | Matches inventory groups except **2 gaps** (below) |
| **Messages** | Top-level `messages.index` | Visible (**Messages** + Sticky Notes) — not source inbox parity | **Hidden** — no owner slice (`deviations.yaml` `gate-d-messages-nav`) **M** |
| **Department** | HR → Department | Visible **Department** root | Embedded / taxonomy — not separate sidebar root (pending owner sign-off) **S/M** |
| **Knowledge Base** | Top-level KB | Under Tickets group | Visible **Knowledge Base** (Phase D) **—** |
| **Shift Roster** | HR label | **Shift Roster** | **Shift Roster** (renamed from shifts) **—** |
| **Studio / dev slices in nav** | No | **Examples/Kitchen Sink**, **Demo/Tasks**, many Operations dashboards **M** | Hidden aux slices (121 hidden) **—** |

**Gate notes:** B **D/M** (nav cardinality + noise vs source 29) · C **S/M** (Messages + Department deviations logged, 27 vs 29)

---

## 2. Dashboard counters (scoped widgets)

Persona reference for benchmark parity: **admin@example.com** as super-admin dashboard (Marjolaine-scoped counters on source — see port `REPLICA-STATUS` Gate F).

| Counter card | A Source (benchmark) | B Flagship `measure-dashboard` | C Port-v201 `measure-dashboard` |
| --- | --- | --- | --- |
| **Tasks** Pending · Overdue | **1 · 1** | **1 · 1** | **1 · 1** **—** |
| **Projects** In progress · Overdue | **4 · 4** | **10 · 10** | **4 · 4** **—** |
| **Deals** Total · Converted | **3 · 0** | **8 · 1** | **3 · 0** **—** |
| **employee@example.com** same cards | Scoped low counts | **5 · 5** tasks; **10 · 10** projects; **8 · 1** deals (same as admin) | **0 · 0** tasks/projects/deals (scoped) **—** vs source employee persona |

**Other dashboard composition (portal):**

| Widget strip | A | B | C |
| --- | --- | --- | --- |
| Welcome + clock + punch | Yes | Replica kit converged (QA9) | Portal composition; counters match on C |
| Week timelogs / shift schedule | Yes | **M** — counts not durations (QA9) | **M/S** (Gate V matrix) |
| My Calendar / WFH | Partial on source | **M** — widget/types/data era | **M** (not in benchmark 8 surfaces) |
| Profile card (admin) | Admin is employee in source | **M** — admin has no employee row | Same class of gap possible **M** |

**Gate notes:** B **D** on Projects/Deals (and employee persona scoping) · C **—** on Marjolaine counters · both ports **S/M** on long-tail widgets vs A

---

## 3. Benchmark surfaces — directory + hub

Row totals (Gate D/F): **Employees 12 · Projects 10 · Tasks 50 · Invoices 19** — aligned on B and C per REPLICA-STATUS; A is reference.

### Employees (`employees.index` → `EmployeeDetails`)

| Aspect | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **Directory** | Code, name/avatar, email, role select, status | Wide schema dump (address, hourly_rate, raw IDs) **M/S** | **5 columns** pipeline: code badge, composite user, email, inline **role**, status pill **—** |
| **Directory filters** | Employee, Designation (facets) | Sidebar filters on raw IDs **S** | Employee + Designation quick filters (Gate F pilot) **—** |
| **Hub tabs** | ~11 functional tabs (+ overflow) | **2** declared: Overview, Activity **M** | **14** declared (Profile → Immigration) **S/M** (3 tabs overflow at 1440px) |
| **Hub KPIs** | Open Tasks, Projects, Hours, Tickets | Not in YAML hub (minimal tabs) **M** | **4 KPI cards** + gauge + donut (Gate F / Gate V PNG) **—** |

**Gate notes:** C best **directory + hub** parity vs A · B **M** on hub depth · B **M/S** on directory density

### Projects (`projects.index` → `Projects`)

| Aspect | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **Directory** | 7 cols + status/progress facets (Gate F) | Pilot-style columns (varies); duplicate **Projects** nav entries **M** | **7 cols** + 2 filters (pipeline-derived Gate F) **—** |
| **Hub tabs** | Curated: Members, Files, Milestones, Tasks, Gantt, Timesheet… | **2** tabs; relation curation debt in REPLICA-STATUS **M** | **19** tabs in YAML (incl. Gantt, Discussion, Burndown) **S/M** (Gantt view type **M** on both ports) |
| **Hub widgets** | Budget/progress/hours | **bars** widget (Hours, Budget) — QA8 **—** | Gauge + donut + pipeline widgets **—** |

**Gate notes:** B **M** tab set · C **S/M** (many relation tabs vs source curation) · both **M** Gantt parity

### Tasks (`tasks.index` / board)

| Aspect | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **Directory views** | Table / board / calendar | Table + **board**; timer, code badge, assignee stack | Table + **board**; pipeline columns (priority-in-heading pattern partial) |
| **Board columns** | 9 / 11 / 14 / 16 | **—** (50 tasks) | **—** (`gate-f-boards.php`) |
| **Inline status in grid** | Yes | status_pill + board filters | status + inline patterns **S** |

**Gate notes:** B ahead on **task row chrome** (timer) **—** vs C · C **—** on board distribution vs A

### Invoices (`invoices.index` + document)

| Aspect | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **Nav** | Single Finance → Invoices | **Duplicate** visible Invoices (ERP + Accounting trees) **M** | Single Finance → Invoices **—** |
| **Directory** | Money breakdown, client/project facets | Transplanted FINANCE twin (QA8); client/project filters judged equivalent in deviations | Many columns incl. `money_breakdown`; project_id facet built **—** |
| **Document view** | Company blocks, PAID stamp, totals | **M** — standard template gaps (REPLICA-STATUS NOT covered) | Line items wired; **M/S** on stamp/blocks (Gate V) |

**Gate notes:** B **M** duplicate nav · both ports **M** on document furniture vs A

---

## 4. Studio availability (LaraSlice Dev Studio)

| Capability | A Source | B Flagship | C erp-port-v201 |
| --- | --- | --- | --- |
| **Native Worksuite studio** | Product settings only | — | — |
| **`/laraslice/studio` wizard** | No | **`wizard.enabled: true`** in `config/laraslice.php` | **`LARASLICE_WIZARD_ENABLED=true`** (.env); config default false |
| **MFA on login** | Source auth | **Off** in config (demo DX) | **On** by default (`LARASLICE_MFA_ENABLED`) **S/M** for QA |
| **MCP HTTP** | — | Disabled | Env-gated (default off) |
| **Replica ERP chrome** | N/A | **No Studio/Copilot/search** on replica pages (QA9 shell) | BlatUI + programme shell; Studio reachable when wizard enabled **S** |
| **Framework pin** | — | `hereafter/laraslice` **2.0** | `hereafter/laraslice` **2.0.1** (candidate **v2.0.2** on programme branch per REPLICA-STATUS) |

**Gate notes:** B **—** for studio access on demo · C **S/M** MFA friction · A **—** (no LaraSlice)

---

## 5. Gate-style summary matrix (benchmark scope)

| Area | B vs A | C vs A | B vs C |
| --- | --- | --- | --- |
| Sidebar | **D/M** (114 vs 29) | **S/M** (27, 2 logged gaps) | B much noisier nav |
| Dashboard counters | **D** (projects/deals scope) | **—** (Marjolaine match) | C wins persona scope |
| Employees dir/hub | **M** (hub shallow; dir raw) | **— / S** (pilot convergence) | C ahead on hub |
| Projects dir/hub | **M** (tabs); widgets OK | **S/M** (tab count vs curation) | C more tabs; B better bars proof |
| Tasks | **—** row UX | **—** board counts | B richer grid chrome |
| Invoices | **M** dup nav + doc | **S/M** doc | C cleaner nav |
| Studio | **—** (enabled) | **—** (enabled + MFA) | Same studio; different framework patch |
| Programme gates | Schema **partial** | Schema **closure local**; V **partial** | C is Phase D + Gate V programme |

---

## Key deltas (headline)

1. **Navigation:** Only **C** intentionally tracks **`source-nav-inventory.json`** (27 roots). **B** exposes **114** visible slices (duplicates, Operations taxonomies, demo slices) — largest UX drift vs A.
2. **Dashboard:** **C** matches A scoped counters for **admin@example.com** (1·1 / 4·4 / 3·0). **B** reports **global** project/deal totals (10·10, 8·1) — **D** for persona-faithful replica.
3. **Hubs:** **C** carries Universal Surface Derivation output (employee 14-tab hub, project 19-tab YAML). **B** replica kit converged **shell** and **project bars** widgets but **YAML hubs stay 2-tab** on employees/projects — **M** vs A and vs C.
4. **Tasks directory:** **B** leads on **timer / code / assignee stack**; **C** leads on **board parity** and Gate F column distribution vs A.
5. **Schema programme:** **C REPLICA-STATUS** claims near-complete Gate S via **port-local** migration; **B** documents honest **62-table** column gaps — relevant to **v2.0.2** “ship closure engine” vs manual port fix.
6. **Studio:** Both ports expose Dev Studio when enabled; **B** hides AI chrome on replica pages. **C** adds **MFA** by default — affects side-by-side QA vs A/B logins.

---

## Do not do yet

- **No zero-port** / no `slice:port` execution from this doc — comparison input only.
- Resolve **Messages** and **Department** nav deviations on C via owner sign-off before declaring 29/29 nav parity.
- Align **B** to Phase D nav policy (or separate “full schema lab” from “replica demo”) before treating :7002 as nav benchmark for v2.0.2.

---

**Mirror copy:** `E:\laragon\www\erp-port-v201\porting\THREE-WAY-COMPARE-2.0.2.md` (same content).
