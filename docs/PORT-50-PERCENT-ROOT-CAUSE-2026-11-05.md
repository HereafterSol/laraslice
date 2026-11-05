# R&D — Why the ERP programme is ~50%, not 100% (5 Nov 2026)

**Audience:** Muse handoff, programme owner, architects, agents  
**Authoritative port headline:** `E:\laragon\www\erp-port-v202\porting\REPLICA-STATUS.md`  
**Framework programme:** `docs/PROGRAMME-STATUS-2026-11-05.md` · `docs/FOUNDATION-EXECUTION-TRACKER.md`

---

## اردو خلاصہ (Roman Urdu)

- **~50%** marketing nahi — **lowest gate wins** rule hai: jab tak **Gate S** aur **Gate V** **PARTIAL** hain, **100%** claim nahi hota (`REPLICA-PROTOCOL.md`, `PORT-100-PLAYBOOK.md`).
- **118** source tables abhi port mein nahi; **`discussions`** parent table documented exclude (`deviations.yaml` → `gate-s-discussions-table`).
- **D** aur **F** benchmark par **PASS** — data/furniture baseline theek; poora ERP surface abhi nahi.
- **Gate T** port par PHPUnit + HTTP matrix **PARTIAL** — source **:7001** paired **(a)(b)(g)** benchmark par fail (documented), port par kaafi journeys pass.
- **Gate V** HTTP **15/16**, PNG capture + **D/M/S** matrix — **pixel PASS nahi**; visual gate incomplete.
- **Framework track (F1–F4)** aur **port track (N3, X5, Gate T)** **parallel** the — duplicate failure nahi; foundation merge + gate slices alag kaam.
- **Porter** account-backed create, ReferenceData split, **118-table** closure abhi automatic nahi — zyada **agent hours** se % nahi uthega; ordered closure chahiye (neeche §6).

---

## 1. Why ~50% and not 100%?

Three lenses — all true at once; none contradicts the others.

### 1a) Programme / gate definition (honest headline)

The replica protocol fixes vocabulary so the team cannot confuse “lots of slices exist” with “replica PASS.”

| Rule | Source | Effect on headline |
| --- | --- | --- |
| Five gates in order **S → D → F → T → V** | `docs/REPLICA-PROTOCOL.md`, `docs/PORT-100-PLAYBOOK.md` | Status = **lowest unverified gate** on the last full run |
| Declare **100%** only when all in-scope gates **PASS** on the **same** run | `PORT-100-PLAYBOOK.md` § “What 100% means” | **Gate S PARTIAL** + **Gate V PARTIAL** ⇒ headline stays **~50%**, not a rounding error |
| Quote status **only** from `porting/REPLICA-STATUS.md` | `REPLICA-PROTOCOL.md` § Claiming status | Chat, Studio, or “147 slices” do **not** raise % |

**Evidence (erp-port-v202, 5 Nov 2026):**

| Gate | Status | Citation |
| --- | --- | --- |
| **S** | **PARTIAL** (name-matched cols **16→0** via N3) | `erp-port-v202/porting/N3-GATE-S-2026-11-05.md`; **118** source-only tables; **`discussions`** excluded — `deviations.yaml` |
| **D** | **PASS (benchmark)** | `REPLICA-STATUS.md`; `verify-programme.php` / clean import |
| **F** | **PASS (benchmark)** | Directory counts + task board **9/11/14/16** — `REPLICA-STATUS.md` |
| **T** | **PARTIAL (expanded benchmark)** | PHPUnit **GateT\*** pass set; `GATE-T-EXPANSION-2026-11-05.md`; source **:7001** **(a)(b)(g)** fail on benchmark |
| **V** | **PARTIAL** | HTTP **15/16**; **no pixel diff PASS** — `X5-GATE-V-2026-11-05.md`, `gate-v-2026-11-05/SIDE-BY-SIDE-MATRIX.md` |

`docs/PROGRAMME-STATUS-2026-11-05.md` states explicitly: **Honest headline: ~50% whole-ERP replica (lowest gate wins). Not 100%.**

**Framework vs port:** LaraSlice can be **Stage 3 — Defined** with **F1–F4 PASS** while the port remains partial — `docs/VISION.md`, `docs/ARCHITECTURE-VS-PORT-TRUTH.md` (referenced in playbook).

### 1b) Scope: whole ERP vs time and benchmark

The stress test is a **production Worksuite-class monolith** (~**358** source tables, RBAC, documents, hubs) — `docs/VISION.md` § Benchmark ERP.

Measured on v202 (N3 diff file summary in `N3-GATE-S-2026-11-05.md`):

- **358** source tables vs **240** port tables
- **118** source-only tables (including **`discussions`**)
- **147** slices scaffolded; **1056** ERP-prefixed routes in route-truth index — `REPLICA-STATUS.md`, `F1-ROUTE-TRUTH-LOG.md`

**D/F PASS** is intentionally **benchmark-scoped** (named entity row parity, directory furniture on signed surfaces) — not “every module journey on every nav root.” **Gate T** expansion doc notes journeys are **not exhaustive over ~27 visible nav roots** — `GATE-T-EXPANSION-2026-11-05.md`.

Foundation mandate (Nov 2026): **net-new ERP slice scaffolding paused** until owner sign-off — `PORTING_FOUNDATION_RULES.md` § Foundation programme; `FOUNDATION-EXECUTION-TRACKER.md` § Porting pause. That trades breadth for **routing truth + flagship install truth** before another table sprawl.

### 1c) What Muse vs agents actually did (parallel workstreams)

Same programme, **not** duplicate failure or “we tried the same thing twice and lost.”

```text
                    ┌─────────────────────────────┐
                    │  LaraSlice v2.0.2-candidate │
                    └──────────────┬──────────────┘
           ┌───────────────────────┼───────────────────────┐
           │                       │                       │
  ┌────────▼────────┐     ┌─────────▼─────────┐   ┌────────▼────────┐
  │ Framework F1–F4 │     │ Port gates N3/X5  │   │ Reference v201  │
  │ route-truth,    │     │ Gate T expansion, │   │ hub YAML,       │
  │ portability,    │     │ formatter lift,   │   │ account-backed  │
  │ flagship lock   │     │ Gate S closure    │   │ patterns lifted │
  └────────┬────────┘     └─────────┬─────────┘   └────────┬────────┘
           │                       │                       │
           └───────────────────────┼───────────────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │ erp-port-v202 (:7005)       │
                    │ REPLICA-STATUS ~50%         │
                    └─────────────────────────────┘
```

| Track | Examples (5 Nov 2026) | Outcome |
| --- | --- | --- |
| **Framework merge / F1–F4** | `slice:qualify-routes`, **`slice:route-truth`**, MCP `get_route_truth`, **`slice:portability`**, flagship `composer.lock` stub | **F1–F4 PASS** — `FOUNDATION-EXECUTION-TRACKER.md` |
| **Port gate slices** | N3 Gate S **16→0** missing cols on matched set; X5 Gate V HTTP **15/16**; Gate T PHPUnit + `gate-t-run.php` | Gates **improved**; lowest gate still **S/V PARTIAL** |
| **Pattern lift (v201 → v202)** | Hub YAML, directory formatters, employee account-backed create | Closes **porter gaps** without abandoning Heart/Kit — `PORTING-DECISION-100-REPLICA.md` |

Muse-oriented docs (`PROGRAMME-STATUS`, `FOUNDATION-EXECUTION-TRACKER`, this file) sit beside **host evidence** (`REPLICA-STATUS`, gate logs). Agents are instructed: read order **PROGRAMME-STATUS → PORT-100 → REPLICA-STATUS** — `PORTING-DECISION-100-REPLICA.md` SDLC test table.

---

## 2. Root cause buckets (evidence-weighted, not blame)

Rating: **High** = clearly limits % today · **Medium** = real cost, partially mitigated · **Low** = friction, not headline driver.

### 2.1 Porting skills / agent instructions

| Aspect | Rating | Evidence |
| --- | --- | --- |
| **What helped** | — | Skill §22 + gates + **`REPLICA-STATUS` only** — `REPLICA-PROTOCOL.md`; §16.5.1 post-analyze mandate — `PORTING_FOUNDATION_RULES.md`; F1 agent rules + **`get_route_truth`** — `ROUTING-TRUTH-ALIGNMENT.md`, tracker F1 row |
| **Gaps** | **Medium** | Skill still large; **X1** skill family split not done — `FOUNDATION-EXECUTION-TRACKER.md`; evaluators told not to use `route:list` alone — `IMPROVEMENT-REGISTER-2.0.2.md` § Skills; **Gate V by eye** easy to skip in multistep runs — playbook AI checklist |
| **Misread risk** | **Medium** | “N slices generated” ≠ ERP-class port — `PORTING_FOUNDATION_RULES.md`; Studio must not imply 100% — `PORTING-DECISION-100-REPLICA.md` anti-patterns |

### 2.2 Framework foundation (Heart / Body / Mind / Soul, VSA, porter vs hand parity)

| Aspect | Rating | Evidence |
| --- | --- | --- |
| **Strengths** | — | Gates, **`slice:drift` 0 errors** on v202, **`slice:links`**, route-truth index, **`deviations.yaml`**, replica kit — `REPLICA-STATUS.md`; Stage **3 Defined** — `ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md` (tracker link) |
| **Porter vs hand-crafted** | **High** | Account-backed directory create **not emitted** by porter — copy v201 pattern — `PORTING_FOUNDATION_RULES.md` § Account-backed; Gate T on fresh scaffold was **High** until v202 manual/service layer — `IMPROVEMENT-REGISTER-2.0.2.md` |
| **ReferenceData / Gate S** | **High** | Merged taxonomy migration blocked import; playbook pre-import step — `PORT-100-PLAYBOOK.md`; porter **250-field cap** / no **`discussions`** parent — `N3-GATE-S-2026-11-05.md`, `deviations.yaml` |
| **VSA / composition** | **Medium** | Phase 0 composition map required before table port — `REPLICA-PROTOCOL.md`; early one-table-one-sidebar = porter bug class — `VISION.md` |

### 2.3 Architecture (legacy monolith, routes, scale)

| Aspect | Rating | Evidence |
| --- | --- | --- |
| **Monolith depth** | **High** | **358** tables, multi-model surfaces, settings hub — `VISION.md`; **118** tables not in port schema — N3 metrics |
| **Account-backed routes** | **High** | Source **`POST /account/employees`** pattern; port unified create in slice service — `PORTING_FOUNDATION_RULES.md`; client account-backed still **next** — `PROGRAMME-STATUS-2026-11-05.md` |
| **Routing truth** | **Low (on v202 after F1)** | Was **High** for agents (`/erp/erp` vs adjudication names) — `ROUTING-TRUTH-ALIGNMENT.md`; v202 **F1 PASS** — `F1-SLICE-LINKS-LOG.md` |
| **YAML / hub / formatters** | **Medium** | Hub YAML lift **done**; directory **M** chrome backlog (filter band, Worksuite widgets) — `PROGRAMME-STATUS-2026-11-05.md`, `DIRECTORY-FORMATTER-LIFT-2026-11-05.md` |
| **Messages module** | **Medium** | No port owner slice — `deviations.yaml` `gate-d-messages-nav` |

### 2.4 Agent harness (multistep, benchmark, host layout)

| Aspect | Rating | Evidence |
| --- | --- | --- |
| **No pixel PASS automation** | **High** for Gate V | Protocol: tokens ≠ visual gate — `REPLICA-PROTOCOL.md`; matrix **D/M/S** without pixel diff PASS — `REPLICA-STATUS.md` |
| **Source :7001 benchmark gaps** | **Medium** for paired Gate T | Source **(a)(b)(g)** fail while port passes — `GATE-T-EXPANSION-2026-11-05.md`, `gate-t-employee-create` deviation |
| **Host-not-git v202** | **Medium** | Evidence lives under `laragon/www/erp-port-v202/porting/`; framework on **`v2.0.2-candidate`** path repo — `REPLICA-STATUS.md`; merge/sync logs — `POST-MERGE-HOST-SYNC-2026-11-05.md` |
| **MFA / headless** | **Low (mitigated on v202)** | Was blocking side-by-side — `IMPROVEMENT-REGISTER-2.0.2.md`; env binding documented in playbook |
| **Phase D silent no-op** | **Medium (fixed)** | `ERP/ERP` vs flat scaffold root — `PORT-100-PLAYBOOK.md`, improvement register |

---

## 3. Is the framework direction correct?

**Balanced verdict:** Yes for **evidence-based porting and AI-legible SDLC**; incomplete for **hands-off 100% ERP** until porter and Gate T/V automation close known gaps.

### Strengths (keep investing)

| Mechanism | Why it matters | Doc |
| --- | --- | --- |
| **Lowest gate wins** | Prevents false 100% | `REPLICA-PROTOCOL.md` |
| **`slice:links` + `slice:route-truth`** | Agents stop 404ing adjudication URLs | `ROUTING-TRUTH-ALIGNMENT.md` |
| **Replica kit + `deviations.yaml`** | Owner verdicts are first-class | `erp-port-v202/porting/deviations.yaml` |
| **Clone vs Kit decision tree** | Page clone time-boxed for Gate V, not default architecture | `PORTING-DECISION-100-REPLICA.md` |
| **Portability lint (F4)** | Heart stays runtime-neutral | `MULTI-RUNTIME-PORTABILITY-CONTRACT.md` (tracker) |

### Gaps (honest)

| Gap | Impact | Doc / tracker |
| --- | --- | --- |
| **Porter closure** — account-backed, split taxonomies, large aggregates | Repeat port-local surgery | `PORT-100-PLAYBOOK.md`, `IMPROVEMENT-REGISTER-2.0.2.md` |
| **Gate T not automatic from `slice:port`** | Every zero port needs pattern copy until generator emits services | `PORTING_FOUNDATION_RULES.md` |
| **Gate V not closed by kit alone** | **~50%** visual class persists after X5 | `PROGRAMME-STATUS-2026-11-05.md` |
| **Flagship zip vs repo stub** | Lock in stub **PASS**; next **zip cut** must ship lock — F3 remaining note | `PROGRAMME-STATUS-2026-11-05.md` |
| **Skill split (X1)** | Token load + router discipline | `FOUNDATION-EXECUTION-TRACKER.md` |

**Crucible-first sequencing** in `VISION.md` remains correct: benchmark gates before multi-runtime marketing.

---

## 4. Adoption realism

### Who may adopt Mind · Body · Heart + VSA soon

| Segment | Condition | Fit |
| --- | --- | --- |
| **Software houses** porting Laravel ERP/CRM | Willing to run gates + Studio; one reference port | Strong — six Studio modes, YAML GitOps — `VISION.md` |
| **AI-forward teams** | Skill + MCP route-truth + drift as CI | Strong if they accept **PARTIAL** headlines until `REPLICA-STATUS` PASS |
| **Enterprises (single vertical)** | One signed module set + NOT COVERED list | Medium — needs **proof on one vertical**, not whole suite day one |
| **Government / regulated** | Mind sign-off, audit trails, deviations.yaml | Medium-long — gates align; adoption needs training + evidence culture |

### Who likely won’t yet

| Segment | Blocker |
| --- | --- |
| **Teams wanting drop-in Worksuite clone in one sprint** | **118** tables + Gate V by eye — scope honest in `PROGRAMME-STATUS` |
| **Org without owner for NOT COVERED / deviations** | Protocol forbids self-approve — `REPLICA-PROTOCOL.md` |
| **NodeSlice-first buyers** | Second runtime **not shipped** — `VISION.md` sequencing |

### Conditions that raise adoption probability

1. **One vertical PASS** on agreed scope (e.g. Work + HR benchmark) with public `REPLICA-STATUS.md`.
2. **Tooling:** porter emits account-backed + taxonomy split; optional pixel diff for Gate V.
3. **Training:** PORT-100 playbook + Phase 0 composition workshops.
4. **Honest demo (:7002)** without replica % on marketing — `PORTING-DECISION-100-REPLICA.md` demo track.

---

## 5. Pain points addressed — and not yet

| Pain | LaraSlice response | Status (5 Nov 2026) |
| --- | --- | --- |
| “We ported tables but not the product” | Phase 0 composition + gates S/T/V | **Partial** — F/T/V open |
| Runtime lock-in | Heart portability lint, kit contract | **F4 PASS**; YAML leakage warnings remain on demo — register |
| AI hallucinating “done” | REPLICA-STATUS + lowest gate | **Working** on v202 |
| Wrong deep links / route names | qualify-routes + route-truth + MCP | **F1 PASS** on v202 |
| Sidebar table explosion | Phase D nav, hidden aux slices | **PASS** ~27 roots — `REPLICA-STATUS.md` |
| ETL lies (counts without joins) | Gate D join proof | **PASS (benchmark)** |
| Studio overclaim | F5 honesty work; no % in Plan | **Partial** — badge/count friction in register |
| Windows evaluator friction | `demo-windows.ps1`, lock stub | **Improved**; zip cut still pending |
| Full ERP schema parity | Porter + closure programme | **Not covered** — **118** tables |
| Pixel-perfect visual parity | Gate V workbook + kit | **Not covered** — no pixel PASS |
| Messages / Discussions parity | Documented excludes / open deviations | **Not covered** |
| Client portal on flagship | Deferred | `IMPROVEMENT-REGISTER-2.0.2.md`, tracker B1 |
| Multi-runtime NodeSlice | Roadmap | `VISION.md` — not near-term |

---

## 6. Path to raise % (ordered — not “more agent hours alone”)

Priority follows **lowest gate** and **generator leverage** — `PORT-100-PLAYBOOK.md`, `PROGRAMME-STATUS-2026-11-05.md`, `FOUNDATION-EXECUTION-TRACKER.md`.

1. **Porter emits account-backed patterns** (employees, clients, `/account/*`) so Gate T is not a manual v201 copy each zero port — `PORTING_FOUNDATION_RULES.md`.
2. **Gate S programme owner decision** — sign NOT COVERED for **118** tables vs fund scaffold/closure waves; resolve **`discussions`** (exclude holds today — `deviations.yaml`).
3. **Framework porter closure** — ReferenceData split by default; batch ALTER lessons absorbed (N3 one-column migration pattern — `N3-GATE-S-2026-11-05.md`).
4. **Gate V matrix closure** — work **M** rows in `SIDE-BY-SIDE-MATRIX.md` (filter band, Worksuite widgets); optional pixel metrics before claiming PASS — `PORTING-DECISION-100-REPLICA.md`.
5. **Gate T verify-journeys** — client account-backed create; expand nav-root matrix beyond expanded benchmark; decide whether to fix **:7001** store for paired source or keep **port-only** deviations — `GATE-T-EXPANSION-2026-11-05.md`.
6. **Messages slice or explicit NOT COVERED** — `gate-d-messages-nav`.
7. **Flagship zip cut** with **`composer.lock`** beside json — F3 remaining item — does not raise replica % directly but unblocks adoption evaluators.
8. **Skill X1 split** — reduces agent error rate on long sessions — tracker X1.

**Explicit non-strategy:** Re-running `slice:port --scaffold` or adding agent hours **without** porter/Gate S/V/T closure **will not** move the honest headline from **~50%** to **100%** — the protocol is designed that way.

---

## Reference index (read order for agents)

| Path | Role |
| --- | --- |
| `docs/PROGRAMME-STATUS-2026-11-05.md` | Programme headline + environments |
| `docs/PORT-100-PLAYBOOK.md` | Ordered programme |
| `docs/REPLICA-PROTOCOL.md` | Gate definitions |
| `docs/PORTING-DECISION-100-REPLICA.md` | Clone vs Kit |
| `docs/PORTING_FOUNDATION_RULES.md` | ERP-class rules + §16.5.1 |
| `docs/VISION.md` | Strategy + crucible |
| `docs/FOUNDATION-EXECUTION-TRACKER.md` | F1–F4, N3, X5, git handoff |
| `docs/IMPROVEMENT-REGISTER-2.0.2.md` | Evaluator + v202 findings |
| `docs/ROUTING-TRUTH-ALIGNMENT.md` | URI vs route names |
| `laragon/www/erp-port-v202/porting/REPLICA-STATUS.md` | **Authoritative % gate table** |
| `laragon/www/erp-port-v202/porting/deviations.yaml` | Owner deviations |
| `laragon/www/erp-port-v202/porting/N3-GATE-S-2026-11-05.md` | Gate S N3 evidence |
| `laragon/www/erp-port-v202/porting/GATE-T-EXPANSION-2026-11-05.md` | Gate T expansion |
| `laragon/www/erp-port-v202/porting/X5-GATE-V-2026-11-05.md` | Gate V HTTP/PNG |

---

*Maintainers: update when `REPLICA-STATUS.md` gate row changes; never bump % without that file.*
