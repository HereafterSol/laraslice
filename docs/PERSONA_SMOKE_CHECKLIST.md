# 3-Persona smoke checklist (ERP demo parity)

Demo accounts (demo.worksuite.biz style):

| Persona | Email (typical) | Password |
|---------|-----------------|----------|
| Admin | admin@example.com | 123456 |
| Employee | employee@example.com | 123456 |
| Client | client@example.com | 123456 |

## Sidebar modules (after `slice:repair --remap-nav`)

Expect short groups: **CRM, Work, HR, Finance, Orders, Tickets, Events, Messages, Settings** — not FinancialAccounting / Operations dumps.

| Module | Admin | Employee | Client |
|--------|-------|----------|--------|
| Work (Projects, Tasks) | yes | yes | yes |
| Finance (Invoices, Payments) | yes | limited | yes (own) |
| HR | yes | yes (self) | no |
| CRM (Leads, Clients) | yes | maybe | no |
| Settings / aux taxes | yes | no | no |

## Per-slice checks

1. **Index** — table / board / calendar switcher when views configured  
2. **Inline status** — dropdown posts workflow transition when workflow enabled  
3. **Show** — Overview + related hasMany tabs + workflow audit when enabled  
4. **Slice Studio** — change group, roles, workflow states → Save → YAML + sidebar update  

## Commands

```bash
php artisan slice:repair --remap-nav --publish-ui
php artisan slice:sync
```
