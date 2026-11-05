# LaraSlice architecture foundation audit

**Date:** 5 Nov 2026  
**Scope:** Package repo `hereafter/laraslice` on branch `v2.0.2-candidate`  
**Auditor role:** Senior solution architect (audit + plan only — no large code refactors in this pass)  
**Programme context:** ERP zero port ~**50%** on erp-port-v202; porting paused until foundation blockers below are owned.

---

## Executive summary

| Field | Assessment |
| --- | --- |
| **Overall maturity stage** | **3 — Defined** (strong declared architecture and gate discipline; operational/agent truth and evaluator DX still **Repeatable**, not yet **Managed**) |
| **Architecture vs port** | Correctly separated in docs; port stress test validated the foundation gaps — not a reason to skip gates |
| **Recommendation** | **Pause net-new port surface work** until P0 foundation blockers (routing truth, demo install, agent index, generator portability leaks) ship in the **framework repo** |
| **Next maturity target** | **Stage 4 — Managed** after measurable PASS on P0 register + `slice:route-truth` (or equivalent) + flagship one-command demo on Windows |

**Stage scale (1–5):**

| Stage | Name | LaraSlice signal |
| --- | --- | --- |
| 1 | Initial | Ad hoc ports, folklore URLs |
| 2 | Repeatable | Gates + playbook exist; hosts still diverge |
| 3 | **Defined** | Mind·Body·Heart, VSA, Studio six modes, Replica Protocol documented and partially automated |
| 4 | Managed | Agent-safe routing index, demo lock, CI portability lint, Studio metrics match discovery |
| 5 | Optimizing | Second runtime spike with inherited contract; family table promotion |

---

## Inventory (docs + code)

### Mind · Body · Heart · Soul

| Asset | Location (framework) | Runtime? | Maturity | Notes |
| --- | --- | --- | --- | --- |
| **Mind** | Host `porting/system.yaml` | No | **Strong** | `docs/MIND_BODY_HEART.md`, SDLC Plan mode, `slice:plan:draft` |
| **Body** | Host `porting/ui-spec/*.ui.yaml` | No | **Strong** | Anatomy pass `slice:port --anatomy`; conformance via `slice:conformance` |
| **Heart** | Per-slice `slice.yaml` | **Yes** | **Strong** | 5 core slices in package `src/Slices/*/slice.yaml`; validator + drift |
| **Soul** | Mind `design:` + BlatUI mapping | Mapped at runtime | **Medium** | JEV honesty split documented in skill; Kit vs TypeSafe called out |

### VSA · DDD · UserSlice

| Topic | Evidence | Maturity | Gap |
| --- | --- | --- | --- |
| **VSA** | ADR-001, skill §1–3, boundary tests | **Strong** | Porter “one table = one slice” class fixed in docs; hosts need repair pass |
| **DDD (pragmatic)** | Aggregates, settings hub, hidden children — `VISION.md`, port rules | **Strong** | Phase 0 composition map still manual discipline |
| **UserSlice / personas** | `navigation.roles`, journeys v2, PORTING-DECISION § UserSlice | **Medium** | Demo smokes admin/employee; client portal deferred; Copilot still generic “Vertical Slice” tone in places |

### slice.yaml lifecycle

| Phase | Mechanism | Status |
| --- | --- | --- |
| Author | Studio GitOps, `slice:make`, port scaffold | **Shipped** |
| Validate | `slice:validate`, YAML spec tests | **Shipped** |
| Discover | `SliceManager`, relative paths in cache | **Shipped** |
| Cache | `slice:cache` / `slice:clear` | **Shipped** — portable clone path documented in skill |
| Drift | `slice:drift` (Mind/Body vs grants) | **Shipped** — 0 errors = release gate |
| Repair | `slice:repair` (+ `--remap-nav`, `--re-infer-ui`, `--publish-ui`) | **Shipped** |
| Sync | `slice:sync`, `slice:regen-form` | **Shipped** |
| Dual emit legacy | JSON + YAML (`ManifestRepository::writeBoth`) | **Refactor target** — YAML-only still on FOUNDATION_ROADMAP Phase B |

### slice:port · repair · cache · generators

| Command / path | Purpose | Strength | Gap |
| --- | --- | --- | --- |
| `slice:port` | Analyze, permissions, anatomy, ETL, scaffold | **Deep** — largest DX surface | Post-analyze §16.5.1 still human/agent discipline |
| `slice:repair` | Upgrade stale generated trees | **Proven** in tests | Hosts must run after package bumps |
| `slice:cache` / `slice:clear` | Discovery cache | **Lossless tested** | Slice count UI ≠ discovery count on demo |
| Generators | `SlicePortService`, modifiers, hub/matrix | **ERP-scale proven** | `.php` leakage in YAML (23 findings on demo) — generator defect |
| `rebuild-discovery-cache.php` | Referenced in skill | **Not in package tree** | Doc points to host `porting/` script — **Defer** unify or document host-only |

### Studio · Blueprint · Wizard

| Surface | Route / mode | Status |
| --- | --- | --- |
| **ONE Studio shell** | `/laraslice/studio?mode=*` | **Shipped** — lazy panels (port, design, create, manage, plan, operate) |
| **Blueprint** | Design sub-mode + legacy `/laraslice/wizard/blueprint` | **Shipped** — terminology drift (Apply/Execute) per STUDIO-UX-AUDIT |
| **Port panel** | `partials/port-panel.blade.php` | **Shipped** — duplicate modal still exists (U3) |
| **Wizard / Create** | `wizard-panel.blade.php` | **Shipped** — heavy Alpine payload |
| **Enterprise chrome** | `EnterpriseShell`, ENTERPRISE-UX-STANDARD | **Partial** — flagship still shows generic titles in register |

Contract: `docs/DEV_STUDIO_STANDARD.md` (six modes + CLI parity intent).

### Artisan command register (package)

Registered in `LaraSliceServiceProvider` (37 commands):  
`slice:install`, `slice:make`, `slice:wizard`, `slice:field`, `slice:ui:prune`, `slice:list`, `slice:seed`, `slice:toggle`, `slice:wipe`, `slice:destroy`, `slice:export-flutter`, `slice:ai`, `blueprint:validate`, `blueprint:plan`, `blueprint:apply`, `slice:sync`, `slice:diff`, `slice:links`, `slice:qualify-routes`, `slice:migrate-json-to-yaml`, `slice:port`, `slice:derive-surface`, `slice:cache`, `slice:repair`, `slice:regen-form`, `slice:plan:draft`, `slice:drift`, `slice:conformance`, `slice:validate`, `slice:clear`, `slice:publish`, `laraslice:audit:prune`, `laraslice:mcp`, `laraslice:skill:publish`, `workflow:transition`, `workflow:sla-check`, `workflow:export`.

**Note:** IMPROVEMENT-REGISTER cites `slice:portability` — **no such signature exists in package source** (likely meant `slice:conformance` / future lint). **Decision: Refactor** docs + add command or rename.

### MCP · skills

| Asset | Location | Status | Gap |
| --- | --- | --- | --- |
| **MCP server** | `laraslice:mcp` → `McpServer` + `AiEngine` tools | **Shipped** (stdio + HTTP guard on writes) | No route JSON tool; unsafe to deep-link from `route:list` alone |
| **Canonical skill** | `skills/laraslice/SKILL.md` (~2.8k lines) | **Strong** | Monolith token cost; family split advertised but **single file** in repo |
| **Workspace pointer** | `E:\Apps\message\.agents\skills\laraslice\SKILL.md` | Pointer to package skill | Keep sync via `laraslice:skill:publish` |
| **Satellite skills** | blatui, yaml-verifier, typesafe-ai in `.agents/skills` | **Adjacent** | Not version-locked to laraslice tag |

Read order (shipped): `FOUNDATION-EXECUTION-TRACKER` → programme status → ENTERPRISE-UX → PORT-100 → host `REPLICA-STATUS.md` (`AI-FOUNDATION-OPTIMIZATION.md`).

### PORTING programme docs

| Doc | Role | Maturity |
| --- | --- | --- |
| `PORT-100-PLAYBOOK.md` | Ordered programme | **Done** |
| `PORTING-DECISION-100-REPLICA.md` | Architect clone vs Kit | **Done** |
| `PORTING_FOUNDATION_RULES.md` | Porter + post-analyze mandate | **Done** |
| `REPLICA-PROTOCOL.md` + status template | Gate truth | **Done** |
| `ARCHITECTURE-VS-PORT-TRUTH.md` | Anti-confusion | **Done** |

### BlatUI · EnterpriseShell

| Item | Status |
| --- | --- |
| Pure Blade + Alpine + Tailwind v4 + BlatUI components | **Convention locked** in skill |
| `EnterpriseShell::documentTitle()` | **Shipped** in package |
| Host adoption | **Partial** — demo/register still report “Vertical Slice Architecture” titles |
| Filter bar / fused chrome | Documented in UI standards; generator parity ongoing |

### RUNTIMES · multi-language truth

| Claim | Truth (Nov 2026) |
| --- | --- |
| LaraSlice (PHP/Laravel) | **Shipping reference** |
| NodeSlice, JavaSlice, … | **Roadmap only** — `RUNTIMES.md` explicit |
| Flutter | **`slice:export-flutter`** generator exists; not full FlutterSlice runtime |
| Cross-runtime move | Same Replica gates — **documented**, not exercised |

See **`docs/MULTI-RUNTIME-PORTABILITY-CONTRACT.md`** for machine-checkable vocabulary rules extracted from RUNTIMES + MIND_BODY_HEART.

### Tests · quality

| Metric | Value |
| --- | --- |
| PHPUnit files | **117** test classes under `tests/` |
| Command smoke | `CommandSmokeTest` covers read-only artisan paths |
| Architecture | Slice boundary + porting + generator coverage substantial |

Release claim “700 tests” applies to **host/demo** trees — not re-verified in this audit pass.

---

## Strengths (keep)

1. **Trilogy + Heart wins** — clear conflict resolution and port vs architecture split.
2. **Replica Protocol + lowest gate** — honest ~50% headline is sustainable.
3. **ONE Studio consolidation** — Dev Studio Standard aligned with implementation.
4. **Porter depth** — ERP-scale analyze/scaffold/ETL with repair and qualify-routes.
5. **Drift + conformance tooling** — evidence-based release discipline.
6. **Enterprise UX direction** — EnterpriseShell + ENTERPRISE-UX-STANDARD (needs host rollout).
7. **MCP + skill** — single agent contract for Laravel host (needs route-truth layer).
8. **Documentation corpus** — playbook, port decision, AI token budget, shared references.

---

## Gaps (foundation blockers)

| ID | Gap | Severity | Blocks |
| --- | --- | --- | --- |
| F1 | Live URLs `/erp/erp/...` vs `route:list` / skill paths | **P0** | Agents, Gate V, bookmarks |
| F2 | Flagship zip: no lock/vendor; Windows extract/install friction | **P0** | Evaluators, release credibility |
| F3 | No `slice:route-truth` JSON index for MCP | **P0** | AI automation at scale |
| F4 | Studio honesty: version badge, slice counts, SQLite Copilot copy | **P1** | Trust, demos |
| F5 | YAML `.php` template leakage in ported Hearts | **P1** | Multi-runtime vocabulary |
| F6 | `slice:portability` doc/command mismatch | **P1** | CI story |
| F7 | Duplicate Port modal + terminology (STUDIO-UX-AUDIT) | **P2** | DX noise |
| F8 | Skill monolith vs advertised family split | **P2** | Token budget |
| F9 | JSON+YAML dual emit | **P2** | Lifecycle simplification |
| F10 | Second runtime | **Defer** | Until Stage 4 on Laravel reference |

---

## Decision register

| ID | Topic | Decision | Rationale |
| --- | --- | --- | --- |
| D1 | Mind·Body·Heart model | **Keep** | Core differentiator; RUNTIMES inherits it |
| D2 | Replica five gates + lowest wins | **Keep** | Only honest % for ports |
| D3 | ONE Studio six modes | **Keep** | DEV_STUDIO_STANDARD v2 decision |
| D4 | Page clone (Gate V tactic) | **Keep** scoped | PORTING-DECISION tree — not default Heart strategy |
| D5 | `/erp/erp/` live prefix | **Keep** as host truth | **Refactor** generators/docs/skills to match, not reverse URLs |
| D6 | `slice:qualify-routes` | **Keep** + mandate on ports | Shipped; rollout incomplete on v202 |
| D7 | `route:list` for agents | **Delete** as primary URL source | Replace with DOM hrefs / route-truth JSON |
| D8 | Dual JSON+YAML manifests | **Defer** removal | Phase B YAML-only still queued |
| D9 | Legacy porting modal | **Delete** (UI) | Consolidate to Port panel (X3) |
| D10 | Monolithic SKILL.md | **Refactor** | Split core/start/port/operate with router pointer |
| D11 | `slice:portability` name | **Refactor** | Implement lint command or align docs to `slice:conformance` |
| D12 | erp-port-v202 Gate S column work | **Defer** porting | Until F1–F3 framework P0s assigned |
| D13 | NodeSlice / siblings | **Defer** | After MULTI-RUNTIME contract CI on Hearts |
| D14 | Marketing 100% language | **Delete** from public claims | Owner sign-off on NOT COVERED (B2) |

---

## Top 10 decisions (actionable)

1. **Keep** trilogy + Replica Protocol as non-negotiable foundation.  
2. **Refactor** routing truth end-to-end (qualify-routes rollout + `slice:route-truth` JSON).  
3. **Keep** live `/erp/erp/...` URIs; fix names and agent docs — do not re-prefix routes to “adjudication”.  
4. **Refactor** flagship demo to Packagist-only + `composer.lock` + `demo.ps1`.  
5. **Refactor** porter/generator to eliminate PHP class strings in portable YAML (F5).  
6. **Refactor** Studio metrics to single discovery source (`FrameworkPackage` + health).  
7. **Delete** reliance on `route:list` alone in skills/MCP (document forbidden pattern).  
8. **Defer** net-new ERP slice scaffolding on v202 until tracker “Foundation P0” row is DONE.  
9. **Refactor** skill into family files with unchanged canonical entry in package.  
10. **Defer** second runtime spike until Stage 4 gate on reference implementation.

---

## Top 5 workstreams before porting resumes

| # | Workstream | Owner | Exit criteria |
| --- | --- | --- | --- |
| W1 | **Routing & agent truth** | Framework | v202 runs qualify-routes; route-truth JSON shipped; ROUTING-TRUTH doc PASS row |
| W2 | **Evaluator install** | Framework + demo | Zip + Windows path documented; lock or no-VCS default; IMPROVEMENT-REGISTER P0 #2–3 closed |
| W3 | **Portable Heart lint** | Framework | Command + CI job; 0 template-leak findings on flagship snapshot |
| W4 | **Studio honesty pass** | Framework | Version from composer; slice count = discovery; driver-aware Copilot |
| W5 | **Port gate hygiene** | Port (after W1) | REPLICA-STATUS routing row PASS; Gate S deviations signed or closure migration merged |

---

## .ai/rules

No `.ai/rules` directory exists in the LaraSlice package repo at audit time. Host apps (e.g. fresh-laraslice) carry Laravel Boost rules separately. **Recommendation:** add package-scoped `.ai/rules` in a follow-up sprint mapping `docs/` and `src/Slices` globs — **Defer** to post-P0.

---

## References

- [FOUNDATION-EXECUTION-TRACKER.md](FOUNDATION-EXECUTION-TRACKER.md)  
- [FOUNDATION-MATURITY-ROADMAP.md](FOUNDATION-MATURITY-ROADMAP.md)  
- [IMPROVEMENT-REGISTER-2.0.2.md](IMPROVEMENT-REGISTER-2.0.2.md)  
- [ROUTING-TRUTH-ALIGNMENT.md](ROUTING-TRUTH-ALIGNMENT.md)  
- [MULTI-RUNTIME-PORTABILITY-CONTRACT.md](MULTI-RUNTIME-PORTABILITY-CONTRACT.md)

---

*Phase 0 foundation audit complete. Porting programme remains valid but **paused** for net-new surface until W1–W4 exit criteria are tracked in FOUNDATION-EXECUTION-TRACKER.*
