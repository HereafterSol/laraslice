# Dev Studio UX audit — 2026-10-28

Scope: ONE Dev Studio shell (`/laraslice/studio`), Port mode (`partials/port-panel.blade.php`), Design/Blueprint (`partials/design-panel.blade.php`), legacy wizard modal (`partials/porting-modal.blade.php`), routes in `LaraSliceServiceProvider.php`, SKILL §8 (Blueprint Studio) and §16 (AI porting protocol).

Evidence: Blade/Alpine source review (no live browser in this pass). Flows traced from `studio-shell.blade.php` lazy panel fetch → `BlueprintStudioController::panel`.

---

## Executive verdict: is Dev Studio “good”?

**For LaraSlice-aware developers:** **Mostly yes** — the ONE-shell direction (SKILL §8) is the right architecture: Studio Home, lazy-mounted modes, keyboard shortcuts (⌘0–⌘6), Port workflow guide, honest “example vs live” labels in the legacy modal, inline errors instead of `alert()`, and post-analyze journey-first tab switch in `analyze()`.

**For non-technical product owners / admins:** **Not yet** — the same surface exposes VSA, ETL, YAML/JSON plans, controller actions, and 10 Port tabs without a glossary or role-based simplification. Home promises “Draft blueprint in Design” while the kickoff banner still says AI drafting is “the next step — for now.”

**Overall:** **B+ framework, C+ first-run UX.** Strong depth and protocol alignment; terminology and navigation debt block confident self-service for non-devs.

---

## Persona matrix

| Persona | Primary tasks | Where in UI | Friction | Severity | Quick win | Roadmap |
| --- | --- | --- | --- | --- | --- | --- |
| **Super-admin / owner** | Decide whether to port vs greenfield; sign off on nav/journeys | Studio Home → Port → Journeys / Sidebar | Jargon (domain namespace, VSA, ETL); unclear Analyze vs Execute vs Design Apply | **High** | “What happens next?” after Analyze; plain Analyze/Execute copy | Owner “guided” Port layout (3 tabs only) + glossary tooltips |
| **Developer / port engineer** | Analyze source, review §16.5 nav, execute scaffold, drift | Port tabs, Design Apply, Manage | Duplicate Port UX (full panel + wizard modal); `design-panel` 6k+ lines; Plan/Operate hidden from Home | **Med** | Wire `setMode()` from Port; unify “Design” naming | Consolidate modal into Port panel; Studio drift panel (BACKLOG #5) |
| **Developer / slice author** | Edit YAML, visual model, apply blueprint | Design (Blueprint/Schema sub-views) | Header action overload; “Porting Studio” link still separate tone | **Med** | Redirect links already OK | Progressive disclosure for Apply/Migrate |
| **Developer / operator** | Backups, audit, access | Operate mode (⌘6) | Not linked from Home cards | **Med** | Doc + Home card | Operate entry on Home when `studio.operate` |
| **Non-technical BA** | Validate user journeys vs legacy | Port → Journeys, Mermaid | BRD language mixed with Blade/controller traces | **Med** | Persona filters already present | Journey-only export PDF / share link |
| **AI agent (MCP)** | Same as developer via CLI/Studio | N/A in UI | UI labels don’t match CLI (`slice:port`, `blueprint:apply`) | **Low** | Cross-link CLI in tooltips | Studio command palette |

---

## Flow notes (code-derived)

### Entry and routing

| URL | Behavior |
| --- | --- |
| `/laraslice/studio` | `studio-shell.blade.php` — canonical |
| `/laraslice/studio?mode=port` | Lazy-fetch `laraslice.studio.panel` → `port-panel` |
| `/laraslice/wizard`, `/wizard/blueprint`, `/wizard/porting` | Redirect into shell (SKILL §8) |
| Writes | Gated by `AuthorizeStudio` + `GuardStudioWrites` per route group |

### Studio Home

- Three primary paths + BRD textarea → Design with `sessionStorage` kickoff.
- Modes **Plan** (⌘5) and **Operate** (⌘6) exist in the header but not on Home — discoverability gap.

### Port mode (canonical panel)

1. Source card: archetype, domain, local/Git.
2. **Analyze** → POST `port-plan` → lands on **User Journeys** tab.
3. Review tabs (guide, sidebar, VSA, pages, data, ETL, YAML, JSON, log).
4. **Open in Design** → `sessionStorage` plan + `__oneStudioSetMode('design')`.
5. **Execute** → POST `port-execute` → log tab.

Gaps found in audit (pre-fix):

- Intro said “Analyze → **Apply**” (Apply is Design/blueprint language, not Port Execute).
- `setMode('manage')` in intro **called undefined** Alpine method when panel mounted outside shell helper (fixed: `setMode()` implementation).

### Design mode

- Full blueprint studio in `design-panel.blade.php`: Visual / Preview / Split / YAML, Apply with plan hash, port plan load from storage.
- Still shows “🚀 Porting Studio” button to legacy path (redirects to Port) — OK but naming inconsistent with ONE shell.

### Legacy wizard modal

- Rich §16 workflow block when `portPlanData` present; still embedded in `wizard-panel` for Manage flow — **two Port implementations** to maintain.

---

## Severity summary

| ID | Issue | Severity |
| --- | --- | --- |
| U1 | Terminology: Apply / Execute / Blueprint Studio / Manage / Slice Studio used interchangeably | High |
| U2 | Non-dev jargon without glossary | High |
| U3 | Dual Port UI (panel + modal) | Med |
| U4 | Design header density + hidden destructive actions on small screens | Med |
| U5 | Incomplete Home AI/BRD promise | Med |
| U6 | Plan/Operate modes not on Home | Low |
| U7 | `design-panel` link label “Porting Studio” vs mode “Port” | Low |

---

## Changes made in this pass (2026-10-28)

1. **`port-panel.blade.php`:** Analyze/Execute copy; pre-analyze empty hint; **What happens next?** block after Analyze; execution log empty state; action bar labels (**Open in Design** / **Execute Porting**); **`setMode()`** helper; success log nudge to Manage/Design.
2. **`studio-shell.blade.php`:** Port home card copy clarifies analyze-first, execute-when-ready.

---

## Recommendations (not implemented here)

1. **Single Port surface** — retire or thin `porting-modal.blade.php`; deep-link Port mode only.
2. **Terminology pass** — public strings: Port / Design / Create / Manage / Operate / Plan (match shell mode IDs).
3. **Owner view** — `?persona=owner` hiding YAML/JSON/ETL tabs.
4. **Post-Execute checklist** — link to `slice:drift`, `REPLICA-STATUS.md` template (SKILL §22).
5. **Kickoff → AI** — either wire brief to blueprint plan endpoint or soften Home marketing until shipped.

---

## Shipped (5 Nov 2026 — [Architect foundation + Studio sprint](90c91c6a-cb46-4b8d-b4f6-eac9817984ed))

| Audit item | Status |
| --- | --- |
| Version badge hard-coded 1.0 / 2.0.0 | **Fixed** — `FrameworkPackage::version()` in studio shell |
| Slice count vs discovery mismatch | **Improved** — “host slices” from `laraslice.slices_path` |
| Copilot “live MySQL” on SQLite demo | **Fixed** — driver-aware schema label in bubble + AI settings |
| Port mode overclaims 100% | **Fixed** — `port-panel` points to `porting/REPLICA-STATUS.md` gate truth |

**Rollout:** Changes live in **LaraSlice package source** (`E:\Apps\message\LaraSlice`). Host apps (demo, `erp-port-v*`) need **`composer update hereafter/laraslice`** or path repo + `slice:cache` to see UI in `/laraslice/studio`.

**Still open:** owner glossary (Muse #3), modal consolidation, drift panel (BACKLOG). **Standard:** [ENTERPRISE-UX-STANDARD.md](ENTERPRISE-UX-STANDARD.md) on `v2.0.2-candidate`.

---

## Browser verification (5 Nov 2026 — flagship :7002)

**Host:** `E:\laragon\www\flagship-erp-v202-demo` · `php artisan serve --host=127.0.0.1 --port=7002` · path repo `dev-v2.0.2-candidate` → `E:\Apps\message\LaraSlice` · login `admin@laraslice.com` / `password`.

| Check | Result | Notes |
| --- | --- | --- |
| Studio subhead **host slices** | **PASS** | Header showed **116 host slices** (discovery-backed label) |
| `FrameworkPackage::version()` | **PASS (CLI)** | Tinker: `dev-v2.0.2-candidate` |
| `FrameworkPackage::schemaTelemetryLabel()` | **PASS (CLI)** | Tinker: `SQLite schemas` on bundled SQLite |
| Port panel **REPLICA-STATUS** honest copy | **PASS (package)** | `port-panel` intro cites `porting/REPLICA-STATUS.md` (lazy panel; code review) |
| `?persona=owner&mode=port` tab filter | **PASS (code)** | `visibleTabs` owner set: guide, journeys, sidebar, log |
| Copilot welcome + `/count` slash desc | **PARTIAL on :7002** | Package stub uses driver-aware label; **published** `resources/views/components/ui/ai-copilot-bubble.blade.php` on demo still says “live MySQL schemas” until republish from stub |
| Sidebar version chip | **PARTIAL on :7002** | Published `layouts/app.blade.php` uses `InstalledVersions` → **v2.0.0**; Studio shell badge uses `FrameworkPackage::version()` when visible (`sm+`) |
| Studio Home BRD promise | **Fixed (package)** | Softened kickoff copy in `studio-shell.blade.php` |

**Screenshot notes:** Dev Studio header + Port mode selected + Copilot bubble open on `/laraslice/studio?mode=port&persona=owner` — slice count visible; republish host Blade to close Copilot/sidebar version gaps on demo zip.

**Rollout reminder:** `composer update hereafter/laraslice` (or path repo) → `php artisan slice:cache` → republish or diff-merge `resources/views/components/ui/ai-copilot-bubble.blade.php` + align `layouts/app.blade.php` version line with `FrameworkPackage::version()`.

---

## References

- SKILL §8: ONE Studio at `/laraslice/studio`, legacy redirects.
- SKILL §16 / §16.5: Analyze → review journeys/nav → Design optional → Execute; post-analyze mandate in `docs/PORTING_FOUNDATION_RULES.md`.
- BACKLOG: Studio drift panel, access model decision.
