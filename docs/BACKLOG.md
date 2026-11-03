# LaraSlice product backlog

Living backlog for the **framework** (not per-port gate status). Port truth: [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) and each app’s `porting/REPLICA-STATUS.md`.

**Release baseline:** [v2.0.1](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.1) · Changes: [CHANGES-v2.0.1.md](../CHANGES-v2.0.1.md) · [NAMING.md](NAMING.md)

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

## Product backlog

1. **`slice:verify-journeys`** — Mind journeys → Playwright + DB assert (Gate T harness).
2. **Gate V workbook** — paired screenshots + D/M/S per surface.
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
| Gate T harness | Backlog #1 `slice:verify-journeys` + employee account-backed create (#3) |
| Gate V workbook | Backlog #2 paired screenshots + D/M/S |
| Studio daily driver | #5 drift panel, Plan/Operate for non-technical sign-off (playbook § Studio handoff) |
| Internal JEV / kit | #8–#10 Body→`KIT-CONTRACT.md`; optional **TypeSafe Jev** (#11) stays separate from `JevDecisionService` |
| Part C evidence | `erp-replica` — baseline gates, not canonical successor |

**Canonical 2.0.1 tree:** `laragon/www/erp-port-v201` — runbook: host app `VERIFICATION-PROGRAMME.md`.  
**Part C baseline (2.0.0):** `laragon/www/erp-replica/porting/REPLICA-STATUS.md`.
