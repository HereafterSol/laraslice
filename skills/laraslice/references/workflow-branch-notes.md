## Branch notes (`feat/workflow-bulk-actions`) — keep in sync with code

### Slice YAML v0.1
- Docs: `docs/SLICE_YAML_V0.1.md`, `docs/SLICE_YAML_STRATEGY.md`
- Machine schema: `schemas/slice-v0.1.json`
- `php artisan slice:validate {path|path.yaml}` or `--all` (CI exit 1)

**Closed formatters:** text, text_with_badge, status_pill, date_time, date_overdue, money_breakdown, progress_bar, code_badge, composite_user, number

**`type` vs `input`:** `type` = storage; `input` = form widget (`select`, `textarea`, …). Never use `type: select`.

### Phase 2 DataTable parser
`LaraSlice\Porting\DataTableUiParser` — static classification of source `*DataTable.php` into the formatter vocabulary.

```bash
php artisan slice:repair --re-infer-ui --source=/path/to/erp
```

### Runtime directory columns
`LaraSlice\Support\DirectoryUi::columnsForSlice($name, $fallback)` resolves `slice.json` from the **registered** slice path (Composer path-repo, vendor, or `app/Slices`). Index blades call this at runtime — do not rely on `__DIR__` or paths baked only at repair time.

```bash
php artisan slice:repair          # re-inject DirectoryUi loader; merge config gaps
php artisan slice:repair --publish-ui
php artisan view:clear
```

### Regenerating an existing slice's form
`php artisan slice:regen-form {Slice} [--path=DIR] [--write] [--keep-staging]`
rebuilds form/create/edit from the slice's Heart: field definitions are
rebuilt from the manifest, the slice is generated into a staging root, and
only the three form views are transplanted. Dry-run by default — the report
names any surface that would be **lost** (named inputs / `$…Options`
variables the Heart cannot re-derive, e.g. pivot multi-selects and hand
edits). `--write` backs the originals up under
`storage/app/laraslice/regen-backups/` before transplanting.

### MFA local toggle
```env
LARASLICE_MFA_ENABLED=false   # default true — never disable in production
```
`slice:repair` merges missing keys (e.g. `auth.mfa_enabled`) into published `config/laraslice.php`.

---

