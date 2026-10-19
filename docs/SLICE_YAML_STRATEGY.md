# Strategy: The Universal Slice YAML Standard

**Date:** 19 Oct 2026
**Author:** BrandUp (for Abdur Rehman)
**Status:** STRATEGY — guides all future work

---

## 1. The Vision

One YAML format that describes an entire application domain (ERP, CRM, HR...). That YAML generates working software on any framework:

```
LaraSlice (Laravel) → NetSlice (.NET) → JavaSlice → ReactSlice →
NextSlice → FlutterSlice → AstroSlice → AngularSlice → ...
```

Copy a YAML file into a Slice app → it renders. Paste ERP YAML on Android → the whole ERP runs locally on SQLite. Ask an AI "I need an ERP" → it generates YAML → instant app. WhatsApp-like sync across devices.

**The goal:** every AI adopts the Slice YAML standard. We make the standard; they follow us.

---

## 2. Why This Direction Is Validated (Not Just Instinct)

### Workday + TypeSafe Jev (Oct 2026) — the industry just proved this

Workday made Sana (their $1.1B AI assistant) the default home screen for HR/finance/IT. The UI is **built on the fly** — but not by generating HTML. They gave TypeSafe's **Jev** model every atomic component in their design system and hard-coded **none** of the selection logic.

The key quote from Workday's Chief AI Officer (Joel Hellermark):

> "If you use a classification model instead and you just give it all of the design components to pick from, you can literally do it instantly."

**How Jev works:**
- NOT a text generator. It's a **decision model**: given state + a fixed list of options, it returns probabilities (70–500ms, ~22x cheaper than frontier LLMs)
- Three primitives: `choice` (pick from list), `score` (rubric), `noul` (yes/no probability)
- **Cannot hallucinate outside the options you supply** — schema-valid by construction

**What this means for Slice YAML:**

| Workday/Jev | Slice YAML equivalent |
|-------------|----------------------|
| Fixed design-system components | BlatUI (shadcn) component library |
| Classification: "which component for this intent?" | Formatter inference: `status` → `status_pill` |
| 70–500ms (no token generation) | YAML → render, no LLM in the hot path |
| Hard-code none of the selection logic | YAML declares intent; framework renders |

**The industry is converging on exactly our architecture:** a fixed component library + a declarative spec + fast selection (not slow generation). We're not chasing a trend — we're ahead of it.

Sources:
- https://www.worldprogramming.org/posts/workday-is-using-typesafes-jev-to-build-user-interfaces-on-the-fly-wvkumt
- https://theinference.org/article/a16z-priced-typesafe-at-7-5-billion-workday-put-the-four-week-old-model-on-every-user-s-home-screen
- https://dev.to/dishant0406/jev-by-typesafe-ai-the-hype-reactions-and-two-week-clone-war-132f

---

## 3. The Role of BlatUI (shadcn)

**Yes — BlatUI + YAML is the right combination, and it's a massive advantage.**

The Jev lesson: the AI should **select from a fixed component set**, not generate UI freely. BlatUI *is* that fixed set:

```
YAML intent              BlatUI component
─────────────────        ──────────────────
"status column"     →    <x-ui.status-pill>
"money field"       →    <x-ui.money-breakdown>
"date, highlight    →    <x-ui.date-overdue>
 overdue"
"person reference"  →    <x-ui.composite-user>
"long text"         →    <x-ui.text-truncate>
```

**Why shadcn specifically helps:**
- It's already the de facto standard AI-generated UI (v0, Lovable, etc. all output shadcn)
- Every AI already knows shadcn components — zero training needed
- Accessible, themeable, copy-paste (not npm dependency hell)
- When we say "our YAML renders to shadcn," every AI instantly understands the target

**The YAML never describes HTML.** It describes intent. BlatUI is the renderer. This is what makes the spec universal — FlutterSlice maps the same intent to Flutter widgets, ReactSlice to React components. The YAML doesn't change.

---

## 4. How the Standard Gets Adopted (Honest Version)

Standards are **adopted, not declared**. The path:

### Phase A: Prove it on ONE framework (NOW)
Make LaraSlice undeniably excellent for Laravel. The porting pipeline, Studio UX, formatter inference — this is the proof. A developer should choose LaraSlice because it's the fastest way to ship a Laravel admin system, not because of a spec document.

### Phase B: Extract the spec from what works (AFTER A)
Write down the YAML subset that *actually generated good apps*. Not the superset you imagine — the subset that's proven. This becomes **Slice YAML v1.0**.

**Critical rule:** the spec describes **intent, not implementation.**
```yaml
# ✅ GOOD — intent (universal)
columns:
  - key: status
    formatter: status_pill

# ❌ BAD — implementation (Laravel-specific)
columns:
  - key: status
    render: '<span class="text-danger">{{ $row->status }}</span>'
```

### Phase C: Second framework (FlutterSlice)
Mobile + SQLite + offline sync matches the Android vision. If the *same YAML* generates a good Laravel app AND a good Flutter app, you have a standard. If it doesn't, the spec needs work — and you'll know exactly where.

### Phase D: AI adoption
Once two frameworks prove the YAML, publish the spec + a reference validator. AI tools adopt it because their users ask for it ("generate me a Slice YAML"), not because we asked them to.

---

## 5. Design Principles for Slice YAML v1.0

1. **Intent over implementation.** YAML says *what*, framework says *how*.
2. **Fixed vocabulary.** Formatters, field types, actions come from a closed list (like Jev's fixed options). Open-ended strings are where hallucinations live.
3. **VSA + DDD structure.** Each slice is self-contained: domain, fields, UI, workflow, permissions. This is what makes "copy YAML → get app" possible.
4. **Progressive disclosure.** A minimal YAML (name + fields) generates a working CRUD. Rich YAML (workflows, formatters, dashboard) generates a rich app. AI starts simple, refines iteratively.
5. **Validation is the contract.** A JSON Schema for Slice YAML that any tool can validate against. If it validates, it renders — on any framework.
6. **Comments are first-class.** YAML allows `#` comments. Generated YAML should explain *why* (`# status_pill because values are: draft, active, archived`).

---

## 6. What This Means for Current Work

| Current task | How it fits |
|--------------|-------------|
| Phase 1 formatter inference | ✅ Building the intent→component mapping (the Jev "options list") |
| Phase 2 DataTable parser | ⚠️ Extract *intent* from DataTables, not implementation. Map to formatter vocabulary, don't copy closures. |
| `slice:repair --re-infer-ui` | ✅ Applies new inferences to existing slices — the upgrade path |
| YAML elimination spec | ✅ Single manifest file = cleaner spec to standardize |
| Dual-write (current) | ✅ Stepping stone — proves YAML carries everything before JSON is cut |

**One correction:** Phase 2 should NOT try to replicate DataTable closure logic. It should classify each `editColumn` into the fixed formatter vocabulary (like Jev classifies into fixed options). If a closure doesn't match a known pattern, skip it — don't invent a new formatter.

---

## 7. The Moat

If this works, the moat isn't the YAML syntax — it's:

1. **The component library** (BlatUI) — the render target every AI already knows
2. **The formatter vocabulary** — the closed set of intents (status_pill, money_breakdown, date_overdue...)
3. **The tooling** — Studio, CLI, porting pipeline, validators
4. **The proof** — real apps (ERP port) running on real frameworks

Anyone can copy a YAML spec. Nobody can copy the ecosystem overnight.

---

## 8. Open Questions

1. **Spec governance:** Who versions Slice YAML? (Suggest: semver, `slice.yaml` declares `spec_version: 1.0`)
2. **Flutter first or React first** for Phase C? (Recommend Flutter — matches offline/SQLite vision, less crowded than React admin space)
3. **Validator:** JSON Schema published where? (Suggest: `schemas.sliceframework.dev/slice-v1.json`)
4. **Sync protocol:** For the WhatsApp-like multi-device sync — CRDTs? Operational transform? (Defer — needs its own spec)
