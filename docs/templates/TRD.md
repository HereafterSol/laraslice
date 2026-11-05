# TRD — Technical Requirements (one page)

_The dossier's slot 2 (SKILL §28). LaraSlice fixes most of the
stack by design — Laravel/PHP, the trilogy, the standard UI —
so this page records only the choices LaraSlice deliberately
leaves open. One page, named decisions, no essays. When a
choice changes, update the line and the date; the running
system's declarations (Mind/Heart) remain the runtime truth._

- **Project:** …
- **Date / decided by:** …

## Runtime & hosting

| Choice | Decision | Why (one line) |
| --- | --- | --- |
| Hosting (server / platform) | | |
| PHP + Laravel versions | | |
| Process model (queue workers, scheduler) | | |

## Data

| Choice | Decision | Why (one line) |
| --- | --- | --- |
| Database (MySQL / PostgreSQL / SQLite) | | |
| File storage (local / S3-compatible) | | |
| Backups (where, how often, restore test) | | |

## Integrations

| Service | Decision | Notes (keys live in the vault, never here) |
| --- | --- | --- |
| Mail (SMTP / provider) | | |
| Payments | | |
| Maps / SMS / other APIs | | |

## Access & security

| Choice | Decision |
| --- | --- |
| Login methods (password / SSO / social) | |
| MFA required for which roles | |
| Public registration open or invite-only | |

## Open questions

- … (each with an owner and a date to decide by)
