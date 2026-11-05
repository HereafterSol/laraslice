# LaraSlice product backlog

Living backlog for the **framework** (not per-port gate status). Port truth: [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) and each app’s `porting/REPLICA-STATUS.md`. **Nov 2026 programme close (~50% v202):** [PROGRAMME-STATUS-2026-11-05.md](PROGRAMME-STATUS-2026-11-05.md) ([Complete v202 programme sprint](e616253b-166b-4268-b2f2-70af16520ad8)).

**Release baseline:** [v2.0.2](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.2) · Changes: [CHANGES-v2.0.2.md](../CHANGES-v2.0.2.md) · Evaluator findings: [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md) · [NAMING.md](NAMING.md)

**Reference index (owner / AI):** [SHARED-REFERENCES.md](SHARED-REFERENCES.md) · Foundation maturity: [FOUNDATION-MATURITY-ROADMAP.md](FOUNDATION-MATURITY-ROADMAP.md) · Port architect note: [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) · AI token R&D: [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md)

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

1. **Route prefix truth** — Navigation uses `/erp/erp/{area}/{slice}`; `route:list` / skills cite `erp/adjudication/...` → **404**. Align registration, `slice:links`, MCP index ([register § UI/UX #1](IMPROVEMENT-REGISTER-2.0.2.md)).
2. **Flagship zip installability** — Ship **`composer.lock`** or documented Packagist-only `composer.json` (no SSH VCS repo); optional `vendor` for offline evaluators.
3. **Windows one-click demo** — `demo.ps1` or doc: `tar -xf`, `.env` `APP_URL` + absolute SQLite path, `slice:cache` ([register § Demo](IMPROVEMENT-REGISTER-2.0.2.md)).
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
6. **Duplicate slice registry** — twin naming when vendor + ERP both register `Settings`.
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
