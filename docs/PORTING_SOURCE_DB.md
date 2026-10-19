# Porting: source database vs target

## Rule

When analyzing a **source** path, LaraSlice must discover schema from:

1. `--source-database` / `--source-connection` / `--source-host`… (live legacy DB)
2. Laravel `database/migrations` under the source path
3. SQL dumps (`CREATE TABLE`) under the source tree

It must **not** silently list tables from the **target** application database. That produced false “36 tables” results when porting Dolibarr against a LaraSlice SQLite install.

## CLI

```bash
php artisan slice:port {source_path} \
  --source-host=127.0.0.1 \
  --source-port=3306 \
  --source-database=LEGACY_DB \
  --source-username=USER \
  --source-password=PASS \
  --dry-run
```

## Escape hatch (discouraged)

```env
LARASLICE_PORT_ALLOW_APP_DB_FALLBACK=true
```
