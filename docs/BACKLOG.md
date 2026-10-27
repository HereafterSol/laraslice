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
| `slice:drift` → **0 errors** | Required before replica parity claims on any port |
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

---

## Active port programme

**Canonical 2.0.1 tree:** `laragon/www/erp-port-v201` — runbook: host app `VERIFICATION-PROGRAMME.md`.  
**Part C baseline (2.0.0):** `laragon/www/erp-replica/porting/REPLICA-STATUS.md`.
