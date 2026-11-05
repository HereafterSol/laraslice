# Multi-runtime portability contract (LaraSlice reference)

**Status:** Normative for **Heart + Mind + Body** authoring in LaraSlice 2.0.x.  
**Vision context:** [RUNTIMES.md](RUNTIMES.md) (sibling runtimes are roadmap, not shipped).  
**Audit:** [ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md](ARCHITECTURE-FOUNDATION-AUDIT-2026-11-05.md)

This document states **machine-checkable** rules. Runtimes map declarations to local stacks; they must not fork the vocabulary in host YAML.

---

## 1. Files in scope

| File | May contain | Must not contain |
| --- | --- | --- |
| `porting/system.yaml` (Mind) | Actors, clubs, journeys, navigation intent, `design:` | PHP/Blade/JS; executable logic |
| `porting/ui-spec/*.ui.yaml` (Body) | Layout patterns, columns, filters, tabs | Framework component class names |
| `slice.yaml` (Heart) | Fields, relations, UI declarations, workflows, permissions | `App\`, `Illuminate\`, `.php`, `.blade.php` string values |
| Host `Routes/*.php` | Stack routing | — (not part of cross-runtime bundle) |

---

## 2. Heart vocabulary (runtime-neutral families)

- **Storage types:** use declared families (`string`, `text`, `integer`, `decimal`, `boolean`, `datetime`, `json`, `enum`, …) — not Eloquent cast class names in YAML.
- **UI patterns:** `ui.directory`, `ui.show`, `ui.dashboard`, `ui.document`, `ui.matrix`, `ui.settings_hub` — not `<x-ui.*>` or Livewire tags in YAML.
- **Workflows:** declared transitions and guards in YAML — not PHP closure references.

**CI intent:** a future `slice:portability` (or extended `slice:validate`) fails on forbidden substrings in `slice.yaml` / Mind / Body under `app/Slices` and `porting/`.

---

## 3. Behaviour inheritance (sibling runtime)

A sibling runtime **must** implement:

1. Trilogy read/write parity (no forked keys without ADR).  
2. Slice anatomy boundaries (model, migration, service, routes, views, permissions) in local idioms.  
3. Dev Studio six modes + CLI parity for the same inputs ([DEV_STUDIO_STANDARD.md](DEV_STUDIO_STANDARD.md)).  
4. Unchanged gate semantics: `slice:drift`, real-usage, Replica S→D→F→T→V ([REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md)).

---

## 4. What moves on cross-runtime port

| Asset | Moves? |
| --- | --- |
| Mind | **Unchanged** (copy) |
| Body | **Unchanged** (copy; revise only for signed deviations) |
| Heart | **Unchanged** (copy; runtime maps to ORM/router/UI) |
| Generated PHP/Blade | **Regenerated** on target stack — not copied |
| Data | **ETL** with same join proofs (Gate D) |

---

## 5. LaraSlice 2.0.x gaps against this contract

| Gap | Remediation |
| --- | --- |
| PHP leakage in demo/port YAML | Porter + validator lint (W3 in foundation audit) |
| `slice:portability` missing | Implement lint or document alias to validated rules |
| Flutter export only | Not FlutterSlice runtime — label as **generator**, not family row “shipped” |

---

## 6. Promotion rule (RUNTIMES family table)

A runtime moves from **Roadmap** to **Reference** or **Shipping** only when:

- This contract’s lint passes on a signed reference app, and  
- Replica Protocol lowest gate is PASS on that app’s signed scope, and  
- Dev Studio Standard checklist is owner-signed.

Until then, public docs stay on the honest table in `RUNTIMES.md`.
