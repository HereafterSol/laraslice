# Changelog

All notable changes to the **LaraSlice** framework will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2026-10-05

### Added
- **Visual Slice Studio (`/laraslice/wizard/studio`)**: Visual orchestrator for vertical slices, aggregate entities, and schema management with live bidirectional event bus (`laraslice-slice-selected`).
- **Real-Time MySQL Column Introspection**: AI Copilot inspects exact database column schemas via native `SHOW COLUMNS FROM {table}` queries, returning accurate field names, types, nullability, keys, and defaults.
- **Conversational 1-Click Schema Migrations**: Natural language column additions in Copilot (`add emergency_contact in user_details`) with inferred SQL types, safe non-breaking nullability, enterprise suggestion chips, and instant execution.
- **Dynamic Context-Aware Slash (`/`) Commands**: Intelligent `/fields [table]`, `/add-field [table]`, `/relations`, `/permissions`, `/nav`, `/logs`, `/suggest-fields`, `/seed`, and `/wipe` commands.
- **Enterprise User Aggregate (15 Tables)**: Primary `users` root table coordinating 14 child entities (`user_details`, `user_devices`, `user_passkeys`, `user_security_logs`, etc.).
- **FIDO2 / WebAuthn Biometric Passkeys**: Complete passwordless enrollment, session lockscreen unlock, and safe revocation handling with multi-verb route dispatching.
- **Live DOM Screen Context**: Copilot automatically inspects visible page inputs and the 4 Quick Starter Domain Suites (CRM, E-Commerce, Billing, Helpdesk).
- **Public Changelog Page**: GitHub Pages changelog at `https://hereaftersol.github.io/laraslice/changelog.html`.

### Fixed
- **Passkey Revocation 404**: Resolved route matching issue by supporting multi-verb dispatch (DELETE/POST/GET) and adding graceful fallback redirection back to `#mfa`.
- **Field Migration Button Visibility**: Corrected status assignment in Alpine Copilot so field migration action cards render completely.

---

## [1.1.0] - 2026-09-20

### Added
- **Declarative Blueprint Studio**: Visual schema and relationship designer with live directory preview.
- **Multi-Table Aggregate Root Architecture**: Parent-child entity hierarchy with transaction-safe cascading updates.
- **Cross-Slice Contracts & DTOs**: Decoupled slice communication standards ensuring zero tight coupling between bounded contexts.
- **Dual-Layer Audit Engine**: Intrinsic user lifecycle stamps paired with immutable system event logs and automated retention pruning (`slice:audit:prune`).

---

## [1.0.0] - 2026-08-15

### Added
- Initial release of **LaraSlice** — Autonomous Vertical Slice Architecture framework for Laravel 11, 12, and 13+.
- **Three-Layer Packaging Model (ADR-001)**: Core Engine, Starter Slices, and Project Slices.
- **Pure Blade + Alpine.js + Tailwind CSS v4 + BlatUI**: Zero Livewire or Metronic bloat; ultra-fast page loads.
- **Artisan CLI Generators**: `slice:make`, `slice:seed`, `slice:toggle`, and `slice:wizard`.
- **Flutter & Mobile API Scaffolding**: Unified generation of typed web views and mobile REST API contracts.