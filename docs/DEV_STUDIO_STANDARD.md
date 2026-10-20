# Dev Studio Standard

**Status:** Contract for LaraSlice and every future Slice runtime (NetSlice, PythonSlice, JavaSlice, FlutterSlice).

**Date:** 20 Oct 2026 (v2 amendment: §0 ONE Studio, same date)

This document captures the unified Dev Studio + CLI experience that both independent reviews (browser + code) agreed on. Code on the current branch implements the highest-leverage items. The rest is the standard every new runtime must copy.

---

## 0. ONE Studio — one developer surface (v2, 20 Oct 2026)

> Decision (Abdur Rehman): the developer experience is **one Studio**, not
> separate Porting / Blueprint / Scaffold / Slice Studio pages. This section
> supersedes the multi-page IA assumed elsewhere in this document; all other
> sections (parity, progressive disclosure, diff-before-save, performance)
> still apply.

A Slice framework ships **exactly one developer surface**: the Dev Studio.
One URL, one shell, one mental model. Tools are **modes** inside the Studio,
never separate pages.

```
/studio?mode=port | design | create | manage
```

| Mode | Absorbs | Job |
|------|---------|-----|
| **Port** | Porting Studio | Import a legacy system (Laravel or SQL dumps) into slices |
| **Design** | Blueprint Studio | Plan domains, model slices in YAML/visual, apply blueprints |
| **Create** | Scaffold Wizard | Guided single-slice creation |
| **Manage** | Slice Studio & Field Manager | Edit fields/UI/workflow of installed slices, diff-before-save |

The journey breadcrumb (§8) becomes the **mode switcher itself**: same
component, same placement, clickable, current mode highlighted. It is
navigation, not decoration.

**Shell contract:**

1. **One GET page.** The shell renders once; mode switches never reload.
   URL updates via `history.replaceState` (`?mode=…`) so deep links land on
   the right mode.
2. **Lazy mount.** A mode's panel (markup + Alpine component) initializes on
   first activation only. Initial payload ≈ shell + default mode, never the
   sum of all modes.
3. **Namespaced state.** Each mode is a named component factory
   (`portingStudio()`, `blueprintStudio()`, …). Modes touch each other's
   state only through the handoffs below.
4. **Endpoints unchanged.** Consolidation is a GET-page concern only. All
   POST/API routes keep names and payloads (§1 parity depends on it).
5. **Legacy routes redirect.** Pre-unification studio URLs redirect to the
   shell with the mode preset (`/wizard/porting` → `/studio?mode=port`).
6. **Mobile.** The mode switcher collapses into the overflow pattern (§7);
   fully usable at 390px, 0px horizontal overflow.

**Mode handoffs** (identifiers, not payloads; the receiver loads its state):

| From → To | Handoff |
|-----------|---------|
| Port → Design | Analyzed source opens in Design, tables pre-mapped to proposed slices |
| Design → Manage | Applying a blueprint lands in Manage with the new slice selected |
| Create → Manage | A scaffolded slice opens in Manage for refinement |
| Manage → Design | "Open in Design" shows the slice inside its domain blueprint |

**Stays separate:** Schema Studio folds into Design as a sub-view (not a
fifth mode). The AI Copilot (§10) stays a shell-level overlay in every mode.
End-user slice pages are untouched.

**Migration path (LaraSlice reference):** Phase 1 shell + Port panel →
Phase 2 Design panel → Phase 3 Create/Manage as native modes → Phase 4
redirects + docs/skill updates. Each phase ships independently; old pages
keep working until their phase lands.

**Acceptance:** one URL loads the shell, all modes reachable without reload;
deep link `?mode=design` works; initial payload measured (§9); each handoff
demonstrated; legacy URLs redirect; 390px clean; CLI parity unchanged;
screenshots of all four modes in the release demo pass.

**For other Slice frameworks:** implement THIS IA from day one. Do not
replicate LaraSlice's historical four-page split; it is deprecated.

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
| Journey breadcrumb | ✅ Port → Design → Customize in all three Studios |
| Visual language unification | ✅ shared journey component; Blueprint overflow already matches |
| Performance contract | 📋 standard (this doc) |

---

## Copy this document

When starting NetSlice, PythonSlice, JavaSlice, or FlutterSlice:

1. Copy `docs/DEV_STUDIO_STANDARD.md` into that runtime’s docs.
2. Implement the CLI ↔ Studio parity table first.
3. Ship the Start-from picker, overflow menu, and mobile Copilot before any visual polish.
4. Treat progressive disclosure and YAML diff as the next functional layer, not optional UX.


## CLI diff

`php artisan slice:diff {slice}` prints the same unified YAML diff as Studio preview and writes nothing. Reuses `ManifestRepository::unifiedDiff()`.
