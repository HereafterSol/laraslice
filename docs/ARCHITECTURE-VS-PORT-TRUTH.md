# Architecture vs port completion

Readers confuse two different questions:

1. **Is LaraSlice’s architecture sound?** (Mind · Body · Heart, VSA, gates, Studio)
2. **Is *this* legacy port a 100% replica?** (only measurable per port)

This page keeps them separate so marketing, AI agents, and project owners quote the right truth.

**Founder intent:** A **production ERP monolith** is the deliberate stress test for the foundation — we *want* a deep port to prove the approach. That ambition does not replace gates; it **requires** them. See [VISION.md](VISION.md). Public docs: [NAMING.md](NAMING.md).

---

## What the framework promises

| Asset | Promise |
| --- | --- |
| **Mind** (`porting/system.yaml`) | Signed intent: actors, clubs, journeys, navigation, operations |
| **Body** (`porting/ui-spec/*.ui.yaml`) | Declared furniture: directories, hubs, documents, dashboards |
| **Heart** (`slice.yaml`) | Runtime truth: data, RBAC, workflows, widgets — **Heart wins conflicts** until a human edits deliberately |
| **VSA** | One bounded context per slice; children live in aggregates, not 80 sidebar roots |
| **Replica Protocol** | Five gates in order; status = **lowest unverified gate** |

The framework does **not** promise that running `slice:port` once yields a finished replica. It promises a **mechanical path** to get there if you walk the gates.

Full protocol: [REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md) · Ordered programme: [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md) · Trilogy: [MIND_BODY_HEART.md](MIND_BODY_HEART.md) · Porting rules: [PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md) · Lifecycle: [SDLC.md](SDLC.md)

---

## What a port must prove

Every port keeps **`porting/REPLICA-STATUS.md`** at the application root (not inside the Composer package). Template: [templates/REPLICA-STATUS.md](templates/REPLICA-STATUS.md).

| Gate | Question | Typical failure mode |
| --- | --- | --- |
| **S** | Does schema match (columns accounted for)? | 242/358 tables, missing columns on matched tables |
| **D** | Does data match on **joins**, not counts? | ETL order, skipped `role_user`, test rows |
| **F** | Does **furniture** match (labels, columns, cards)? | LaraSlice chrome vs source legacy UI widgets |
| **T** | Do **journeys** work with DB asserts both sides? | HTTP 200 without user row; AJAX-only source forms |
| **V** | Does **composition** match paired screenshots (by eye)? | Shell, hub tabs, invoice blocks |

**Claiming “100%”** means all in-scope gates are **PASS** for the signed scope, `NOT covered` is explicit, and deviations are owner-approved. Anything else is “converged with residuals” or **partial**.

---

## Mandatory order (do not skip)

Playbook: [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md) (Analyze → Phase 0 → Phase D nav → gates → Studio handoff).

1. **Phase 0 — composition map** — cross-model surfaces (e.g. employee = user + `employee_details` + directory). See protocol Phase 0.
2. **Mind** — operational `laraslice.journeys.v2` personas; no invented BRD essays ([PORTING_FOUNDATION_RULES.md](PORTING_FOUNDATION_RULES.md)).
3. **Port on current package** — prefer **v2.0.1+**: migrate-before-import, `--import-auth`, portable discovery cache.
4. **`slice:drift` → 0** on in-scope slices before furniture sign-off.
5. **Gates S → D → F → T → V** — update `REPLICA-STATUS.md` after each gate.

---

## Known port roots (this programme)

| Tree | Package | Role |
| --- | --- | --- |
| `laragon/www/erp` | — | Source ERP (benchmark) |
| `laragon/www/erp-replica` | 2.0.0 Part C | Measured **partial** (lowest gate **T**) |
| `laragon/www/erp-port-v201` | 2.0.1 | Fresh re-port programme — **canonical successor** |
| `laragon/www/flagship-erp-app` | demo / Part B | Different status file — not Part C replica |

Do not merge status files across trees. Quote the file in **that** port root only.

---

## For AI assistants and skills

- Read **`porting/REPLICA-STATUS.md`** before stating replica % or “done”.
- Prefer **`porting/system.yaml`** (Mind) over tickets for scope.
- Fixes belong in **generator / standard layer** when possible so the next port inherits them ([BACKLOG.md](BACKLOG.md)).

---

## One-line truth (README / landing)

**LaraSlice is built for gated ports; a port is only as replica as its lowest measured gate in `porting/REPLICA-STATUS.md`.**
