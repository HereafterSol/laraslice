# LaraSlice v2.0.2 ERP programme — status (5 Nov 2026)

**Honest headline:** **~50%** whole-ERP replica (lowest gate wins). **Not 100%.** **Framework maturity:** **Stage 3 — Defined** — [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md) ([Full framework architecture audit](f13b1c79-24c9-4ac4-8965-e7f665a4be90)); **foundation F1–F4 PASS** — **N3/N5** port gates unblocked on v202.

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
| **S** | **PARTIAL (N3 cols closed)** | Matched-table missing columns **16→0** (5 Nov N3); **118** source-only tables; `discussions` **excluded** in `deviations.yaml` — `porting/N3-GATE-S-2026-11-05.md`, `GATE-S-CLOSURE-2026-11-05.md` |
| **D** | **PASS (benchmark)** | `verify-programme.php` / clean import |
| **F** | **PASS (benchmark)** | Directory counts + task board 9/11/14/16 |
| **T** | **PASS (benchmark)** | `GateTEmployeeCreateTest` |
| **V** | **PARTIAL (N5 matrix)** | HTTP **14/16** (**9/9** dirs); 16 PNGs; `gate-v-2026-11-05/SIDE-BY-SIDE-MATRIX.md` · `N5-GATE-V-2026-11-05.md` |

**Drift:** 0 errors, 44 warnings (`slice:drift` after closure).

## Lowest gates & why not 100%

1. **Schema:** `discussions` **documented exclude** (N3); **118** source-only tables; **176** extra-column tables — matched name gaps **closed** (0 missing cols on 215 tables).
2. **Visual:** No pixel-diff PASS; dashboard/document chrome still **M** class on several surfaces.

## Next owner actions

### Port (erp-port-v202)

1. Scaffold or exclude **Discussions** (exclude documented — optional parent table later); porter closure for **118** source-only tables.
2. Re-capture Gate V PNGs after hub YAML lift from v201; optional pixel diff tooling (N5 HTTP matrix **done**).
3. Expand Gate T beyond single PHPUnit employee create.

### Framework P0 (unchanged — ship in LaraSlice, not port trees)

See [FOUNDATION-MATURITY-ROADMAP.md](FOUNDATION-MATURITY-ROADMAP.md) · [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md).

1. **Routing truth** — **`slice:route-truth`** shipped (`c3abc2d`); **F1 port PASS** on v202 — `slice:links` + **`slice:route-truth`** exit 0 (5 Nov — `F1-SLICE-LINKS-LOG.md`, `F1-ROUTE-TRUTH-LOG.md`). See [F1 F2 routing truth](d42146fe-049c-45c5-a106-5b6c580e8a0e).
2. **Flagship zip** — **`resources/stubs/flagship/composer.lock`** tracked (**`23c18d6`**, Packagist `hereafter/laraslice` **v2.0.2**); generator uses **`--no-install`**. **Remaining:** ship lock beside `composer.json` in the **next flagship zip** artifact cut.
3. **Windows demo** — **`demo.ps1`** / **`docs/demo-windows.ps1`** + **`demo.ps1 -NoServe`** smoke **PASS** on flagship tree; re-validate on **fresh** `tar -xf` extract when cutting zip.
4. **Portable Heart** — **`slice:portability`** + trilogy lint **PASS** (`f467877`); run on host trees before demo cuts — ported YAML may still report findings until porter cleanup ([MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md)).

### Demo (:7002)

- Try **`docs/demo-windows.ps1`** or stub **`demo.ps1`** after `git pull` `v2.0.2-candidate` (F3).
- **Studio F5 / X6:** demo host views republished from package stubs (`ai-copilot-bubble`, `app` layout version via `FrameworkPackage::version()`); CLI copilot status = **SQLite schemas** + **`dev-v2.0.2-candidate`**. **:7002 browser:** stop duplicate `php -S` processes; run one `artisan serve` from `flagship-erp-v202-demo` before smoke.
- **Client portal** deferred — `IMPROVEMENT-REGISTER-2.0.2.md`.

## Doc index

- **`LaraSlice/docs/FOUNDATION-EXECUTION-TRACKER.md`** — **primary sprint board + Muse git handoff** (`v2.0.2-candidate`)
- `erp-port-v202/porting/F1-SLICE-LINKS-LOG.md` — F1 **`slice:links`** closure (ErpSettings + permission_types)
- `erp-port-v202/porting/F1-ROUTE-TRUTH-LOG.md` — F1 **`slice:route-truth`** index (1056 routes)
- `erp-port-v202/porting/N3-GATE-S-2026-11-05.md` — N3 Gate S **16→0** matched missing columns
- `erp-port-v202/porting/N5-GATE-V-2026-11-05.md` — N5 Gate V HTTP **14/16** + 16-row D/M/S matrix
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
