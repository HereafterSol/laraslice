# One Architecture, Every Runtime — the Portability Vision

**Status:** LaraSlice (Laravel) is the reference implementation
and ships today. The sibling runtimes named below are the
roadmap this document exists to make possible — none is claimed
to exist yet. What exists, and what this vision is built on, is
the *contract*: a set of runtime-neutral files and standards
that any stack can implement.

## The problem this answers

Software outlives its runtime. Frameworks age, language
versions reach end-of-life, and — the newer force — critical
vulnerabilities are now discovered in language runtimes
themselves, increasingly by AI, at a pace that compresses the
window between disclosure and exploitation for everyone. A
system welded to one stack experiences each of those events as
an emergency. A system whose truth is *declared* experiences
them as a migration — planned, gated, verifiable.

LaraSlice is built so the declaration is the asset:

- **Mind** (`system.yaml`) — why the system exists: purpose,
  actors, journeys, operations. Runtime-neutral by nature; a
  business does not change when its stack does.
- **Body** (`*.ui.yaml`) — what every page looks like: the
  declared surface. Describes intent (columns, filters, tabs,
  composition), not any framework's components.
- **Heart** (`slice.yaml`) — what runs: data, fields,
  relations, workflows, settings, per slice. The vocabulary is
  the standard's; each runtime maps it to its own ORM, router,
  and component library.
- **Soul** — the design identity, declared in the Mind and
  realized by each runtime's standard UI layer.

Around the files sit the experiences, and they are part of the
contract too: the **Dev Studio** (six modes — Plan, Port,
Design, Create, Manage, Operate), the **CLI** (every Studio
action has a command with the same inputs), and the **AI
surface** (the shipped skill, MCP, and the Copilot) — all
governed by the Dev Studio Standard, which was written from
the start as the inheritance every future runtime adopts.

## The runtime family

One Slice architecture, implemented per stack:

| Runtime | Stack | Status |
| :--- | :--- | :--- |
| **LaraSlice** | Laravel / PHP | Reference implementation — shipping |
| **NodeSlice** | Node.js | Roadmap |
| **JavaSlice** | Java | Roadmap |
| **.NetSlice** | .NET | Roadmap |
| **PythonSlice** | Python | Roadmap |
| **ReactSlice** | React | Roadmap |
| **NextSlice** | Next.js | Roadmap |
| **AngularSlice** | Angular | Roadmap |
| **AstroSlice** | Astro | Roadmap |
| **HtmlSlice** | Plain HTML | Roadmap |
| **FlutterSlice** | Flutter | Roadmap (mobile generator exists in LaraSlice today) |
| **AndroidSlice** | Android | Roadmap |

The end state this table serves: one YAML describing a whole
domain — an ERP, a CRM, a clinic system — generating working
software on the stack a team actually runs, and moving between
stacks without the system being re-thought, only re-mapped.

## What a sibling runtime must implement (the inheritance)

1. **The trilogy formats** — read and write Mind, Body, and
   Heart files identically; the vocabulary does not fork per
   runtime.
2. **The slice anatomy, translated** — model, migration,
   business objects, service, controllers, views, routes,
   permissions, in whatever idiom the stack uses; boundaries
   enforced by that stack's tests.
3. **The Standard UI patterns** — directories, forms, hubs,
   documents, matrices, reports, dashboards, settings,
   rendered by the runtime's own component library to the
   same declared behavior.
4. **The Dev Studio Standard** — the same six modes, the same
   information architecture, CLI parity for every action.
5. **The protocol** — the AI skill's rules and the evidence
   gates (drift, real-usage, and for migrations the Replica
   Protocol) apply unchanged; a gate does not soften because
   the stack changed.

## Moving between runtimes

A cross-runtime move is a port, and it is verified like one:
the Replica Protocol's five gates translate directly — schema
parity across the two stacks' data layers, data truth proven
by joins, furniture parity page by page, the same task
journeys executed on both, and visual conformance read from
paired screenshots. The Mind does not move at all; it was
never the old runtime's property.

## The discipline that keeps this possible

Portability is lost in small leaks, so the rule is strict:
**the Heart's vocabulary stays runtime-neutral.** Storage
types are families, not one ORM's column classes; UI is
declared as patterns, not components; stack specifics live in
the runtime's mapping layer, never in a system's files. Every
feature added to LaraSlice is reviewed against that rule —
a declaration only one runtime could honor is a defect in the
standard, however convenient it is locally.

That is the whole vision: *make the standard once, keep it
neutral, and let every runtime — and every AI that follows
the skill — inherit it.*

## Built to last — the permanence principles

Most software efforts are wasted, and the pattern of waste is
consistent: a feature is absorbed by the platform it sat on, a
product's economics never close, a system dies with the
runtime, vendor, or team that carried it in their heads. This
project is structured, deliberately, against every one of
those failure modes:

1. **A standard, not a feature.** Features get absorbed;
   standards get built on. The trilogy formats, the slice
   anatomy, and the Studio standard are the contribution —
   implementations, including this one, are replaceable.
2. **Neutrality over lock-in.** No platform's roadmap,
   pricing change, or end-of-life can kill a system whose
   truth is declared rather than welded — that is the whole
   point of the runtime family above.
3. **Knowledge in files, not heads.** A system's intent,
   surface, and truth — and the method itself, in the skill —
   are plain files any future human or AI can pick up and
   continue. Work is wasted when it becomes illegible; this
   work is written to stay legible.
4. **Domain-shaped, so it travels.** Slices describe actors,
   data, journeys, and operations — the anatomy of systems,
   not of web pages. The same pattern is intended, in time,
   for fields beyond business software, IoT and robotics
   among them: fleets, devices, and telemetry are domains
   too. That is a horizon, stated as one.
5. **Earned by adoption, not declared.** Permanence is not a
   claim in a document; it is what happens when working
   software proves a standard worth inheriting. Hence the
   sequence this project follows: an excellent reference
   implementation, a flagship proof, a second runtime —
   then the standard spreads the way standards always have,
   through use.

The aim, plainly: build the layer that outlives its builders'
tools — for the developers and the AI alike who will build on
it next.
