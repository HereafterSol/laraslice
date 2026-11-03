# The SDLC on LaraSlice — for software houses, big teams, and AI

A software house does not sell code; it sells a lifecycle —
discovery, build, verification, release, years of operation —
repeated across clients without the process collapsing. LaraSlice
is shaped for that: every phase of the lifecycle has a surface in
the framework, and the handoffs between phases are files, not
folklore. The unit of work is the **slice**; the contract surfaces
are the **Mind** (`system.yaml`), the **Body** (`*.ui.yaml`), and
the **Heart** (`slice.yaml`); the working surface is the **Dev
Studio** and its six modes.

**Port or greenfield:** the ordered programme (Analyze → Phase 0 →
Phase D nav → gates → Studio handoff) lives in
[PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md).

## The lifecycle, phase by phase

### 1. Discovery & requirements — the Mind (Plan mode)

A brief or BRD becomes `system.yaml`: purpose, actors, role clubs
with per-person overrides, journeys, operations. It is written in
plain language on purpose — the client or product owner reads and
signs the Mind, and that sign-off is the requirements gate. From
zero, a team writes it in **Plan** mode; `slice:plan:draft` can
draft a Mind and per-slice Bodies from discovery material, marked
as drafts, for a human to correct and approve. Drafts are never
silently promoted — in a software house, that is the difference
between a requirements document and a rumor.

### 2. Architecture & design — the Body (Design mode)

Domains group slices into bounded contexts (suites), so a large
system decomposes the way the business does, and different teams
can own different domains. Each slice's Body declares its surface
— columns, filters, tabs, dialogs — reviewable without running
the app. Architecture rules are enforced, not documented:
boundary tests fail the build when one slice reaches into
another's internals; cross-slice communication goes through
contracts.

### 3. Build — Create mode, the CLI, and the AI door

A slice scaffolds complete: model, migration, business objects,
service, controllers, views, routes, permissions. Developers work
through **Create/Design**, the CLI, or an AI assistant holding the
framework skill (§24's two doors) — the declarations are the
same, so work is interchangeable between people, teams, and AI.
The Standard UI layer renders the basics correctly by default,
which is what keeps ten developers' output looking like one
product.

### 4. Verification — evidence gates, not impressions

- **Drift audit** (`slice:drift`): the Mind's club promises and
  the Bodies' access declarations are checked against the roles'
  actual grants; zero errors is a release gate, `--fix`
  reconciles.
- **Real-usage gates**: per-domain, two-role browser journeys
  against the running app, asserted at the value level (the
  method is codified in the skill, §18).
- **Composition probe**: the layout rules (fused filter bar,
  title in the topbar, one title per page) checked on live pages.
- For **ported** systems, the Replica Protocol's five gates
  ([REPLICA-PROTOCOL.md](REPLICA-PROTOCOL.md), playbook
  [PORT-100-PLAYBOOK.md](PORT-100-PLAYBOOK.md)) with the port's
  `REPLICA-STATUS.md` as the quotable record (lowest gate wins).

### 5. Release — the boundary

A release is a governed act, not a push: a working demo, a
changes file documenting what changed, the docs / README /
landing / changelog updated *before* merging, and the owner's
approval — then merge, tag, and publish. The framework's own
releases follow this boundary; client projects inherit it as
their definition of done.

### 6. Operate & maintain — Operate mode

The phase most frameworks abandon is a Studio mode here: health
checks, backups run against the Mind's declared policy, the
audit trail, an **access review** that flags system-wide roles,
multiple-role users, and the unassigned, and one-click
**operator-manual generation** from the Mind — the document a
support team actually answers from. Maintenance follows the
loop: edit the Plan → assess impact → diff-before-save → `slice:repair`
retrofits improvements fleet-wide (idempotent, evidence-gated) →
verify in Operate. A fix made once, at the standard layer,
reaches every slice — and every client system built on them.

## Who does what (software house / big team)

| Role | Lives in | Signs off |
| :--- | :--- | :--- |
| Client / product owner | Plan (the Mind) | Requirements, operator manual |
| PM / BA | Plan, Operate | Journeys, release readiness |
| Architect | Design, contracts | Domain boundaries, Body reviews |
| Developers | Create / Design / CLI | Slices, drift-clean state |
| QA | Verify gates | Real-usage runs, replica status |
| DevOps / operator | Operate | Backups, health, access review |
| Support | Operate | Manuals, audit trail |

Role clubs in the Mind mirror the delivery team as well as the
end users — the same mechanism governs both.

## Where AI sits in the lifecycle

AI is a team member with a defined seat, not a side channel:

- **Discovery**: drafts the Mind and Bodies from a brief —
  marked drafts, approved by humans.
- **Build**: scaffolds and extends slices from the same
  declarations humans write; its work is diffable YAML + code,
  reviewed like a colleague's PR.
- **Verification**: runs the gates and reports evidence; it
  quotes status from the status files, and it never approves
  its own deviations or releases.
- **Operate**: reads health and audit surfaces through the same
  permissions the UI enforces (the Copilot renders only for
  users who hold its permission; MCP access is permissioned
  the same way).

The framework skill is the shared protocol that makes this
safe: one document teaches a new hire and an AI assistant the
same rules, the same gates, and the same boundaries — so the
lifecycle holds no matter who, or what, is doing the work.
