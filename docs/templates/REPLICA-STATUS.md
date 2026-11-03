# Replica Status — `<source name>` → LaraSlice `<package version>`

**Port root:** `<absolute or repo path>`  
**Source:** `<system>`, `<database>`, `<url>`  
**Last gate run:** `<date>`  
**Overall status:** **Gate _X_ — PASS | PARTIAL | FAIL** (lowest unverified gate = status)

This port is **not** declared 100% until all in-scope gates are PASS.

Evidence index: `porting/` (diffs, logs, `gate-v/`, `GATE-T-LOG.md`).

---

## Gate S — Schema parity

| Metric | Value |
| --- | --- |
| Source tables | |
| Replica tables | |
| Name-matched | |
| Missing source columns (tables) | |

**Verdict:** PASS | PARTIAL | FAIL  
**Evidence:**

---

## Gate D — Data truth

- Join-verified samples:
- FK orphan sweep:
- ETL command used:

**Verdict:** PASS | PARTIAL | FAIL  
**Evidence:**

---

## Gate F — Furniture parity

| Surface | Verdict | Notes |
| --- | --- | --- |
| Dashboard (persona: ) | | |
| Employees directory | | |
| Projects / tasks | | |
| Clients / invoice doc | | |

**Verdict:** PASS | PARTIAL | FAIL  
**Evidence:**

---

## Gate T — Task conformance

| Journey | Source | Replica | DB assert |
| --- | --- | --- | --- |
| Employee create + login | | | |
| Project + status | | | |
| Task board move | | | |
| Ticket + reply | | | |
| Invoice + item + document | | | |
| Settings save | | | |

**Verdict:** PASS | PARTIAL | FAIL  
**Evidence:** `porting/GATE-T-LOG.md`

---

## Gate V — Visual conformance

Paired screenshots: `porting/gate-v/` or `porting/gate-v-<date>/`  
Classify deltas: **D** (data), **M** (missing), **S** (style/chrome).

**Verdict:** PASS | PARTIAL | FAIL  
**Evidence:**

---

## NOT covered

- 

---

## Deviations (owner-approved only)

| Item | Reason | Approved by |
| --- | --- | --- |
| | | |

---

## Personas used for verification

| Persona | Credentials | Notes |
| --- | --- | --- |
| | | |
