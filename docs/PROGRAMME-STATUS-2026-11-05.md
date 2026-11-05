# LaraSlice v2.0.2 ERP programme — status (5 Nov 2026)

**Honest headline:** **~50%** whole-ERP replica (lowest gate wins). **Not 100%.** **Framework maturity:** **Stage 3 — Defined** — [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md) ([Full framework architecture audit](f13b1c79-24c9-4ac4-8965-e7f665a4be90)); **porting paused** until tracker **F1–F3**.

## Environments

| Role | Path / URL | DB | Notes |
| --- | --- | --- | --- |
| **Source Worksuite** | `E:\laragon\www\erp` · http://127.0.0.1:**7001** | MySQL `erp` | Pairing baseline |
| **Zero port v2.0.2** | `E:\laragon\www\erp-port-v202` · http://127.0.0.1:**7005** | `erp_port_v202` | Programme benchmark |
| **Programme reference v2.0.1** | `E:\laragon\www\erp-port-v201` · http://127.0.0.1:**7004** | `erp_port_v201` | Hub YAML / patterns |
| **Flagship demo zip** | `E:\laragon\www\flagship-erp-v202-demo` · http://127.0.0.1:**7002** | SQLite | Real-user register; **client portal not re-smoked this close** |

## Gate snapshot (erp-port-v202, same-day run)

| Gate | Status | Evidence |
| --- | --- | --- |
| **S** | **PARTIAL (improved)** | Closure migration applied; `GATE-S-DIFF-2026-11-05.txt` (**16** missing-col tables, **240/358** tables) |
| **D** | **PASS (benchmark)** | `verify-programme.php` / clean import |
| **F** | **PASS (benchmark)** | Directory counts + task board 9/11/14/16 |
| **T** | **PASS (benchmark)** | `GateTEmployeeCreateTest` |
| **V** | **PARTIAL (classified)** | HTTP **9/9**; 16 PNGs; `gate-v-2026-11-05/SIDE-BY-SIDE-MATRIX.md` |

**Drift:** 0 errors, 44 warnings (`slice:drift` after closure).

## Lowest gates & why not 100%

1. **Schema:** Discussions slice skipped; **118** source-only tables; **16** matched tables still missing columns.
2. **Visual:** No pixel-diff PASS; dashboard/document chrome still **M** class on several surfaces.

## Next owner actions

### Port (erp-port-v202)

1. Scaffold or exclude **Discussions**; close **16** residual columns or document class C/D deviations.
2. Re-capture Gate V after hub YAML lift from v201; optional pixel diff tooling.
3. Expand Gate T beyond single PHPUnit employee create.

### Framework P0 (unchanged — ship in LaraSlice, not port trees)

See [FOUNDATION-MATURITY-ROADMAP.md](FOUNDATION-MATURITY-ROADMAP.md) · [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md).

1. **Routing truth** — **`slice:route-truth`** shipped (`c3abc2d`); close **F1** on v202 (`slice:links` collisions). See [F1 F2 routing truth](d42146fe-049c-45c5-a106-5b6c580e8a0e).
2. **Flagship zip** — Packagist stub + **`porting/generate-flagship-composer-lock.ps1`** shipped ([F3 evaluator install](e952550a-3cc9-4e65-a66d-39320cf4b4e4)); **`composer.lock` inside zip** still open.
3. **Windows demo** — **`resources/stubs/flagship/demo.ps1`** + **`docs/demo-windows.ps1`** shipped; validate on fresh extract (`tar -xf`).

### Demo (:7002)

- Try **`docs/demo-windows.ps1`** or stub **`demo.ps1`** after `git pull` `v2.0.2-candidate` (F3).
- **Studio F5 / X6:** demo host views republished from package stubs (`ai-copilot-bubble`, `app` layout version via `FrameworkPackage::version()`); CLI copilot status = **SQLite schemas** + **`dev-v2.0.2-candidate`**. **:7002 browser:** stop duplicate `php -S` processes; run one `artisan serve` from `flagship-erp-v202-demo` before smoke.
- **Client portal** deferred — `IMPROVEMENT-REGISTER-2.0.2.md`.

## Doc index

- **`LaraSlice/docs/FOUNDATION-EXECUTION-TRACKER.md`** — **primary sprint board + Muse git handoff** (`v2.0.2-candidate`)
- `erp-port-v202/porting/REPLICA-STATUS.md` — authoritative port headline
- `erp-port-v202/porting/ZERO-PORT-2.0.2-LOG.md` §9 — programme close run
- `erp-port-v202/porting/GATE-S-CLOSURE-2026-11-05.md`
- `erp-port-v201/porting/PROGRAMME-NEXT.md` — item **#9** partial
- `LaraSlice/docs/IMPROVEMENT-REGISTER-2.0.2.md` — evaluator + zero port findings
- `LaraSlice/docs/PORT-100-PLAYBOOK.md` — ReferenceData pre-import step
- `LaraSlice/docs/PORTING-DECISION-100-REPLICA.md` — page clone vs Kit architect note
- `LaraSlice/docs/AI-FOUNDATION-OPTIMIZATION.md` — skill/MCP token budget
- `LaraSlice/docs/FOUNDATION-MATURITY-ROADMAP.md` — pillars + P0 alignment
- `LaraSlice/docs/SHARED-REFERENCES.md` — owner reference index
- `LaraSlice/docs/STUDIO-UX-AUDIT-2026-10-28.md` — Studio quick wins (parallel track)
