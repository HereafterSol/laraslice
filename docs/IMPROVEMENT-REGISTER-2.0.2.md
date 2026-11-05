# LaraSlice v2.0.2 — Real-user validation register

**Date:** 2026-11-05  
**Environment:** `E:\laragon\www\flagship-erp-v202-demo` (full zip re-extracted via `tar -xf`; **not** `Expand-Archive`, which stopped at `app/` only)  
**Demo URL:** http://127.0.0.1:7002 (manual setup; `demo.sh` not run on Windows)  
**Framework lock:** `hereafter/laraslice` **v2.0.2** → `dd1e0badcf0d601da714b388d2dbf80bc582d420`  
**Release notes reference:** [v2.0.2 on GitHub](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.2), `CHANGES-v2.0.2.md` in vendor

---

## Demo

| Item | Result | Notes |
|------|--------|-------|
| Asset `flagship-erp-v2.0.2.zip` (~6.35 MB) | **OK** | Complete tree includes `artisan`, `composer.json`, `demo.sh`, SQLite snapshot |
| Extract on Windows | **Friction** | `Expand-Archive` left partial tree (`app/` only). **`tar -xf`** extracted full app |
| `composer install` from zip | **Blocked → workaround** | Zip ships **no** `composer.lock` / `vendor`. Default `repositories` VCS entry forces GitHub SSH → auth failure. Removed VCS repo; `composer update` resolved **v2.0.2** from Packagist |
| `demo.sh` | **Not run (Windows)** | Requires bash + `sed -i`; manual `.env` + absolute `DB_DATABASE` + `key:generate` + `slice:cache` used instead |
| SQLite snapshot | **OK** | `database/erp-clone.sqlite`; demo accounts present |
| `php artisan serve :7002` | **OK** | Server running after composer install |
| Password `password` for seeded accounts | **OK** | Verified via tinker `Hash::check` for `admin@laraslice.com`, `admin@example.com`, `employee@example.com`, `client@example.com` |
| Login Auto Fill | **OK** | Fills employee session (maps to `employee@example.com` user **Marjolaine Altenwerth**) |
| `APP_URL` | **Stale** | `.env` still `http://localhost:8000` while serving **:7002** |

---

## Role matrix (browser + CLI)

| Role | Account | Login | Dashboard | Sidebar | Module CRUD (sample) |
|------|---------|-------|-----------|---------|----------------------|
| Super admin | `admin@laraslice.com` | **PASS** | **PASS** | **PASS** (full ERP nav) | **PASS** Studio + admin dashboard widgets |
| Admin | `admin@example.com` | **PASS** (credentials) | Not re-walked | — | — |
| Employee | `employee@example.com` | **PASS** (Auto Fill) | **PASS** | **PASS** | **PASS** Work → Projects directory (filters, inline status, export) |
| Client | `client@example.com` | **PASS** (credentials) | Not re-walked | — | — |

**CRUD modules exercised**

- **Work / Projects:** list, filters, status ribbons, export, row actions — **PASS** at `/erp/erp/work/projects`
- **CRM / Leads:** sidebar targets `/erp/erp/crm/leads` — not fully exercised; same URL pattern as Projects

**Routing mismatch (high severity)**

- `php artisan route:list` registers names like `erp/adjudication/work/projects`
- Live navigation and working URLs use **`/erp/erp/{area}/{slice}`** (double `erp`, no `adjudication`)
- Direct hits to `erp/adjudication/...` return **404** even when authenticated — breaks bookmarking, docs, and agents that read `route:list`

---

## Release claims (v2.0.2 / CHANGES vs observed)

| Claim | Verdict | Evidence |
|-------|---------|----------|
| Tag **v2.0.2** / commit **dd1e0ba** | **Verified** | `composer.lock` dist reference `dd1e0bad…` after Packagist install |
| Flagship demo zip + SQLite one-command story | **Partial** | Zip OK; **no lock/vendor**; Windows needs non-`demo.sh` path; composer VCS default breaks fresh install |
| `demo.sh` pulls v2.0.2 / fixes v2.0.1 demo block | **Unverified here** | Script present; not executed on Windows; dependency resolution is **`composer update`**, not script |
| **700 tests, 2557 assertions** | **Unverified** | Demo app has no test run in this session |
| Studio six modes (Port, Design, Create, Manage, Plan, Operate) | **Verified (smoke)** | `/laraslice/studio` loads; mode tabs switch (`?mode=design`, `manage`, `port`) |
| `slice:operate health` | **Verified** | DB OK (sqlite), 164 slices discovered, Mind present |
| `slice:portability` + CI non-zero on findings | **Verified** | Command exists; **23 findings** in ported slice YAML (`.php` template leakage) |
| Declaration history on `slice:cache` | **Verified** | “recorded … 312 declaration change(s)” on cache |
| Studio UX polish (version label, honest AI, SQLite-aware copy) | **Partial fail** | UI badge **“LaraSlice v2.0.0”**; Copilot footer cites **“live MySQL schemas”** on SQLite demo; Home kickoff still says brief drafting is “the next step — for now” despite v2.0.2 BRD drafting claims |
| Slice count consistency | **Partial fail** | Studio header **116 slices** vs health check **164 discovered** |
| Replica / 24-of-24 journey gates on flagship | **Unverified** | Not re-run in this session |
| MCP / JSON gates / skill family split | **Unverified (UI session)** | CLI commands listed; not exhaustively tested |

---

## Studio (Dev / Blueprint / Porting)

| Area | Result | Notes |
|------|--------|-------|
| Studio shell | **PASS** | `/laraslice/studio` authenticated |
| Port tab | **PASS (load)** | `?mode=port` — panel swap OK |
| Design tab | **PASS (load)** | Blueprint / Schema sub-tabs appear |
| Manage tab | **PASS (load)** | Large slice list UI loads (`?mode=manage`) |
| Plan / Operate | **Not deep-tested** | Tabs present; Operate quality card not audited line-by-line |
| Copilot | **PASS (built-in)** | “Built-in answers”; BUILT-IN mode visible |
| Team Library / blueprint drafts / Port analysis retention | **Unverified** | Requires interactive flows + time |
| Post-demo customization | **Blocked for agents using `route:list`** | Use **`/erp/erp/...`** URLs from DOM, not `erp/adjudication/...` |

---

## UI/UX friction

1. **Double-`erp` routes vs `route:list` / adjudication prefix** — 404 confusion, invalid deep links.
2. **Version badge stuck at v2.0.0** while composer is v2.0.2.
3. **Studio slice count (116) ≠ discovery count (164)** — trust issue for Operate/Manage.
4. **Copilot context string wrong DB dialect** (MySQL on SQLite demo).
5. **Studio Home copy stale** relative to release notes (brief → blueprint promised in v2.0.2).
6. **Page title** generic “Laravel - Vertical Slice Architecture” on ERP pages.
7. **Sidebar click on Leads from dashboard** did not change URL in one attempt (possible Turbo/JS nav); direct `/erp/erp/...` works.
8. **Setup Progress 4/6** persistent — unclear completion criteria for evaluators.
9. **Projects directory** dense filter panel — usable but heavy for first-time demo evaluators.
10. **Logout** is POST-only — GET `/logout` throws MethodNotAllowed (expected Laravel, but surprises manual testers).

---

## Skills / commands gaps

1. **Windows demo path undocumented** — document `tar -xf`, PowerShell `.env` DB path, and Packagist-only composer when VCS SSH unavailable.
2. **Ship `composer.lock` (or vendor) in flagship zip** — reproducible v2.0.2 without `composer update` drift.
3. **Remove or fix VCS `repositories` in demo `composer.json`** — prefer Packagist dist for public evaluators.
4. **Align route registration with navigation** (`adjudication` vs `erp/erp`) — single source of truth for skills, MCP route index, and `slice:links`.
5. **`demo.sh --port` / Windows `.ps1` parity** — sed and bash block Laragon users.
6. **Expose framework version from composer** in Studio shell (not hard-coded 2.0.0).
7. **Evaluator checklist** in skill: verify client portal + admin RBAC diff after port (only super-admin + employee walked here).
8. **Portability findings** in demo YAML — runbook for flagship snapshot (`slice:portability` before demo cut).

---

## Zero port erp-port-v202 (5 Nov 2026)

Evidence from [Zero ERP re-port v202](132d0df3-8ed1-4ea1-9630-c82d6a6acdfe) on **`hereafter/laraslice` 2.0.2** (`:7005`, DB `erp_port_v202`).

| Finding | Severity | Action |
|---------|----------|--------|
| Phase D script assumed `ERP/ERP` only | **High** (silent no-op) | Auto-detect slice root — backported to **v201** + documented in `PORT-100-PLAYBOOK` |
| ReferenceData merged migration | **High** (import blocked) | Framework porter split; playbook pre-import step |
| Gate T on fresh scaffold | **High** → **mitigated on v202** | Copy v201 account-backed employee create; not automatic from `slice:port` — **done 5 Nov 2026**: `GateTEmployeeCreateTest` PASS on `erp-port-v202`; lowest gate remains **S (PARTIAL)** until closure migration + broader journeys |
| Studio YAML + `slice:repair` | **Low (PASS)** | Tasks nav label customize OK on v202 |
| Gate S closure | **Medium** → **partial fix 5 Nov** | Applied `porting/migrations-deferred/` on v202 (**64→16** missing-col tables); **Discussions** table still absent |
| Side-by-side HTTP | **Done** ([Side-by-side + Gate V v202](8884ea94-bc5a-43ed-ab90-cc0b6649013d)) | **9/9** pairs `:7001`↔`:7005` — `side-by-side-http-results.json` |
| Gate V visual | **PARTIAL** | **16 PNGs** + **D/M/S** matrix `erp-port-v202/porting/gate-v-2026-11-05/SIDE-BY-SIDE-MATRIX.md` — pixel diff not closed |
| MFA blocks headless QA | **High** → **mitigated on v202** | `config/laraslice.php` had hard-coded MFA; side-by-side/Playwright needed `LARASLICE_MFA_ENABLED=false` + env binding — **ship in framework** for port trees |

**Docs:** `laragon/www/erp-port-v202/porting/REPLICA-STATUS.md` · `ZERO-PORT-2.0.2-LOG.md` · `GATE-V-v202.md`

**Programme status (5 Nov 2026, programme close):** v202 benchmark **D/F/T PASS**, HTTP **9/9**, Gate S **PARTIAL improved**, Gate V **PARTIAL classified**. **Headline ~50%** (lowest gate S/V). **Not 100%** replica. One-pager: `LaraSlice/docs/PROGRAMME-STATUS-2026-11-05.md`.

**Foundation maturity (5 Nov 2026):** [Architect foundation + Studio sprint](90c91c6a-cb46-4b8d-b4f6-eac9817984ed) — `PORTING-DECISION-100-REPLICA.md`, `AI-FOUNDATION-OPTIMIZATION.md`, `FOUNDATION-MATURITY-ROADMAP.md`, `SHARED-REFERENCES.md`; Studio honesty fixes in framework source (see `STUDIO-UX-AUDIT-2026-10-28.md` § Shipped).

---

## Session artifacts

- **Improvement register:** `E:\Apps\message\LaraSlice\docs\IMPROVEMENT-REGISTER-2.0.2.md`
- **Local change (demo only):** `composer.json` — removed VCS repository; pinned `hereafter/laraslice` **2.0.2** for install
