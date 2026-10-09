# Contributing to LaraSlice

Thank you for your interest in contributing to **LaraSlice**! We welcome bug fixes, documentation improvements, core slice enhancements, and architectural features from the community.

---

## 🏛️ Architecture Overview: Core vs. Application Slices

LaraSlice operates on the **Three-Layer Packaging Model (ADR-001)**:
1. **Core Framework (`vendor/hereafter/laraslice` / `src/`)**: Contains the discovery engine, declarative schema system, CLI generators, and default starter core slices (`Users`, `Roles`, `Settings`, `Auth`).
2. **Project Workspace (`app/Slices/`)**: Application-specific domain slices (e.g. `Invoices`, `Catalog`, `HR`).

### Discovery Precedence
LaraSlice's discovery system is explicitly designed so that **any slice located in `app/Slices/` automatically overrides the packaged core slice of the same name**.

---

## 🛠️ How to Customize a Core Slice in Your Project

If your project needs to customize a core slice (for instance, adding `cnic`, `phone`, or custom relationships to `Users`):
```bash
php artisan slice:publish Users
```
To publish all packaged core slices simultaneously:
```bash
php artisan slice:publish --all
```

### What this does:
1. Copies the entire slice from `vendor/hereafter/laraslice/src/Slices/{SliceName}` into your local `app/Slices/{SliceName}`.
2. Registers it as first-class project code in your repository: you can edit models, migrations, DTO contracts, and BlatUI Blade views.
3. Future `composer update` operations will **never** overwrite or touch files inside `app/Slices/`.

---

## 🌐 Open Source Contributions to Framework Core

If you want to contribute enhancements or fixes directly back to LaraSlice's core starter slices or framework engine:

### 1. Fork & Clone
1. Fork the official repository: [github.com/AbdurRehman712/laraslice](https://github.com/AbdurRehman712/laraslice)
2. Clone your fork locally:
   ```bash
   git clone https://github.com/<your-username>/laraslice.git
   cd laraslice
   composer install
   ```

### 2. Making Changes
- **Core Slices**: Edit files inside `src/Slices/{SliceName}/` (e.g. `src/Slices/Users`, `src/Slices/Roles`, `src/Slices/Settings`).
- **Core Engine**: Edit files in `src/Core/`, `src/Commands/`, `src/Generator/`, or `src/Schema/`.
- Ensure all views use pure **BlatUI standard** (Blade + Tailwind CSS v4 + Alpine.js + Lucide icons). Zero Metronic or Keenicons dependencies.

### 3. Running the Test Suite
Unit, feature and architecture-boundary tests, formatting (Pint) and static analysis (Larastan) must pass; CI runs the same checks on PHP 8.2–8.4 and Laravel 11–13:
```bash
composer test     # PHPUnit
composer lint     # pint --test, then phpstan
composer format   # fix formatting
```
Larastan starts from a baseline (`phpstan-baseline.neon`) of findings that predate it. Fix entries rather than adding new ones.

### 4. Submitting a Pull Request
1. Commit your changes with descriptive commit messages following the Conventional Commits specification (e.g., `feat:`, `fix:`, `docs:`).
2. Push to your fork and submit a Pull Request on GitHub against the `master` branch.

---

## 💻 Local Development Workflow (Path Repository)

When developing a feature or bug fix for LaraSlice, the best way to verify your changes live is by linking your local LaraSlice repository to a Laravel test application using Composer's path repository:

1. Open your test Laravel application's `composer.json`.
2. Add a `path` repository pointing to your local LaraSlice clone:
   ```json
   "repositories": [
       {
           "type": "path",
           "url": "../LaraSlice",
           "options": {
               "symlink": true
           }
       }
   ]
   ```
3. Run:
   ```bash
   composer update hereafter/laraslice
   ```
4. Composer creates a symlink/junction in `vendor/hereafter/laraslice` pointing directly to your local LaraSlice directory. Any edits you make in LaraSlice are immediately reflected in your test app in real time!
