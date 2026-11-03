# ADR-002 — The application shell ships in the package

**Status:** Accepted (1 Nov 2026). **Supersedes:** the Layer-2
mechanics of ADR-001 (the three-layer model itself stands).

## Context

ADR-001 described Layer 2 as a `laraslice-starter` template
repository, cloned once per project. That is no longer how the
product works, and the skill + README kept teaching the cloned
flow while the code did something else — a newcomer following
the docs met a contradiction at install time.

## Decision

The application shell (layouts, BlatUI components, theme
tokens, starter slices) **ships inside the `hereafter/laraslice`
package** and is published into the host application by
`slice:install`. From that moment the shell is project-owned:
the team edits it freely; framework updates arrive through
Composer and never overwrite the published shell.

There is no starter repository to clone. §24 of the skill is
the single install truth: create the Laravel app, require the
package, run `slice:install`.

## Consequences

- One install path for every audience (AI, developer, founder).
- The packaging model keeps ADR-001's ownership guarantee —
  upstream updates cannot break a project's shell — without a
  second repository to keep in sync.
- ADR-001 remains in the record as history; where its text
  describes cloning, this ADR governs.
