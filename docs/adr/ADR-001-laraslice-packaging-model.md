# ADR-001: Three-layer packaging model

**Status:** Accepted  
**Context:** Teams need framework updates via Composer without losing ownership of client-specific domains or fighting vendor overrides on every release.

## Decision

LaraSlice uses a **three-layer hybrid model**:

### Layer 1 — `hereafter/laraslice` (Composer / `vendor/`)

- Shared engine: slice discovery and cache, audit, RBAC bridge, declarative schema, generators, CLI, Studio shell.
- Updated through Composer and CI. No business domains in the package.

### Layer 2 — Starter application (template repo, cloned once)

- App shell, BlatUI layout, theme tokens, default slices (Auth, Users, Roles, Settings, Audit).
- Owned by the client or agency after clone. No requirement to merge upstream starter on every framework bump.

### Layer 3 — `app/Slices/*` (project-owned)

- Business slices (Invoices, Employees, Tickets, …).
- **Generation gap:** regenerable bases vs hand-written subclasses and `Contracts/` — generators must not overwrite custom code.

## Consequences

- **Zero lock-in claim** holds when slices remain plain Laravel (Eloquent, controllers, Blade, migrations) and can be relocated to standard `app/` structure if the package is removed.
- **Boundary tests** enforce cross-slice communication via DTOs and public services, not foreign model imports.
- **Publishing:** `php artisan slice:publish Vendor/Slice` copies a vendor slice into Layer 3 for full customization.

## Related

- [README.md](../../README.md) — diagram and capabilities  
- [RUNTIMES.md](../RUNTIMES.md) — Mind/Body/Heart portability  
- [ARCHITECTURE-VS-PORT-TRUTH.md](../ARCHITECTURE-VS-PORT-TRUTH.md) — framework vs port gates
