# LaraSlice example blueprints

These YAML files are **merge-blocking product samples** for UI testing in Slice / Blueprint Studio.

| Path | Purpose |
|------|---------|
| `kitchen-sink.slice.yaml` | All v0.1 formatters + common UI blocks on one slice |
| `suites/crm-*.slice.yaml` | CRM: Companies, Contacts, Deals |
| `suites/hr-*.slice.yaml` | HR: Employees, Leave |
| `suites/shop-*.slice.yaml` | Shop: Products, Orders |
| `project-portfolio.slice.yaml` | Existing portfolio sample |
| `service-desk.slice.yaml` | Existing service desk sample |

## Validate

```bash
php artisan slice:validate blueprints/examples/kitchen-sink.slice.yaml
php artisan slice:validate blueprints/examples/suites --all   # if path walk supported
# or per file:
for f in blueprints/examples/suites/*.slice.yaml; do
  php artisan slice:validate "$f" || exit 1
done
```

## Studio

1. Open **Blueprint Studio** → paste or open a file from this folder.
2. Or **Slice Studio** → import YAML.
3. Kitchen Sink is the visual QA target: code badge, title+badge, status pill, progress bar, money breakdown, date overdue, date_time.

## Scaffold (optional)

```bash
# After copy into app/Slices or via Studio Generate
php artisan slice:repair --publish-ui
```
