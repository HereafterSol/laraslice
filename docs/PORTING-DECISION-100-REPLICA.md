# Porting decision — 100% A–Z ERP replica demo

**Status:** Living decision note (5 Nov 2026)  
**Audience:** Owner, Muse, architects, port engineers, AI agents  
**Honest baseline:** Programme benchmark `erp-port-v202` is **~50%** replica (lowest gate wins) — see [PROGRAMME-STATUS-2026-11-05.md](PROGRAMME-STATUS-2026-11-05.md) and host `porting/REPLICA-STATUS.md`.

**Related:** [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md) · [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md) · [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) · [PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md) · Skill §16.5.1 · Skill §22 · [PORTING-DECISION note in Skill](../skills/laraslice/SKILL.md)

---

## The question

For a **world-class, demonstrably honest** full ERP replica (sales demo, regression crucible, flagship proof), when do we:

1. **Page-by-page usage clone** — match what users see and do on each surface, even when that means **per-surface** Blade/controller fidelity and temporary deviation from generic Body/Kit shortcuts; or  
2. **Mind · Body · Heart · Kit path** — declare surfaces in YAML, render through the portable kit contract, and fix gaps at the **generator/porter** layer?

Both are valid **phases** of the same programme. The defect is **choosing the wrong default for the gate you are trying to pass**.

---

## Decision tree (architect port mode)

```text
START: Signed replica scope documented in REPLICA-STATUS.md (modules, personas, NOT covered list)
  │
  ├─ Gate not yet run for this surface?
  │    └─ YES → Analyze + §16.5.1 (nav, composition map, hide child slices) BEFORE scaffold claims
  │
  ├─ Is this surface on the signed scope list?
  │    └─ NO → Do not port; record under NOT covered
  │
  ├─ Does source behavior span multiple models / account-backed create / hub tabs / document lines?
  │    └─ YES → Phase 0 composition owner MUST be named (Heart path). Page-clone-only without
  │             composition map → Gate T/F fail class. Fix porter/generator, not one-off Blade forever.
  │
  ├─ Is the gap **visual chrome only** (spacing, badge, tab order) with same data contract?
  │    └─ YES → Prefer Body/Kit + Gate V workbook; page clone only for evidence capture, not permanent fork
  │
  ├─ Is the gap **interaction or wiring** (wrong route, missing bulk action, broken workflow POST)?
  │    └─ YES → Heart + generator fix first; temporary host patch allowed only with ADR + drift ticket
  │
  ├─ Is the gap **class M/S** on Gate V after Kit path exhausted?
  │    └─ YES → Time-boxed page-by-page clone **for that surface**; record deviation in REPLICA-STATUS;
  │             backlog item to fold pattern back into Body/Kit
  │
  └─ Declaring 100%?
       └─ ALL in-scope gates S→D→F→T→V PASS on same run; slice:drift → 0 errors; lowest gate quoted only
          from REPLICA-STATUS.md — never from Studio or chat
```

---

## Two paths compared

| Dimension | Page-by-page usage clone | Mind · Body · Heart · Kit |
| --- | --- | --- |
| **Best for** | Gate **V** evidence, unknown widget, deadline demo of **one** flagship screen | Sustainable port fleet, second runtime, AI-authored YAML |
| **Speed (short)** | Can look “done” quickly on paired URLs | Slower until porter catches source patterns |
| **Speed (long)** | Debt compounds; drift untestable | `slice:drift`, regen, repair scale |
| **Portability** | Poor — Laravel/Blade locked | Strong — contract travels to NodeSlice et al. |
| **AI fit** | Agents copy HTML; plan/HTML drift risk | Agents edit declarations; gates judge renders |
| **Honesty** | Easy to overclaim “100%” from screenshots | Forces gate vocabulary and NOT covered list |
| **Owner narrative** | “Looks like the old system” | “Same journeys, provably same schema/data/behavior” |

**Rule:** Page clone is a **tactical instrument** for classified Gate V gaps and capture sessions — not the **strategic** definition of LaraSlice ports.

---

## Gates as go/no-go (S / D / F / T / V)

| Gate | What it proves | Page clone helps when… | Kit/Heart must win when… |
| --- | --- | --- | --- |
| **S** Schema | Column/type parity | Never — diff is mechanical | Always — porter emits migrations |
| **D** Data | Join-following ETL truth | Never substitute row counts | ETL order, `--import-auth` |
| **F** Furniture | Labels, columns, cards users see | Pairing worksheets | Declarative directory/formatter |
| **T** Journeys | Create/edit flows, RBAC, MFA | Debug only | Account-backed creates, workflows |
| **V** Visual | Composition, density, chrome class D/M/S | **After** kit attempt documented | Default render path |

**Lowest gate wins** for headline status — see [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md).

---

## SDLC test — does the stack hang together?

Use this checklist before approving a port phase (not a substitute for gates):

| Layer | Test question | Fail signal |
| --- | --- | --- |
| **Mind** | Nav and journeys match **source compile**, not invented modules? | BRD essays diverge from `menu` inventory |
| **Body** | Page Structure + List Contract satisfied without Blade escape hatches? | Standard pages only in custom Blade |
| **Heart** | Slice YAML runtime-neutral; aggregates named in Phase 0? | One-table-one-sidebar porter layout |
| **Soul** | Tokens/kits match enterprise preset on demo URLs? | Consumer radii/glow on ERP chrome |
| **VSA** | Bounded contexts own aggregates, not table sprawl? | Duplicate Settings twins |
| **DDD** | Composition map names cross-model surfaces? | Directory without account backing |
| **UserSlice** | Admin / employee / client journeys each smokes? | Admin-only demo |
| **Studio** | Analyze → review → Execute copy honest; status from REPLICA-STATUS? | Studio badge implies 100% |
| **AI** | Agent read order: PROGRAMME-STATUS → PORT-100 → REPLICA-STATUS? | Chat % without evidence file |

---

## Recommended programme mix (ERP crucible)

1. **Foundation track (always):** Porter + §16.5.1 + gates on `erp-port-v202` — fix at generator (IMPROVEMENT-REGISTER P0).
2. **Gate V track (parallel, time-boxed):** Workbook + paired screenshots; page clone **only** for surfaces stuck at M after Kit attempt — fold winners back into Body.
3. **Demo track:** Flagship zip (:7002) — SQLite, role matrix, **no** replica % on marketing; link to programme status doc.
4. **Reference hub:** `erp-port-v201` patterns lifted into v202 — not a second truth file.

---

## Anti-patterns

- Declaring replica % from Studio Analyze or Copilot.
- Permanent Blade forks for standard directories “because Kit is faster today”.
- Skipping **Discussions** (or any signed module) without explicit NOT covered + owner sign-off.
- Pairing only the healthy twin slice when two slices claim one source page (QA8 class).
- Using generic design-system shortcuts on **benchmark surfaces** before Gate V class is recorded.

---

## When to escalate to owner

- Any **PASS** claim for Gate S while >0 missing-column tables on signed scope.
- Choosing page-clone for **>3** flagship surfaces without fold-back plan.
- Scope change that adds modules without updating NOT covered and re-baselining gates.

---

## References (ports)

| Tree | Role |
| --- | --- |
| `laragon/www/erp` | Source (:7001) |
| `laragon/www/erp-port-v202` | Zero port benchmark (~50%, :7005) |
| `laragon/www/erp-port-v201` | Hub / 2.0.1 reference (:7004) |
| `laragon/www/flagship-erp-v202-demo` | Evaluator demo SQLite (:7002) |
