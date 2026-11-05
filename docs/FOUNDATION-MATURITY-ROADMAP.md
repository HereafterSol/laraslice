# Foundation maturity roadmap

**Last updated:** 5 Nov 2026 (post Phase 0 audit)  
**Overall stage:** **3 — Defined** ([ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md))  
**Programme tie-in:** [PROGRAMME-STATUS-2026-11-05.md](PROGRAMME-STATUS-2026-11-05.md) (~**50%** erp-port-v202 — **not 100%**) · **porting paused** until tracker **F1–F3**  
**P0 ship list:** [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md) · [BACKLOG.md](BACKLOG.md#v202-validation--p0-from-real-user-demo-nov-2026)  
**Legacy phase tracker:** [FOUNDATION_ROADMAP.md](FOUNDATION_ROADMAP.md) (Phase A–D item grid — still valid detail)  
**Portability contract:** [MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md)

---

## Maturity pillars

| Pillar | Current (Nov 2026) | Next milestone | Evidence |
| --- | --- | --- | --- |
| **Portability** | Trilogy + RUNTIMES contract; Laravel reference only | Second runtime **proposal** after Gate S/T lift on v202 | `docs/RUNTIMES.md`, Skill §26 |
| **Security** | Studio gates, RBAC bridge, MFA env fix validated on v202 port | Ship `LARASLICE_MFA_ENABLED` in framework default | IMPROVEMENT-REGISTER P0 #6 |
| **Performance** | Drift cache, chunked ETL | Reduce drift warning noise (Body defaults) | BACKLOG # warnings |
| **Studio** | ONE shell, six modes; honesty pass started | Version from composer, SQLite Copilot copy, drift panel | STUDIO-UX-AUDIT, FrameworkPackage |
| **Skills / AI** | Family split (core/start/port/operate) | Router skill + token read order | [AI-FOUNDATION-OPTIMIZATION.md](AI-FOUNDATION-OPTIMIZATION.md) |
| **Cross-lang vision** | NodeSlice et al. = roadmap rows | **No** public “shipped” claims | `VISION.md` paragraph |

---

## Sequencing (post audit — foundation before port lift)

```text
Phase 0 audit DONE (Nov 2026)
  → LaraSlice 2.0.x foundation P0 (F1–F5 in FOUNDATION-EXECUTION-TRACKER)
  → erp-port-v202 gate closure (S columns, Gate V workbook) — resume after F1 PASS
  → Flagship demo install truth (composer.lock, Windows demo.ps1) — F3
  → erp-port-v201 hub lift into v202 where patterns win
  → Stage 4 evidence (route-truth JSON, portability lint CI)
  → Second runtime spike (one sibling) — Defer until Stage 4
  → Family table promotion in RUNTIMES.md
```

**Anti-goal:** Marketing 100% replica before `REPLICA-STATUS.md` lowest gate PASS on signed scope.

---

## Port programme alignment

| Workstream | Owner | Status |
| --- | --- | --- |
| Zero port v2.0.2 | erp-port-v202 | ~50%; S partial (16 missing-col tables); V classified 9/9 HTTP |
| Reference v2.0.1 | erp-port-v201 | Hub YAML, Phase D patterns |
| Flagship demo | flagship-erp-v202-demo | SQLite evaluator; client portal smoke deferred |
| Part C baseline | erp-replica | Historical 2.0.0 gates — not canonical forward root |
| Porting decision policy | Framework docs | [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) |

---

## Framework P0 (must ship in LaraSlice, not port trees)

1. Route prefix truth (`/erp/erp/...` vs skill/MCP paths).  
2. Flagship zip installability (`composer.lock` or Packagist-only).  
3. Windows `demo.ps1` parity.  
4. **Studio honesty** — composer version badge, host slice count, driver-aware Copilot (shipped Nov 2026 pass).  
5. Porter ReferenceData / large migration split.  
6. MFA env respect in framework config default.

---

## Studio & DX

| Priority | Item | Backlog # |
| --- | --- | --- |
| P1 | Drift panel in Operate/Port | #5 |
| P1 | Owner Port persona (`?persona=owner`) | #8 |
| P2 | Retire duplicate port modal | #10 |
| P2 | Post-Execute checklist → REPLICA-STATUS | #12 |

---

## Skills & documentation maturity

| Deliverable | Status |
| --- | --- |
| Port 100 playbook | Done — `PORT-100-PLAYBOOK.md` |
| Architect port decision note | Done — `PORTING-DECISION-100-REPLICA.md` |
| AI token optimization note | Done — `AI-FOUNDATION-OPTIMIZATION.md` |
| Shared references index | Done — `SHARED-REFERENCES.md` |
| Skill § Architect + § AI token budget | Done — canonical `skills/laraslice/SKILL.md` |

---

## Gate targets (v202 — honest)

| Gate | Nov 2026 | Target |
| --- | --- | --- |
| S | PARTIAL (16 tables missing cols) | PASS or signed C/D deviations |
| D | PASS (benchmark) | Hold |
| F | PASS (benchmark) | Hold |
| T | PASS (single test) | Journey harness + employee account-backed create |
| V | PARTIAL (D/M/S matrix) | Workbook + optional pixel diff |

---

## Decision log

| Date | Decision |
| --- | --- |
| 2026-11-05 | **Phase 0 foundation audit** — maturity **Stage 3**; port programme paused for F1–F3; MULTI-RUNTIME-PORTABILITY-CONTRACT added. |
| 2026-11-05 | Foundation maturity doc created; ties programme ~50% headline to P0 register; Studio honesty implemented in framework package. |
| 2026-11-05 | Page-by-page clone scoped as Gate V tactic; Heart/Kit remains strategic default — see porting decision note. |
