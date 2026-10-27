# LaraSlice Foundation Roadmap (YAML-centric porting + DX)

Last updated: 2026-10-20 (v1.7.0 release — `feat/workflow-bulk-actions` merged to master)  
Branch: `master` (feature branch merged)

Goal: ERP-class porting that is **usable for admin / employee / client**, with **demo-aligned IA**, **Slice Studio as daily driver**, and **JSON + YAML + DB** sync.

**v1.7.0 shipped (2026-10-20):** Slice YAML v0.1 spec + validator, formatter pipeline (YAML → pixels), Phase 2 DataTable parser, non-Laravel porting (SQL-dump legacy apps), Dev Studio Standard. Verified by porting a **production Laravel ERP** (85 slices) and a **non-Laravel legacy suite** via SQL dumps (407 tables). Next: Slice Studio progressive disclosure (first post-merge PR), YAML elimination (JSON → YAML-only), Phase B spec publishing, FlutterSlice proof-of-concept.

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

---

## Future — Distribution & try-it experiences (R&D noted 21 Oct 2026; not started)

### D1 — `laraslice new <app>` installer

A Laravel-installer-style scaffolder so a fresh project is one command
instead of create-project + require + install.

Scope (v1): a small `hereafter/laraslice-installer` package shipping a
`laraslice` bin (Symfony Console): runs `composer create-project
laravel/laravel`, requires `hereafter/laraslice` (`--dev` selects
dev-master), defaults `.env` to SQLite, runs `php artisan
slice:install`, and prints the seeded admin + next steps.

Estimate: core command 2–3 focused days (composer discovery and
Windows path handling are the usual time sinks, not the command
itself); release-ready with flags (--db, --force, git init), tests,
Packagist publishing and docs: ~1 week. Status: NOT implemented —
current documented path is create-project, then require + install.

### D2 — Browser playground (PHP in WebAssembly)

A zero-install, in-browser LaraSlice — the WordPress Playground model:
PHP compiled to WebAssembly, a Service Worker as the web server,
SQLite instead of a database server, state persisted in browser
storage (IndexedDB/OPFS), optionally synced to a local folder via the
File System Access API.

Feasibility (researched 21 Oct 2026): proven possible today on the
WordPress project's own `@php-wasm/web` builds (PHP up to 8.4/8.5).
Full Laravel applications already run this way in third-party
proofs of concept (browser IDEs bundling a frozen `vendor/`, SQLite,
folder sync, shareable URLs). The known hard limits apply to us
unchanged: vendor is frozen (no Composer at runtime), SQLite only,
no queues/workers, no outbound network from PHP without a JS bridge,
per-request interpreter boot cost, and a bundle measured in tens of
MB (a fresh Laravel app is ~6,200 files; ~7 MB gzipped in one field
report).

Staged plan:
- S1 spike (~1 week): boot the stock LaraSlice demo (frozen vendor,
  seeded SQLite) on `@php-wasm/web-8.4`; prove login, Studio, and one
  ported module end-to-end in a tab.
- S2 playground (~3–5 weeks): packaging pipeline from a release,
  persistence + reseed/reset controls, a hosted try-it page with the
  sample application.
- S3 YAML-in-browser (+2–4 weeks, the differentiator): paste or load
  a slice YAML in the playground and generate + run it in-tab via
  Artisan-in-WASM — the universal-YAML vision, demonstrable with zero
  install.

Positioning: a try-it and demo surface, not a development
environment. Status: R&D only, not started.
