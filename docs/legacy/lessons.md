# Legacy problems the new platform must never repeat

Found in the Phase 0 review. Each has a structural answer in the new architecture.

| Legacy problem                                                           | New-platform answer                                                                                                 |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| Live payment and partner API keys hardcoded in source                    | Secrets only in a secrets manager via `config/`; architecture test forbids `env()` outside config                   |
| Cron, migration and billing endpoints reachable over HTTP without auth   | Scheduler and queued jobs only; no HTTP entry points for jobs; migrations run by the release pipeline               |
| Full database backup written to a public folder                          | Managed backups with PITR, encrypted, never in the web root                                                         |
| User input placed in shell commands during tenant provisioning           | Provisioning is an internal service with no shell interpolation; tenants are database records in a cell             |
| All tenants shared one database superuser and one default admin password | Per-cell least-privilege credentials; every user sets their own password; optional 2FA, required for HQ admins      |
| "Update" ran git reset on each customer's folder, wiping changes         | Central releases through canary and waves with automatic rollback; customisation is configuration, never code edits |
| CSRF off, cookies not secure, empty encryption key, SSN in plain text    | Framework defaults on; per-tenant envelope encryption for sensitive fields                                          |
| Unsigned payment webhooks                                                | Signature verification on every webhook                                                                             |
| No foreign keys, mixed engines and charsets, money stored as text        | Data standards in architecture-v2 section 7, enforced in review and tests                                           |
| Copies of the codebase per brokerage with fixes ported by hand           | One codebase, modules and entitlements; tenants share releases                                                      |
| No automated tests or CI                                                 | Pest, PHPStan level 8, architecture tests, CI-green merge rule                                                      |
