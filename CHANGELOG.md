# Changelog

All notable changes to the **LaraSlice** framework will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.3.2] - 2026-10-06

### Fixed
- **Seed Demo Data `Data truncated for column 'updated_by'`**: The demo seeder treated any column containing "date" as a date, including `updated_by`. Date columns are now matched by whole word (`close_date`, `date_of_birth`).
- **Orphaned userstamps in demo data**: `created_by` and `updated_by` are now filled with the authenticated user (or the first existing user) instead of made-up ids; `deleted_by` stays null.
- **Boolean guessing**: `is_` / `has_` columns are matched as prefixes only, so names like `analysis_notes` keep text values.

## [1.3.1] - 2026-10-05

### Changed
- **Admin sidebar rebuilt on BlatUI sidebar-02**: The starter layout's sidebar now uses collapsible navigation groups with a version switcher and search form in the header. Groups are Workspace, one per domain, Vertical Slices (ungrouped app slices), and Administration (core slices). Slices with child pages render as dropdowns that open automatically on their active page, and every item shows the Lucide icon from its slice navigation settings. Long labels truncate instead of overlapping.

### Added
- `block/version-switcher` and `block/search-form` BlatUI components, published by `slice:install`.
- `core` flag on items returned by `SliceManager::getNavigableSlices()`.

### Removed
- Duplicate "AI Copilot & Settings" sidebar link (available under Settings & AI).

## [1.2.5] - 2026-10-05

### Fixed
- **Slice Studio "Generation failed: 0 slice(s) successfully generated"**: Escaped `$table` in the `SliceGenerator` migration template. The unescaped variable raised a PHP warning that failed every slice during web requests and produced migrations with invalid PHP elsewhere. This also fixes the `BlueprintApplierTest` failures.
- **Hidden generation errors**: Slice Studio's domain suite generation now logs each failing slice's exception and includes the slice name and error in the response message instead of only the success count.

## [1.2.4] - 2026-10-05
*Special thanks to community contributor **Muhammad Adnan** ([@adnanansari901](https://github.com/adnanansari901)) for contributing the v1.2.4 security, passkey, and installation enhancements!*


### Fixed
- **Passkeys on HTTP dev domains**: Passkey flows (login, MFA enroll/challenge, lockscreen, settings) now detect an insecure context and ask for HTTPS or `localhost` instead of wrongly reporting "WebAuthn / Passkeys are not supported by this browser".
- **`Unknown column 'session_id'` on device enrollment**: Added migration `2026_10_05_000000_add_device_trust_columns_to_user_devices_table` to backfill `session_id`, `device_label`, and `is_trusted` on `user_devices`, which the v1.3.0 schema skipped when the v1.1.0 table already existed.
- **Settings page TypeError with `App\Models\User`**: `UserWebController` now resolves the host app's authenticated user to the slice `User` model.
- **`Call to undefined method hasRole()`**: `slice:install` now injects `HasSlicePermissions` into the app's User model whatever traits it already uses (e.g. `use HasApiTokens, HasFactory, Notifiable;`).
- **`ComponentAttributeBag::twMerge does not exist`**: Registered the `twMerge` attribute macro used by the published BlatUI components, backed by the new `gehrisandro/tailwind-merge-php` dependency. An app-provided `twMerge` macro takes precedence.
- **`[postcss] ENOENT ... open 'tailwindcss'` on `npm run dev`**: `slice:install` now adds `tailwindcss` and `@tailwindcss/vite` v4 to `package.json`, registers the Tailwind plugin in `vite.config.js`, and runs `npm install` when any required package is missing. The starter `blatui.css` now scans `vendor/hereafter/laraslice` instead of a non-existent vendor path and a hard-coded local `E:/` path.

## [1.2.3] - 2026-03-31

### Fixed
- Removed non-standard `bps_scale` reference and fragile `->after(...)` clauses in `enhance_users_table_and_security_devices` migration that caused column not found errors during `php artisan slice:install` on fresh databases.

## [1.2.2] - 2026-03-31

### Fixed
- Registered `SliceInstallCommand` in `LaraSliceServiceProvider` so `php artisan slice:install` is immediately available in downstream Laravel applications.
- Registered `laraslice-config` and `laraslice-starter` vendor publish tags.

### Added
- Added `skills.sh.json` and official skills.sh directory badge to README.

## [1.2.1] - 2026-10-05

### Fixed
- **Packagist Upstream Re-Tag Resolution**: Incremented release tag to `v1.2.1` to comply with Packagist immutability requirements.
- **Passkey Revocation 404**: Multi-verb routing (`DELETE`, `POST`, `GET`) with safe model deletion and redirect to `#mfa`.

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