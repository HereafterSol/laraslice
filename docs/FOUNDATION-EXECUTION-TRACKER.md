# Foundation execution tracker

**Vision (one line):** Mature LaraSlice so AI, developers, and super-admins adopt it — **enterprise-grade UX**, honest **~50%→100%** port path, skills/MCP discipline, multi-language vision without false shipping claims.

**Headline (5 Nov 2026):** ERP programme **~50%** replica on erp-port-v202 (lowest gate wins — **not 100%**). **Phase 0 foundation audit complete** — **net-new porting paused** until foundation blockers W1–W4 below are in progress or done.

**Audit:** [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md) · **Maturity stage:** **3 — Defined**

---

## Pillars

| Pillar | North star | Tracker hook |
| --- | --- | --- |
| **UX enterprise** | Product chrome, not Laravel demo | [ENTERPRISE-UX-STANDARD.md](ENTERPRISE-UX-STANDARD.md) · [STUDIO-UX-AUDIT-2026-10-28.md](STUDIO-UX-AUDIT-2026-10-28.md) |
| **Port 100% path** | Gates S/D/F/T/V on signed scope | [PROGRAMME-STATUS-2026-11-05.md](PROGRAMME-STATUS-2026-11-05.md) · [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) · [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md) |
| **AI / skills** | Token budget + family skills | [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md) · Skill §28 |
| **Multi-lang vision** | RUNTIMES roadmap, no hype | [VISION.md](VISION.md) · [MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md) |

**Evaluator friction register:** [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md)

---

## Porting pause (mandate)

Do **not** start net-new ERP slice scaffolding, Gate S column closure, or Gate V expansion on erp-port-v202 until **Now** row **F1–F3** show owner + target date. Host **repair** (`slice:qualify-routes`, `slice:repair`, `slice:cache`) on existing trees is allowed.

---

## Sprint board

### Now (foundation — post Phase 0 audit)

| ID | Work | Owner | Target |
| --- | --- | --- | --- |
| F1 | Routing truth: `slice:qualify-routes` on v202 + agent rules | Framework + port | **PARTIAL** — v202 qualify-routes PASS; `slice:links` FAIL (Settings name + permission_types table); see v202 `REPLICA-STATUS` routing row |
| F2 | `slice:route-truth` JSON index for MCP (or slice:links enhancement) | Framework | **Done** — `slice:route-truth` + MCP `get_route_truth` (5 Nov 2026) |
| F3 | Flagship zip install truth (`composer.lock`, no SSH VCS default, `demo.ps1`) | Framework + demo | **In progress** — Packagist stub + `demo.ps1` + lock script shipped; lock in zip next cut |
| F4 | Portable Heart lint (`slice:portability` or validate extension) | Framework | [MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md) |
| F5 | Studio honesty: composer version, discovery slice count, SQLite Copilot | Framework | STUDIO-UX-AUDIT + register |

### Next (after F1–F3 underway)

| ID | Work | Notes |
| --- | --- | --- |
| N3 | erp-port-v202 Gate S column closure | **Unblocked when F1 PASS** |
| N4 | (merged into F3) Flagship install | — |
| N5 | Gate V URL matrix + qualify-routes on v202 | Depends F1 |
| X1 | Skill family split (core/start/port/operate router) | Audit D10 |
| X2 | Studio owner persona — glossary tooltips | STUDIO-UX-AUDIT U2 |
| X3 | Consolidate port modal → single Port panel | STUDIO-UX-AUDIT U3 |
| X4 | Windows `demo.ps1` parity | Part of F3 |
| X5 | Gate V workbook + pixel diff tooling | erp-port-v202 |

### Blocked

| ID | Blocker | Unblock |
| --- | --- | --- |
| B0 | **Foundation P0 incomplete** | Close F1–F3 in **Now** |
| B1 | Client portal smoke on :7002 | Dedicated session / checklist |
| B2 | 100% marketing language | Owner sign-off on NOT COVERED list |

### Done (recent)

| Date | Item | Evidence |
| --- | --- | --- |
| 2026-11-05 | **F1 routing truth on erp-port-v202** | qualify-routes PASS; `slice:links` FAIL (2 collisions) — `porting/REPLICA-STATUS.md` routing row |
| 2026-11-05 | **F2 `slice:route-truth` + MCP `get_route_truth`** | `SliceRouteTruthCommand`, `RouteTruthIndex` |
| 2026-11-05 | **Phase 0 architecture foundation audit** | [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md) |
| 2026-11-05 | Multi-runtime portability contract | [MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md) |
| 2026-11-05 | `FOUNDATION-EXECUTION-TRACKER.md` | This file |
| 2026-11-05 | `ENTERPRISE-UX-STANDARD.md` + shell implementation | `EnterpriseShell`, head partial, filterbar, Studio persona stub |
| 2026-11-05 | `ROUTING-TRUTH-ALIGNMENT.md` | P0 investigation doc |
| 2026-11-05 | `FrameworkPackage` version / slice count / schema label | Studio + Copilot |
| 2026-10-28 | Studio UX audit quick wins | STUDIO-UX-AUDIT shipped table |
| 2026-11-05 | Programme status + improvement register | PROGRAMME-STATUS, IMPROVEMENT-REGISTER |
| 2026-11-05 | **F3 evaluator install (partial)** | `resources/stubs/flagship/composer.json` (Packagist-only), `demo.ps1`, `docs/demo-windows.ps1`, `porting/generate-flagship-composer-lock.ps1` |

---

## Key doc links

| Doc | Purpose |
| --- | --- |
| [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md) | Maturity stage, decision register, workstreams |
| [PROGRAMME-STATUS-2026-11-05.md](PROGRAMME-STATUS-2026-11-05.md) | Honest ~50% headline, environments |
| [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) | Clone vs Kit architect mode |
| [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md) | Skill/MCP token budget |
| [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md) | Evaluator + zero port findings |
| [STUDIO-UX-AUDIT-2026-10-28.md](STUDIO-UX-AUDIT-2026-10-28.md) | Studio persona + terminology |
| [ENTERPRISE-UX-STANDARD.md](ENTERPRISE-UX-STANDARD.md) | Shell typography, titles, personas |
| [ROUTING-TRUTH-ALIGNMENT.md](ROUTING-TRUTH-ALIGNMENT.md) | `/erp/erp` vs route:list P0 |

---

## Git handoff (Muse)

| Field | Value |
| --- | --- |
| **Branch** | `v2.0.2-candidate` |
| **Remote** | `origin` → `https://github.com/HereafterSol/laraslice.git` |
| **Last commit** | `10da68f` (F3 demo stubs; F2 body `c3abc2d`) |
| **In flight** | F1, F2, F4, F5 **Now**; F3 lock-in-zip on next flagship cut; port N3/N5 paused until F1 |

**Pull before work:**

```powershell
cd E:\Apps\message\LaraSlice
git pull origin v2.0.2-candidate
```

**Host apps:** Path repo or `composer update hereafter/laraslice` + `php artisan slice:cache` to pick up package UX.

---

## Muse — suggested next 3 tasks

1. **Close F1 on v202:** resolve Settings slice twin + `permission_types` collision → **`slice:links` PASS**; run **`slice:route-truth`** after path-repo `composer update` to `c3abc2d`.
2. Ship flagship **`composer.lock`** + run **`demo.ps1`** / `demo-windows.ps1` (F3).
3. Studio owner glossary (F5); align **`slice:portability`** with shipped lint (F4).

---

*Maintainers: update **Done** + **Git handoff** on every foundation sprint; never bump replica % without `REPLICA-STATUS.md`.*
