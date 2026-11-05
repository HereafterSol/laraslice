# AI foundation optimization — skills, docs, MCP

**Status:** R&D note (5 Nov 2026)  
**Question:** Are LaraSlice skills, docs, and MCP **token-efficient** for daily agent work?  
**Answer:** **Directionally strong, not yet optimal.** The skill family split (v2.0.2) helped; monolithic context paths and duplicate truth files still burn budget.

**Related:** [AI-FOUNDATION-OPTIMIZATION read order in Skill § AI token budget](../skills/laraslice/SKILL.md) · [SHARED-REFERENCES.md](SHARED-REFERENCES.md) · Skill §11 MCP · Skill §16.5.1 · [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md)

---

## Assessment (honest)

| Asset | Token posture | Verdict |
| --- | --- | --- |
| **`laraslice` core skill (~740 lines post-split)** | Single file still large for every turn | **Load on demand** — router skill / START-HERE |
| **`laraslice-port` / `-start` / `-operate`** | Good skills.sh shape | **Activate by task**, not all at once |
| **`docs/` corpus** | Many overlapping status docs | **Ordered read index** (below) reduces re-read |
| **MCP (`slice:*`, Studio)** | Powerful; tool list undiscovered without index | **Route index** in Skill §11 + compressed checklists |
| **§16.5.1 placement** | Lives inside §16 (porting) | **Correct** — agents in Port mode find it; add **top-of-port-skill pointer** |
| **Plan HTML vs React/Blade drift** | Agents paste UI from plans that never ship | **Anti-pattern** — gates measure renders, not plans |

---

## Recommended read order (AI token budget)

**Default session boot (≤3 files):**

1. Host or programme **`docs/PROGRAMME-STATUS-YYYY-MM-DD.md`** (or `porting/REPLICA-STATUS.md` if inside a port app only).  
2. **`docs/PORT-100-PLAYBOOK.md`** — phase you are in (Analyze, Phase 0, gates, Studio handoff).  
3. **Port app `porting/REPLICA-STATUS.md`** — lowest gate; never skip for parity work.

**Add only when task requires:**

| Task | Next file |
| --- | --- |
| Architect port vs clone | [PORTING-DECISION-100-REPLICA.md](PORTING-DECISION-100-REPLICA.md) |
| Post-analyze nav | `PORTING_FOUNDATION_RULES.md` + Skill §16.5.1 |
| UI parity | Skill §19–§21, `KIT-CONTRACT.md` |
| Framework P0 | `IMPROVEMENT-REGISTER-2.0.2.md`, `BACKLOG.md` |
| Vision / multi-runtime | `VISION.md`, `RUNTIMES.md` (horizon — not current gate) |

**Do not** load full competitor analysis, changelog HTML, or compilation packs unless auditing strategy.

---

## Skill layering (target architecture)

```text
Layer 0 — Router (≤80 lines): name, when to activate, links only
Layer 1 — Task skills: laraslice-start | laraslice-port | laraslice-operate
Layer 2 — Core laraslice: architecture + CLI + gates (reference sections)
Layer 3 — references/*.md: deep dives (JEV truth, ETL, MCP tool tables)
Layer 4 — examples/: REPLICA-STATUS snippet, port plan JSON shape
```

**Rules:**

- One concept → **one home** (family split already maps this; keep it when adding §s).  
- **Compressed checklists** at section tops; prose below for humans.  
- **§16.5.1** stays under §16; port skill Layer 1 must link it in first screen.  
- **Architect port mode** tree lives in `PORTING-DECISION-100-REPLICA.md`; Skill summarizes + links (no duplicate 400-line tree in skill).

---

## MCP route index (compress tool discovery)

Maintain in Skill §11 (and host `AI-CONTEXT-INDEX.md` when present):

| Intent | CLI / MCP first | Studio second |
| --- | --- | --- |
| Analyze legacy | `slice:port --anatomy` / plan | Studio Port → Analyze |
| Drift truth | `slice:drift` | Operate (future panel) |
| Apply blueprint | `slice:blueprint:apply` | Design → Apply |
| Gate evidence | `porting/GATE-*`, PHPUnit | Do not self-report in chat |
| Discovery repair | `slice:clear` → `slice:cache` | After clone/copy port tree |

Agents should **call tools** instead of re-embedding command help from memory.

---

## Compressed checklists (paste into agent context)

### Port session (minimum)

- [ ] Read `REPLICA-STATUS.md` headline gate  
- [ ] Run/post Analyze; execute **§16.5.1** before parity language  
- [ ] `slice:drift` → 0 errors before PASS claims  
- [ ] Update evidence files, not chat status  

### Greenfield session (minimum)

- [ ] Mind sign-off (Plan mode)  
- [ ] Blueprint validate → apply  
- [ ] §18 real-usage smoke for flagship surfaces  

---

## Anti-patterns

| Anti-pattern | Why it hurts | Remedy |
| --- | --- | --- |
| **Plan HTML / wireframe drift** | Agent “completes” UI that never renders in Blade/kit | Gate V + `slice:derive-surface`; no PASS from mock HTML |
| **Loading full SKILL every turn** | 3k+ tokens before work | Task skill + read order |
| **Chat replica %** | False confidence | REPLICA-STATUS only |
| **Re-opening SETTLED decisions** | Compilation pack §5 wastes audit time | Test against settled rules |
| **MCP bypass for port writes** | Skips GuardStudioWrites | Studio/CLI only with permissions |
| **Overselling JEV / TypeSafe** | Doc–runtime gap | Skill §17 claim language; Body→kit only |

---

## Portable architecture (philosophical anchor)

The **PHP2Shell / patch-race** narrative (runtime vulnerabilities below application code) supports LaraSlice’s **inference**, not a proven product claim: **definitions in owner files + mechanical gates** improve **response speed** and **migration optionality** when runtimes must move. It does **not** replace patching PHP or shipping LTS backports.

Structural parallel:

- **Risk below the app** → welding to one stack increases exposure.  
- **Inventory + tested upgrade paths** → Mind, porter, drift, REPLICA-STATUS.  
- **AI compresses attack and defense** → protocol + gates are the defense; skills must stay **small and ordered** so agents spend tokens on **evidence**, not lore.

See [RUNTIMES.md](RUNTIMES.md) for runtime family sequencing (second runtime **after** crucible gates — not parallel hype).

---

## Next R&D (framework backlog)

1. **`laraslice-router` skill** — 60-line activation map (port vs greenfield vs operate).  
2. **`references/mcp-routes.md`** — one table, generated from `php artisan list slice`.  
3. **Doc dedupe** — merge `FOUNDATION_ROADMAP` status into `FOUNDATION-MATURITY-ROADMAP` with single date.  
4. **Studio → CLI tooltips** — deep links to slash commands (STUDIO-UX audit U8).  
5. **Token budget lint** — CI warning if core skill exceeds N lines without reference split.
