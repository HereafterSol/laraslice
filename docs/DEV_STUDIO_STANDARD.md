# Dev Studio Standard

**Status:** Contract for LaraSlice and every future Slice runtime (NetSlice, PythonSlice, JavaSlice, FlutterSlice).

**Date:** 20 Oct 2026

This document captures the unified Dev Studio + CLI experience that both independent reviews (browser + code) agreed on. Code on the current branch implements the highest-leverage items. The rest is the standard every new runtime must copy.

---

## 1. CLI ↔ Studio parity

Every Studio action must map to a documented CLI command with the same inputs and the same plan/hash output.

| Studio action | CLI equivalent |
|---------------|----------------|
| Load Example / Start from… | `slice:blueprint:plan <path>` or `slice:port --dry-run` |
| Generate / Apply | `slice:blueprint:apply <path> --plan-hash=… --yes` |
| Run Migrations | `php artisan migrate` (or `--migrate` on apply) |
| Seed demo | `php artisan slice:seed <Slice> --count=10` |
| Validate YAML | `php artisan slice:validate <path>` |
| Port source | `php artisan slice:port <path> --dry-run` |

**Rule:** Studio shows the plan (and hash) before execute. CLI and Studio write the same files + manifest. No Studio-only behavior.

---

## 2. Generate = files + migrate + optional seed

One action, not three manual steps.

- CLI: `slice:blueprint:apply --yes` prompts to migrate (or use `--migrate`). `--seed` seeds demo records and implies `--migrate`.
- Apply output always prints the exact seed command.
- Studio: Generate preview shows “will write files + migrate + optional seed”; one click executes the chosen steps.

---

## 3. Single “Start from…” picker

One picker with sections:

- **Presets / Archetypes** (ERP, Healthcare, E-Commerce, …)
- **Examples (v0.1)** (Kitchen Sink, CRM, HR, Shop)
- **My Blueprints / Installed**

No overlapping “Choose Preset” and “Load Example” dropdowns.

---

## 4. Progressive disclosure (Slice Studio)

The wizard has five steps. Enforce them:

1. Template / example picker only
2. Platform & architecture
3. Workflow & roles
4. Access control
5. Review & generate (summary appears here)

Do not show the full field/UI editors until the user is on the Studio tab. Target: five simple screens, not hundreds of controls at once.

*(Landed: wizard summary deferred until step ≥ 3, steps locked until project name, mobile progress bar, Studio UI architecture collapsed by default.)*

---

## 5. YAML diff + version history before overwrite

Studio edits to an existing slice must show a diff and write to version history (`slice_ui_versions` or equivalent). No silent overwrite.

CLI must support the same: `--diff` or dry-run before apply.

---

## 6. Unified visual language

Adopt Porting Studio’s header pattern across all Studios:

- Breadcrumb + title + **one primary action** + overflow menu
- Same button hierarchy: primary filled, secondary outline, danger red
- Same empty states
- Same step / progress indicator style

Secondary actions (Import, Add from DB, Run Migrations) live in an overflow menu on viewports < `md`. Visual / YAML toggle and primary Generate stay visible.

---

## 7. Intentional mobile layouts

Not just “no horizontal overflow.”

- Bottom or sticky primary action
- Collapsible sections
- AI Copilot starts collapsed on small screens (icon restore chip); must not cover primary content
- For FlutterSlice, treat mobile as a first-class layout, not a stacked desktop

---

## 8. Journey breadcrumb

Porting → Blueprint → Slice Studio is the real path. A persistent “where am I” indicator (or short guided strip) must be visible so a first-time porter sees the sequence. Porting Studio’s numbered workflow cards are the pattern to extend.

---

## 9. Performance contract

Studio lists, dropdowns, and slice pickers must be paginated or virtualized above ~100 slices. Current scale (ERP ~85 slices) is fine; the contract exists so future runtimes stay usable.

---

## 10. AI Copilot

- Collapsible on mobile by default
- Does not cover primary actions or the bottom-right content area when collapsed
- Same slash-command surface in every Studio
- Built-in answers work without an API key

---

## What landed on this branch

| Item | Status |
|------|--------|
| Apply includes migrate + `--seed` hint | ✅ `slice:blueprint:apply --migrate` / `--seed` |
| Single Start from… picker | ✅ Blueprint Studio |
| Header overflow on small screens | ✅ Blueprint Studio |
| AI Copilot collapse on mobile | ✅ icon-only + restore chip |
| Progressive disclosure (Slice Studio) | ✅ wizard + Studio toggle |
| Diff / version history | ✅ preview modal before Studio save |
| Journey breadcrumb | 📋 standard (this doc) |
| Visual language unification | 📋 standard (this doc) |
| Performance contract | 📋 standard (this doc) |

---

## Copy this document

When starting NetSlice, PythonSlice, JavaSlice, or FlutterSlice:

1. Copy `docs/DEV_STUDIO_STANDARD.md` into that runtime’s docs.
2. Implement the CLI ↔ Studio parity table first.
3. Ship the Start-from picker, overflow menu, and mobile Copilot before any visual polish.
4. Treat progressive disclosure and YAML diff as the next functional layer, not optional UX.
