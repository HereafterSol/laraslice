# Enterprise UX standard (LaraSlice shell)

**Status:** Normative for host `layouts.app` + Dev Studio ONE shell (Nov 2026).  
**Companion:** [UI_UX_STANDARD.md](UI_UX_STANDARD.md) (slice page patterns P1–P8) · [STUDIO-UX-AUDIT-2026-10-28.md](STUDIO-UX-AUDIT-2026-10-28.md) · [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md)

---

## Vision (one line)

Operators and owners should feel they are in a **product**, not a Laravel demo — titles, density, and Studio copy must match ERP-grade chrome.

---

## Typography & density

| Token | Value | Where |
| --- | --- | --- |
| App base | **14px** (`text-sm` on body) | `layouts/app.blade.php` body |
| Page / shell title | **16px / semibold** | Topbar `@section('page-title')`, Studio `h1` |
| Meta / helper | **11px** muted | Studio subheads, filter hints |
| Sidebar group | **11px uppercase tracking** | Nav groups (existing BlatUI sidebar) |
| Content max width | **1600px** | Shell main + Studio header |
| Page horizontal pad | **16px mobile / 24px lg** | `px-4 lg:px-6` |
| Directory filter band | **compact** — `py-1.5`, `gap-2`, `text-xs` controls | `data-table-filterbar` |
| Table row target | **42–61px** | See UI_UX_STANDARD |

CSS variables (starter `head` partial): `--ls-shell-title`, `--ls-content-max`, `--ls-filterbar-py`.

---

## Document titles (browser tab)

**Never** default to `{App} - Vertical Slice Architecture`.

1. `@section('title', '…')` when a view sets an explicit title (Studio, settings).  
2. Else **active slice nav label** (parent — child when nested) via `EnterpriseShell::documentTitle()`.  
3. Else route fallbacks (`Dashboard`, `Dev Studio`).  
4. Else **app name only**.

Implementation: `LaraSlice\Support\EnterpriseShell`, `resources/stubs/starter/views/layouts/partials/head.blade.php`.

---

## Shell titles (in-app)

- ERP pages: breadcrumb + optional `@section('page-title')` from generated directory views — label comes from **slice YAML nav**, not framework marketing strings.
- Dev Studio: fixed product name **Dev Studio**; mode switcher shows **Port / Design / Create / Manage / Plan / Operate** (mode IDs = public strings).

---

## Empty states

- **Dashboard widgets:** sentence case, actionable hint (`DashboardUi` / `dashboard.blade.php` defaults).
- **Port execution log:** nudge to Analyze first, then Execute (port panel).
- **Studio Home:** three cards + BRD door — no blank landing.

---

## Studio personas (stub rules)

Query param: `?persona=owner|developer` (default **developer**).

| Persona | Audience | Port tabs visible | Copy tone |
| --- | --- | --- | --- |
| **developer** | Port engineer | All tabs (YAML, JSON, ETL, VSA, …) | Technical OK |
| **owner** | Super-admin / product sign-off | Guide, Journeys, Sidebar, Execution log only | Plain language; no YAML/JSON/ETL |

Persona switcher lives in Studio header (stub). Rules will expand in STUDIO-UX-AUDIT recommendations #3.

---

## Anti-patterns (from port + demo validation)

1. Generic Laravel marketing in `<title>`.
2. Hard-coded framework version in Blade (use `FrameworkPackage::version()`).
3. Copilot schema label claiming MySQL on SQLite (use `FrameworkPackage::schemaTelemetryLabel()`).
4. Dense filter strip without compact tokens — reads as “internal tool”, not ERP.
5. Quoting replica % in UI — point to `porting/REPLICA-STATUS.md` instead.

---

## Verification checklist (evaluators)

- [ ] ERP module page tab shows **slice label · app name**, not “Vertical Slice Architecture”.
- [ ] `/laraslice/studio` badge matches `composer show hereafter/laraslice`.
- [ ] `?persona=owner&mode=port` hides YAML/JSON/ETL/VSA/Page tabs.
- [ ] Projects directory filter band height ≤ ~48px with default filters collapsed.

---

## References

- [FOUNDATION-EXECUTION-TRACKER.md](FOUNDATION-EXECUTION-TRACKER.md) — sprint board  
- [ROUTING-TRUTH-ALIGNMENT.md](ROUTING-TRUTH-ALIGNMENT.md) — URL vs `route:list` P0
