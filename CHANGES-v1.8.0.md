# LaraSlice v1.8.0 — ONE Studio + YAML-Only Manifests + Default UI (DRAFT)

**Status:** DRAFT — demo passes complete (release surfaces + full-flow gate 12/12 + side-by-side comparison with the source ERP), awaiting user approval, then tag + GitHub release.

This release finishes the manifest unification begun in v1.7.0: `slice.yaml` is now the **only** manifest format — JSON reads are gone — and Studio saves can no longer overwrite silently. It also ships the **ONE Studio** unification (the four developer studios become one shell with four modes) and a **Default UI program**: generated and ported apps now present correctly with zero hand-tuning, proven side-by-side against a port of a real, production ERP application.

---

## 1. YAML elimination complete — `slice.yaml` is the only manifest

| Merge | What it changed |
|-------|-----------------|
| `facd0ccd` — Phase 1 | Dual-read: `ManifestRepository` reads `slice.yaml` first, falls back to `slice.json`. JSON-only slices auto-migrate on read (YAML twin written, JSON kept). Malformed YAML falls back to JSON with a warning — never fatal |
| `33a30035` — Phase 2 | Write-YAML-only: `writeBoth`, `SliceGenerator`, Studio saves and `slice:sync` write **only** `slice.yaml`. New `php artisan slice:migrate-json-to-yaml [--dry-run] [--delete]` — the framework never deletes JSON on its own |
| `bdc79423` — Phase 3/4 | Phase 3: loading from a JSON-only slice logs a deprecation warning once per slice; `slice:repair` lists JSON-only slices as "pending migration" with the exact migrate command. Phase 4: the JSON read fallback is **removed** — a JSON-only slice now throws a clear error pointing at the migrate command (the migrate command itself still reads JSON to convert). Zero JSON runtime load paths remain in `src/` outside the conversion command |

**Bugs caught in verification before merge (all fixed):**
- Grok's Phase 3 branch shipped a **corrupted `LaraSliceServiceProvider.php`** (literal `\n` + double backslashes at the `SliceDiffCommand` registration) — the branch did not parse as pushed. Un-corrupted and verified (`4739d565`)
- The framework's own core **Settings slice was JSON-only**, so Phase 4 rejected it: `settings.ai` routes never registered and every page with the AI Copilot bubble 500'd. Fixed by generating Settings' `slice.yaml` with the framework's own converter (`2d2d0477`)
- The parallel-branch merge conflicted on `SliceModifier` — resolved in master's favor because Phase 3's side still carried the old broken `findSlice` code

## 2. No silent overwrites — YAML diff preview + version history (`0f8f490c`)

Studio Save no longer writes immediately:

1. **Preview** — `POST /preview-studio-config` builds the proposed manifest and returns a unified YAML diff. It does not write
2. **Modal** — "Review YAML changes before save" shows the diff; Cancel does nothing
3. **Confirm** — "Save & write history" writes YAML and appends `version_history`
4. **No changes** — empty diffs report "No changes to save."

Honest note from verification: the modal diff is large for ported slices because Studio's form state rebuilds defaults — which is exactly why the preview matters: nothing is written silently anymore.

**Bugs caught in verification before merge (all fixed):**
- `previewStudioConfig()` called `$this->findSlice($sliceName)` — a method that doesn't exist in `SliceModifier` — so the preview endpoint threw on every call and returned 422 (the modal could never show a diff). Fixed via the class's existing `resolveSliceDir()` pattern
- Line 781 used the wrong namespace (`\LaraSlice\Support\SliceName`); the class is `LaraSlice\Generator\SliceName`
- Empty config reported `changed: true` — the only diff was the auto version-bump (1.0.0 → 1.0.1), so "No changes to save" could never trigger. Preview now ignores version-only bumps (`2710e660`)

## 3. Journey breadcrumb + visual alignment (same merge)

- Persistent journey indicator across **Porting → Blueprint → Slice Studios** (`Port → Design → Customize`), current step highlighted, linking to each Studio — one shared component, same placement in all three
- Unified visual language: one primary action + overflow menu, consistent button hierarchy and empty states
- This breadcrumb is the mode switcher waiting to happen — it becomes clickable modes in the ONE-Studio work (see §5)

## 4. `slice:diff` — CLI parity for the preview (`bdc79423`)

`php artisan slice:diff {slice} [--against=]` prints the same unified YAML diff the Studio preview shows and writes nothing. Reuses `ManifestRepository::unifiedDiff()` — no duplicated diff logic.

## 5. Slice Studio progressive disclosure (`02759045`)

The wizard guides instead of dumping everything at once:
- Summary hidden until step ≥ 3
- Steps 2–5 locked until the project name is set
- Mobile progress bar

## 6. ONE Studio — SHIPPED (`6da60722` standard; `b5f5d609` P1; `ad64508d` P2; `80bf0b33` P3; `3edffac7` P4)

The standard in `docs/DEV_STUDIO_STANDARD.md` §0 is now the product: **one developer surface, four modes** at `/laraslice/studio?mode=…`:

`Port` (was Porting) · `Design` (was Blueprint) · `Create` (was Scaffold Wizard) · `Manage` (was Slice Studio), with Schema as a Design sub-view.

Contract as built: modes are **destinations, not steps** (no numbering); panels lazy-mount on first activation (the initial payload never carries all ~14.5k Blade lines at once); POST endpoints unchanged — consolidation is GET pages only; legacy routes redirect into the shell with the mode preset; handoffs flow Port → Design in-shell without reloads; ⌘0–⌘4 shortcuts. **Studio Home** is the front door — "What are you starting from?" — with a BRD/AI brief card that carries the brief into Design as a visible kickoff banner (honest scope: AI auto-drafting YAML from a brief is not built yet). Phase 1 (shell + Port) was built by Grok; Phases 2–4 were built in-house after Grok hit plan limits. Full-flow gate before merge: **12/12 surfaces** in the browser (auth, Home, all 4 modes, Schema, Kitchen Sink, HR Employees, Tasks, ERP Invoices + create form). All 10 shipped example blueprints pass `slice:validate` — project-portfolio and service-desk were fixed to the v0.1 vocabulary (`17344686`).

## 7. Default UI program — listings, detail pages, forms (`d081c18f`, `9f60b159`, `070abb80`, `4ce24fbf`)

Driven by a side-by-side audit against the original application (a production ERP): the ported data was faithful, but the default presentation was not. Every gap traced to a default and fixed at that level:

- **L1 — Text hygiene.** Long text in listings clamps to two lines with a tooltip (deterministic CSS class, not utilities) instead of stretching rows into tall narrow columns.
- **L2 — Renderer defaults.** Directory columns are enriched from the manifest at request time: declared option labels replace raw values (`single` → "Full Day"), display labels drop the `_id` suffix ("Leave Type"), date/datetime fields render `05 Jan 2024` (the `date_time` formatter previously just echoed the raw string), and tables with >8 columns default to 7 sensible ones plus a persisted Columns menu (Invoices: 13 → 7).
- **L3 — FK labels + porter vocabularies.** The FK label resolver (and `modelDisplayName`) learned the `*_name` convention — `leave_types.type_name` now resolves, so Leaves shows "Sick" instead of `2`. The porter had been dropping MySQL `enum(...)` vocabularies at introspection; they are now extracted into manifest options (+ select inputs). Porter sidebar curation (merged earlier, `4d74ce31`): supporting slices (Details/Docs/Files/Schedules/Items/Histories/Attachments/Templates) no longer flood the sidebar by default — the source ERP's HR menu went from 12 ported items to the same curated 7 the original shows.
- **L4 — Detail + forms.** `DirectoryUi::detailRows()` builds detail pages from the manifest through the same pipeline as listings (resolved labels, option labels, human dates, Yes/No booleans, status pill, no tenant keys — the raw attribute dump remains only as a no-manifest fallback). Form relationship selects learned `*_name` too (they were silently falling back to id⇒id options), and **any** field with declared options now renders as a real select — previously only the literal `select` type kept its options, so ported enums rendered as free text.

Verified in the demo: the ported Leaves module (list, detail, create form) is at presentation parity with the original; regression sweep over Tasks, Kitchen Sink, Employees, Projects, Invoices renders clean. Full plan: `DEFAULT-UI-PLAN.md` (goal workspace). Existing installs pick up the component changes on BlatUI republish (`slice:repair --publish-ui`).

---

## Release-gate verification (demo passes, 20 Oct 2026)

Release surfaces screenshotted from the running demo:

1. **Porting Studio** — journey breadcrumb, Port active
2. **Blueprint Studio** — breadcrumb, Design active, "Start from…" picker
3. **Slice Studio** — breadcrumb, Customize active, progressive disclosure wizard
4. **YAML diff modal** — real flow: loaded Tasks, changed config, hit Save → "Review YAML changes before save" with the full unified diff and "Save & write history"
5. **Tasks (ERP)** — 50 records rendering under YAML-only Phase 4
6. **Kitchen Sink** — all 10 formatters live (pills, progress, money, overdue)
7. **ONE Studio** — Home + all modes + mobile (12/12 full-flow gate)
8. **Default UI** — Leaves list/detail/form before-and-after vs the live source ERP

Tests: `ManifestRepositoryTest` (YAML-only, JSON-only migrate, both-present YAML-wins, malformed fallback), `tests/Unit/Generator/StudioPreviewTest.php` (empty config → `changed: false`; title change → `changed: true` with real diff). Full existing suite green on master at the YAML merges; the Default UI changes were verified in the running demo (browser gates above) rather than a suite re-run.

## Still open (not in this release)

- Studio Polish program P1–P4 (Manage rail rework first) — agreed, queued
- Studio access decision: super-dev-only vs super-admin (user's call, still open)
- FlutterSlice PoC; Phase B schema publishing; BRD → YAML AI auto-drafting
- Holiday slice has no ported counterpart in the ERP port (porter coverage gap, noted)

---

**Release boundary status:** demo passes ✅ · this changes file ✅ (updated draft) · user approval ⏳ · tag + GitHub release ⏳ · Packagist auto-pickup (same as v1.7.0)
