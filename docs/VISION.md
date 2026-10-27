# LaraSlice — vision, mission, and how we measure truth

**Last updated:** 2026-10-27  
**Owner:** Hereafter Solutions (H. Sol)  
**Status:** Living document — update when strategy, gates, or runtime roadmap changes.

Related: [RUNTIMES.md](RUNTIMES.md) (portability family) · [MIND_BODY_HEART.md](MIND_BODY_HEART.md) (trilogy) · [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) (framework vs one port) · [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md) (gates) · [BACKLOG.md](BACKLOG.md) (framework work queue) · [SDLC.md](SDLC.md) (lifecycle)

---

## Mission (why we exist)

Build a **runtime-neutral standard** for describing and operating serious software — so teams (and AI) can **plan, port, verify, and migrate** systems without losing intent in scattered code or a single vendor stack.

We are not building “another Laravel admin.” We are building **the contract** (Mind · Body · Heart · Soul, VSA, gates, Studio, skill) that **LaraSlice implements first**, and that sibling runtimes (**NodeSlice**, **JavaSlice**, **.NetSlice**, **PythonSlice**, **ReactSlice**, **NextSlice**, **AngularSlice**, **AstroSlice**, **HtmlSlice**, **FlutterSlice**, **AndroidSlice**, …) can inherit when each is earned.

Impact is meant to be **long-lived**: legible to humans and AI, useful beyond web ERP, and extensible in principle to other domains (IoT, robotics) where **actors, data, journeys, and operations** still apply.

---

## Vision (where we are going)

1. **One architecture, many runtimes** — the portable asset is **declared truth**, not Laravel code. See [RUNTIMES.md](RUNTIMES.md).
2. **Dev Studio + CLI + AI skill** — same inputs, same gates; machines and humans interchangeable on the protocol.
3. **Standards spread through working software** — reference implementation → flagship proof → **stress-test ports** → second runtime → adoption.

Permanence is **earned**, not declared. See **Built to last** in [RUNTIMES.md](RUNTIMES.md#built-to-last--the-permanence-principles).

---

## Worksuite ERP: our crucible, not our marketing lie

**Worksuite** (`laragon/www/erp`) is the **primary foundation stress test**, not a slide-deck example.

| Why ERP | What it proves |
| --- | --- |
| Depth (hundreds of tables, RBAC, workflows, documents) | Generator, porter, ETL, tenancy, aggregates |
| Real companies in production | Furniture + journeys matter; “CRUD exists” is insufficient |
| Worksuite’s *product* discipline (modules, settings hub, personas) | Our **Mind** navigation and **PORTING_FOUNDATION_RULES** align with how mature ERPs think |

**Important distinction** (see [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md)):

- **Framework strength** = trilogy + VSA + gates + Studio can *host* a port programme.
- **Worksuite parity** = measured only in `porting/REPLICA-STATUS.md` (lowest gate wins).

Wanting a **full Worksuite port** is **correct strategy** for the foundation. Saying the port is **done** before gates pass is **incorrect messaging**. The Replica Protocol exists so the crucible **cannot lie to us**.

**Current programme trees:**

| Port | Role |
| --- | --- |
| `erp-replica` (2.0.0, :7003) | Part C baseline — partial gates documented |
| `erp-port-v201` (2.0.1, :7004) | **Canonical forward path** — drift, clean import, F/T/V |
| `flagship-erp-app` | Demo / Part B — separate status file |

---

## Mind · Body · Heart · Soul — fit for AI and developers

### Verdict: **adoptable and differentiated**, with discipline required

| Audience | What works | What you must enforce |
| --- | --- | --- |
| **Developers** | One folder per capability; YAML + codegen; `slice:drift`, `slice:repair`; escape hatch to plain Laravel | Heart vocabulary stays **runtime-neutral**; no per-slice Blade hacks for standard pages |
| **AI agents** | Skill + gates + `REPLICA-STATUS.md` = mechanical evidence; Mind-first briefs | Skill versioned with package; agents must **not** confuse generator output with Gate T pass |
| **Owners / PMs** | Mind readable; journeys v2; sign-off before build | Journeys from **real nav**, not invented BRD essays ([PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md)) |
| **Future runtimes** | Same files, different mappers | Any Heart field only LaraSlice understands is a **standard defect** |

**VSA + DDD (pragmatic):** Slices are **bounded contexts**, not tables. Aggregates, settings hub, and hidden child tables are **architecture**, not optional UX polish. The early “one table = one slice” phase was a **porter bug class**, not a reason to abandon VSA.

**Soul:** Design system + density + JEV in Mind — keeps UI identity out of a fourth competing file tree. Good for multi-runtime **if** Body stays pattern-based (P1–P16), not React component names.

---

## Strong feedback (honest)

### What is genuinely strong

1. **Problem choice** — Runtime lock-in and illegible systems are real; AI accelerates both discovery and rebuild. Declarations + gates are a credible defense.
2. **Trilogy separation** — Intent (Mind) vs surface (Body) vs runtime (Heart) matches how enterprises and regulators think.
3. **Replica Protocol** — Turning Worksuite into **S→D→F→T→V** is how you avoid the AI graveyard pattern (“shipped a demo, called it ERP”).
4. **Studio six modes** — Plan/Port/Operate as first-class matches software houses, not only solo devs.
5. **Permanence principles** in RUNTIMES — Correctly name failure modes (feature vs standard, lock-in, tacit knowledge).

### What will hurt you if ignored

1. **Parity lag vs vision lag** — Multi-runtime roadmap is **credible only after** LaraSlice passes Worksuite gates on **2.0.1** with clean import and `slice:drift` → 0 errors. Sequence: **crucible first**, NodeSlice second.
2. **Warning noise** — Hundreds of drift warnings train teams to ignore drift. Tighten Body defaults for hidden slices or automate club trimming.
3. **Doc / status schizophrenia** — Multiple port trees without one “canonical” status file confuses humans and AI. **erp-port-v201** is the forward root; old trees are **baseline evidence**, not “the product.”
4. **Runtime-neutral leaks** — Every Laravel-only shortcut in Heart YAML delays the whole family. Review gate: “Could NodeSlice honor this declaration?”
5. **Adoption** — Standards win on **one killer reference port**, not manifestos. Worksuite at **honest partial** is still valuable; **honest PASS** on agreed scope is the milestone that unlocks the narrative.

### IoT / robotics horizon

Coherent **as domain shape** (devices, telemetry, commands, fleets = slices + journeys). **Not** a 2026 deliverable. Record as horizon in RUNTIMES; do not dilute ERP crucible resources.

---

## Sequencing (agreed discipline)

```text
Foundation (LaraSlice 2.0.x)
  → Worksuite port programme (gates, erp-port-v201)
  → Flagship / demos for adoption
  → Second runtime (one sibling, not twelve in parallel)
  → Expand family table in RUNTIMES.md as each ships
```

**AI’s role:** Find gaps fast; **gates** decide ship. **Skill** teaches protocol; **Mind/Body/Heart** are the artifacts AI edits.

---

## How we update this file

| Trigger | Action |
| --- | --- |
| New canonical port root or major gate PASS | Update **Worksuite crucible** table + date |
| Runtime family: first commit on a sibling | Change RUNTIMES row from Roadmap → Alpha |
| Strategy shift (e.g. IoT pilot) | Add subsection with **honest status** |
| Muse / founder alignment session | Append **Decision log** below |

### Decision log

| Date | Decision |
| --- | --- |
| 2026-10-27 | Worksuite is the **foundation stress test**; parity quoted only from `REPLICA-STATUS.md`. Vision doc created; `message/plan.md` (legacy v1.4 audit) removed. erp-port-v201 forward; `ErpSettings` registry rename; drift 54 errors remains open. |

---

## One sentence for the team

**We are building a permanent, AI-legible standard; LaraSlice on Laravel is the proof machine; Worksuite is the exam; gates are the grade.**
