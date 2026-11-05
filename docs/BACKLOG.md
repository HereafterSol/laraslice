# LaraSlice product backlog

Living backlog for the **framework** (not per-port gate status). **Live sprint board:** [FOUNDATION-EXECUTION-TRACKER.md](FOUNDATION-EXECUTION-TRACKER.md) · **F2 shipped:** `slice:route-truth` (`c3abc2d`) · **F1 partial:** v202 `slice:links` collisions. Port truth: [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) and each app’s `porting/REPLICA-STATUS.md`.

**Release baseline:** [v2.0.2](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.2) · Changes: [CHANGES-v2.0.2.md](../CHANGES-v2.0.2.md) · Evaluator findings: [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md) · [NAMING.md](NAMING.md)

**Reference index (owner / AI):** [FOUNDATION-EXECUTION-TRACKER.md](FOUNDATION-EXECUTION-TRACKER.md) · [ENTERPRISE-UX-STANDARD.md](ENTERPRISE-UX-STANDARD.md) · [ROUTING-TRUTH-ALIGNMENT.md](ROUTING-TRUTH-ALIGNMENT.md) · [SHARED-REFERENCES.md](SHARED-REFERENCES.md) · [FOUNDATION-MATURITY-ROADMAP.md](FOUNDATION-MATURITY-ROADMAP.md) · [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) · [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md)

---

## Owner decisions (pick one before next flagship zip)

| Topic | Options |
| --- | --- |
| `permission_type_id` | Ship schema **or** remove half-port |
| Studio access | Super-dev only **vs** super-admin + `laraslice.studio` |
| Internal naming residue | `docs/NAMING.md` alias table when needed |

---

## Release gates (framework)

| Check | Notes |
| --- | --- |
| `slice:drift` → **0 errors** | Required before replica parity claims on any port (benchmark port v201 achieved 27 Oct; warnings remain) |
| ETL + `--import-auth` | v2.0.1 migrate-before-import |
| PHPUnit | 555 tests; known Windows flakes (`PortDataImportReportingTest`, `DesignLintTest`) |
| Portable discovery cache | `slice:clear` → `slice:cache` after cloning a port tree |

---

## v2.0.2 validation — P0 (from real-user demo, Nov 2026)

1. **Route prefix truth** — Live **`/erp/erp/...`** URIs; agents use **`php artisan slice:route-truth`** / MCP **`get_route_truth`** ([F2 done](d42146fe-049c-45c5-a106-5b6c580e8a0e) `c3abc2d`). **F1 partial on v202:** `slice:qualify-routes` PASS; **`slice:links` FAIL** — resolve **Settings** core vs ERP twin + **`permission_types`** Roles/ReferenceData collision (BACKLOG #6).
2. **Flagship zip installability** — Packagist-only **`resources/stubs/flagship/composer.json`**; generate lock via **`porting/generate-flagship-composer-lock.ps1`** before zip cut (optional `vendor` for offline evaluators).
3. **Windows one-click demo** — **`resources/stubs/flagship/demo.ps1`** + [demo-windows.ps1](demo-windows.ps1): `tar -xf`, `.env` `APP_URL` + absolute SQLite path, `slice:cache`.
4. **Studio honesty** — Version from composer (not hard-coded badge); host slice count matches discovery; SQLite-aware Copilot copy. *(Framework: `FrameworkPackage` + Studio shell — Nov 2026.)*
5. **Porter ReferenceData** — `slice:port --scaffold` can emit merged `currencies` migration over MySQL row limit; split taxonomies in framework or playbook step (see IMPROVEMENT-REGISTER § Zero port erp-port-v202).
6. **MFA env for port QA** — `auth.mfa_enabled` must respect `LARASLICE_MFA_ENABLED` (**validated on erp-port-v202** Nov 2026; **ship in framework** default config).

---

## Product backlog

1. **`slice:verify-journeys`** — Mind journeys → Playwright + DB assert (Gate T harness).
2. **Gate V workbook** — paired screenshots + D/M/S per surface (**erp-port-v202 matrix 5 Nov 2026** — port evidence only; framework workbook still open).
3. **Employee account-backed create** — nested user + details in one transaction (Part C fail class).
4. **Report slices** — attendance/finance as first-class port surfaces.
5. **Studio drift panel** — drift table with jump-to-slice in Dev Studio.
6. **Duplicate slice registry** — twin **Settings** (core vs `app/Slices/ERP/Settings/Settings`) breaks **`slice:links`** on erp-port-v202; **`permission_types`** shared by Roles + ReferenceData — fix or document deviation before F1 PASS ([F1 F2 routing truth](d42146fe-049c-45c5-a106-5b6c580e8a0e)).
7. **Windows test stability** — import test isolation; `tests/README-PLATFORMS.md`.

### Studio UX (Dev / Port / Blueprint)

8. **Owner-simplified Port mode** — optional `?persona=owner` (Journeys + Sidebar + plain “Execute” only).
9. **Terminology pass** — align UI strings with shell modes (Port / Design / Manage); remove “Apply” on Port path.
10. **Retire duplicate Port modal** — single `port-panel` in shell; wizard opens Port mode deep link.
11. **Home ↔ Plan/Operate** — surface Operate/Plan on Studio Home when permitted.
12. **Post-Execute checklist UI** — link `slice:drift` + replica status doc after successful port.

### UI / JEV (internal Body→kit — not TypeSafe product)

13. **P1 Kit contract renderer** — Body regions → `docs/KIT-CONTRACT.md` → components (`SliceGenerator`, `DirectoryUi`).
14. **`activity` region** — close contract gap in generator/show templates + drift rules.
15. **Second markup kit spike** — prove `design.system` swap beyond token-only Kit #2.
16. **Optional TypeSafe Jev** — port/Studio closed-choice decisions via `typesafe-ai` skill; keep separate from `JevDecisionService`.

Truth reference: [JEV truth in LaraSlice](691fd6b7-2361-4c0a-91fb-452a170570b2) assessment (Oct 2026).

---

## Port programme v2

**Playbook:** [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md) — Analyze → Phase 0 → Phase D nav → Gates S/D/F/T/V → Studio handoff; **100%** = lowest gate PASS on signed scope in `REPLICA-STATUS.md`.

| Epic item | Ties to |
| --- | --- |
| Benchmark ERP forward path | `erp-port-v201` — drift 0 errors, `--import-auth`, Phase D (`porting/PHASE-D-ALL.md`) |
| **Zero port v2.0.2 proof** | `erp-port-v202` — scaffold + Gate D/F; Gate **T benchmark PASS**; Gate **S closure applied** (64→16 missing-col tables, 5 Nov 2026); Gate **V D/M/S classified**; headline **~50%**, **not 100%** |
| Gate T harness | Backlog #1 `slice:verify-journeys` + employee account-backed create (#3) |
| Gate V workbook | Backlog #2 paired screenshots + D/M/S |
| Studio daily driver | #5 drift panel, Plan/Operate for non-technical sign-off (playbook § Studio handoff) |
| Internal JEV / kit | #8–#10 Body→`KIT-CONTRACT.md`; optional **TypeSafe Jev** (#11) stays separate from `JevDecisionService` |
| Part C evidence | `erp-replica` — baseline gates, not canonical successor |

**Canonical 2.0.1 tree:** `laragon/www/erp-port-v201` — runbook: host app `VERIFICATION-PROGRAMME.md`.  
**Zero port 2.0.2 tree:** `laragon/www/erp-port-v202` — `:7005`, `porting/ZERO-PORT-2.0.2-LOG.md`, `porting/RUNBOOK.md`.  
**Part C baseline (2.0.0):** `laragon/www/erp-replica/porting/REPLICA-STATUS.md`.
