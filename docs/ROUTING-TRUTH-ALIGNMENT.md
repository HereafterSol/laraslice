# Routing truth alignment (framework P0)

**Problem (IMPROVEMENT-REGISTER § UI/UX #1):** Live ERP navigation uses **`/erp/erp/{area}/{slice}`** (URI from nested slice `Route::prefix`). `php artisan route:list` and older skills still show **`erp/adjudication/...`** or flat `tasks.*` names — agents bookmark 404s.

**Root cause (two layers):**

1. **URI prefix** — Nested ERP slices declare `Route::prefix('erp/erp/work/projects')` (example). That is **correct lived truth** for the flagship port.
2. **Route name prefix** — Legacy slices used flat names (`tasks.index`). Same-named slices collide; `route()` resolves to one winner. Fix: **`php artisan slice:qualify-routes`** (see `SliceQualifyRoutesCommand`) rewrites to path-qualified names (`erp_erp_work_tasks.index`).

**Adjudication vs ERP:** Preset/domain label “adjudication” in Studio Port is a **blueprint preset**, not the hosted ERP URL scheme. Do not generate docs links with `erp/adjudication/...` unless the host slice manifest actually registers that prefix.

---

## Agent / skill rules

| Need | Use |
| --- | --- |
| Browser URL in ported flagship | DOM sidebar `href` or **`/erp/erp/...`** pattern from IMPROVEMENT-REGISTER |
| Named route in PHP/Blade | Qualified name from slice `Routes/web.php` `->name('…')` group |
| MCP / route index | `php artisan slice:route-truth` (JSON `{uri, name, slice_id}`) or MCP tool **`get_route_truth`**; run after `slice:qualify-routes` + `slice:cache` |
| Deep link from `route:list` alone | **Unsafe** until name/URI audit PASS on host |

---

## Recommended host repair (port trees)

```bash
php artisan slice:qualify-routes --dry-run
php artisan slice:qualify-routes
php artisan slice:cache
php artisan route:list --path=erp
```

Track completion in host `porting/REPLICA-STATUS.md` (routing row). Framework: **`slice:route-truth`** + MCP **`get_route_truth`** emit JSON `{uri, name, slice_id}`.

---

## Framework status (5 Nov 2026)

| Item | Status |
| --- | --- |
| `slice:qualify-routes` | **Shipped** |
| `slice:route-truth` / MCP `get_route_truth` | **Shipped** (5 Nov 2026) |
| Skill §11 / §28 read order | Points to this doc via tracker |
| Auto-fix adjudication prefix in generator | **Not shipped** — documentation-only this sprint |
| Host routing row PASS on v202 | **`slice:links` exit 0** + **`slice:route-truth` exit 0** (5 Nov 2026 — `erp-port-v202/porting/F1-SLICE-LINKS-LOG.md`, `F1-ROUTE-TRUTH-LOG.md`; post-merge **1203** routes in `POST-MERGE-HOST-SYNC-2026-11-05.md`) |
