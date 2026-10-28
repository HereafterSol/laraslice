# Port 100% playbook — replica or greenfield

**Purpose:** One ordered programme for **any** LaraSlice delivery: port an existing system to a measured replica, **or** build greenfield with the same gates and Studio handoff.  
**Audience:** Project owners, developers, QA, and AI agents.  
**Package baseline:** `hereafter/laraslice` **v2.0.1+** ([release](https://github.com/HereafterSol/laraslice/releases/tag/v2.0.1)).

**Related:** [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md) · [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) · [PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md) · [SDLC.md](SDLC.md) · Skill §22 · Skill §24

---

## What “100%” means (lowest gate wins)

A **replica** claim is **never** a vibe. Status lives in **`porting/REPLICA-STATUS.md`** at the **host application root** (template: [templates/REPLICA-STATUS.md](templates/REPLICA-STATUS.md)).

| Rule | Meaning |
| --- | --- |
| **Five gates, fixed order** | **S → D → F → T → V** (schema, data, furniture, journeys, visual composition). |
| **Overall status** | The **lowest unverified gate** on the last full run — e.g. “Gate T PARTIAL” means you do **not** have 100%. |
| **Declaring 100%** | All in-scope gates **PASS** on the **same** run; deviations empty or **owner-approved**; `NOT covered` empty for signed scope. |
| **Framework vs port** | LaraSlice architecture can be sound while a port is partial — see [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md). |

**Greenfield** does not use “100% replica” language. It uses the same **evidence habits**: Mind sign-off, drift-clean release, §18 real-usage verification, and optional Gate F/T-style checklists for flagship surfaces — without a source system to pair.

---

## Programme overview

```text
                    ┌─────────────────┐
                    │    Analyze      │
                    │ (source or brief)│
                    └────────┬────────┘
                             │
              ┌──────────────┴──────────────┐
              │                             │
     ┌────────▼────────┐           ┌────────▼────────┐
     │  Replica branch │           │ Greenfield branch│
     │  slice:port …   │           │ Plan → Create    │
     └────────┬────────┘           └────────┬────────┘
              │                             │
              └──────────────┬──────────────┘
                             │
                    ┌────────▼────────┐
                    │ Phase 0 map     │
                    │ (surfaces)      │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ Phase D nav     │
                    │ (ERP-class IA)  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ Gates S→D→F→T→V │
                    │ + slice:drift 0 │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │ Studio handoff  │
                    │ (Operate daily) │
                    └─────────────────┘
```

---

## Phase 1 — Analyze

**Replica:** Capture how the source actually works — not how tables are named in isolation.

| Step | Action | Evidence |
| --- | --- | --- |
| A1 | `slice:port --anatomy` (or Porting Studio **Analyze**) | Interaction inventory per page |
| A2 | Source nav compile (`menu.blade.php` / `x-menu-item`, module map) | Mind `navigation:` or `porting/source-nav-inventory.json` |
| A3 | Schema + FK discovery (code or SQL dump) | Porter plan; hard-fail on ambiguous DB |
| A4 | **Post-analyze mandate** — Skill **§16.5.1** before scaffolding parity claims | [PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md) § Post-analyze mandate |

**Greenfield:** Analyze the **brief**, not a codebase.

| Step | Action | Evidence |
| --- | --- | --- |
| G1 | Plan mode / `slice:plan:draft` → draft Mind + Bodies | Marked **draft** until owner signs |
| G2 | Personas, journeys v2 (`laraslice.journeys.v2`), operations | No invented BRD essays — operational steps only |
| G3 | Domain/suite boundaries (VSA) | Architect review; boundary tests in CI |

**Both:** After clone or copy of a port tree: `php artisan slice:clear` → `slice:cache` (portable discovery cache).

---

## Phase 0 — Composition map (before “table porting”)

**Unit of porting = surface**, not table.

1. List every **cross-model surface**: account-backed directories, documents (header + lines), hubs (record + relations + widgets), wizards, joined reports.
2. Assign **one owner slice** per primary menu surface; child tables → tabs, Settings hub, or `navigation.visible: false`.
3. Name **twins** when two slices claim the same source page — every gate pairs **each** twin or records a deliberate duplicate verdict.
4. Account-backed create: one form, one transaction when the source uses one operational form ([PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md) § Account-backed).

Record the map in the port plan or Mind notes; gaps become Gate **T** / **V** entries in `REPLICA-STATUS.md`.

---

## Phase D — Navigation (ERP-class IA)

**When:** After Analyze + Phase 0 + hidden aux slices — **before** furniture sign-off.

| Step | Action |
| --- | --- |
| D1 | Demo-aligned **`navigation.group`** (Work, Finance, HR, …) — not internal namespace labels |
| D2 | ~primary roots only in sidebar; taxonomies/lines/histories hidden |
| D3 | `navigation.roles` / personas on every slice (`admin`, `employee`, `client` when ERP-shaped) |
| D4 | Batch apply: `porting/apply-phase-d-nav.php`, `sync-visible-nav-roles.php`, `slice:repair --remap-nav --publish-ui`, `slice:cache` |

**Benchmark runbook:** host app `porting/PHASE-D-ALL.md` (canonical tree: **erp-port-v201**). Greenfield projects still benefit from the same **group + visibility** rules when building ERP-shaped products.

---

## Gates S / D / F / T / V

Walk **in order**. Update `REPLICA-STATUS.md` after each gate run.

| Gate | Question | Release habit |
| --- | --- | --- |
| **S** | Every source column accounted for? | Column diff; no silent drops |
| **D** | Same entities **by joins**, not counts? | Sample users ⋈ details, invoices ⋈ items, both systems |
| **F** | Furniture matches (labels, columns, cards, actions)? | Per-page element diff; D/M/S classification |
| **T** | Journeys work with **DB asserts** both sides? | Persona-aware; full forms, not HTTP 200 alone |
| **V** | Composition matches **paired screenshots by eye**? | Not token probes alone — region order and shell |

**Framework release gate (all programmes):** `slice:drift` → **0 errors** before replica parity claims on in-scope slices.

**Replica data:** `slice:port … --import-data --import-auth` (v2.0.1 migrate-before-import). Without `--import-auth`, users import with **no role assignments** — treat porter warning as a failed step.

Detail: [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md) · Skill §22 · Skill §21 (page contract).

---

## Greenfield branch (best practices)

Same trilogy, no source pairing:

1. **Mind signed** in Plan — purpose, clubs, journeys, navigation policy.
2. **Body** per slice — directory, hub tabs, field classification; list-only Body is a draft.
3. **Heart** (`slice.yaml`) — runtime truth; generator reads Heart when files disagree.
4. **Build** via Create/Design or AI door (Skill §24) — Standard UI + patterns, not one-off Blade.
5. **Verify** — `slice:drift` zero errors; §18 two-role journeys on flagship domains; composition probe on live pages.
6. **Release boundary** — demo + changes file + docs + **owner approval** ([SDLC.md](SDLC.md) §5).

Porting (§22) is **not** the default start — it is the branch when a system **already exists**.

---

## Replica branch (mechanical path)

Follow Skill §22 runbook in order:

1. Anatomy → Phase 0 map → **§16.5.1** nav/composition.
2. `slice:port` (+ `--permissions`, `--table-prefix`, **`--import-auth`** on data).
3. ETL → prove **Gate D** by joins.
4. Declare surfaces in Heart/Body (filters, hub composition, dashboard widgets, replica shell).
5. `slice:repair` → `slice:cache`.
6. Phase D nav (when ERP-class).
7. Gates **S → D → F → T → V** → `REPLICA-STATUS.md`.
8. Fix at **generator / standard layer** when possible ([BACKLOG.md](BACKLOG.md)).

**Canonical forward port (benchmark):** `laragon/www/erp-port-v201` · Part C baseline: `erp-replica` — quote **that tree’s** status file only.

---

## Studio handoff — daily driver for humans

After first slice fleet exists (port or greenfield), **Dev Studio** is the customization surface — not raw codegen tours.

| Mode | Who uses it | Non-technical expectations |
| --- | --- | --- |
| **Plan** | Owner, PM, BA | Read **plain-language Mind**; sign requirements; review journeys without reading PHP |
| **Port / Create / Design** | Devs, implementers | YAML + preview; GitOps saves with version history |
| **Operate** | Support, ops | Health, backups, access review, operator manual from Mind |

**UX expectations for non-technical users:**

- **Plan** copy is the contract — if they cannot understand it, the Mind is not ready for sign-off.
- **Drafts** (`slice:plan:draft`, AI drafts) stay labeled until a human promotes them — never silent auto-apply.
- **Permissions** match Studio modes; Copilot/MCP same gates as UI (Skill §18).
- **No replica % in Plan mode** — status questions go to `REPLICA-STATUS.md` (ports) or release checklist (greenfield).
- **Customization** stays in YAML + Studio panels (roles, workflow, bulk actions, dashboard widgets) — not “edit generated Blade” for standard pages.

Long-form lifecycle: [SDLC.md](SDLC.md) · Studio standard: [DEV_STUDIO_STANDARD.md](DEV_STUDIO_STANDARD.md)

---

## AI agent checklist

Use on **every** port or greenfield session. Quote status only from files, not from generator output.

### Before build

- [ ] Read host **`porting/REPLICA-STATUS.md`** (if port exists) — if missing, status is **unknown**, not “mostly done”.
- [ ] Read **`porting/system.yaml`** (Mind) for scope — not tickets alone.
- [ ] Read [ARCHITECTURE-VS-PORT-TRUTH.md](ARCHITECTURE-VS-PORT-TRUTH.md) — do not confuse architecture strength with replica %.
- [ ] Replica: Phase 0 composition map written **before** table-by-table porting.
- [ ] After Analyze: **§16.5.1** executed ([PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md)).

### During build

- [ ] Heart wins conflicts until a human edits deliberately (Skill §23).
- [ ] No invented-table directories for account-backed surfaces (Skill §21).
- [ ] Cross-slice: contracts only — no foreign slice models (boundary tests).
- [ ] **`--import-auth`** on replica data import; migrate-before-import order on v2.0.1+.
- [ ] Fixes prefer **generator / `slice:repair`** over one-off slice patches.

### Verification & messaging

- [ ] `slice:drift` → **0 errors** before parity claims.
- [ ] Gate **V**: paired screenshots, by eye — not CSS token probes as visual pass.
- [ ] Gate **T**: DB assertions after journeys; run as real personas, not admin-only.
- [ ] Deviations: record **awaiting owner** — never self-approve.
- [ ] **JEV / UI:** internal Body→kit synthesis per [KIT-CONTRACT.md](KIT-CONTRACT.md) — **not** the TypeSafe Jev product API; optional TypeSafe closed-choice helper is separate from `JevDecisionService` ([BACKLOG.md](BACKLOG.md) UI/JEV items).

### Studio & handoff

- [ ] Leave Studios usable: editable YAML, nav groups, journeys v2 — “N slices generated” ≠ programme complete.
- [ ] Point owners to **Plan** for sign-off and **Operate** for run-state — not to gate tables in chat without linking evidence.

**Skill home:** `skills/laraslice/SKILL.md` (§22 replica, §24 greenfield, §25 SDLC summary).

---

## One-line truth

**Port programme:** Analyze → Phase 0 → Phase D nav → gates S/D/F/T/V with drift clean → Studio handoff. **100% replica** = lowest gate PASS on signed scope in `REPLICA-STATUS.md`. **Greenfield** = same discipline, Mind-first, no false replica claims.
