# Deployment requirements

Settings the platform depends on in every environment. Each one was found by a test.

## MySQL / Aurora

| Setting                           | Value                       | Why                                                                                                                                                                                                                                                                                                                                |
| --------------------------------- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `log_bin_trust_function_creators` | `1` (DB parameter group)    | The ledger migration creates triggers that make journal history append-only. With binary logging enabled (standard on RDS/Aurora), MySQL only lets a non-super user create triggers when this is on. Without it, the first production deploy fails at the ledger migration. Found in Sprint 2B by running the suite on real MySQL. |
| Default isolation level           | `REPEATABLE READ` (default) | Ledger posting relies on row locks (`SELECT … FOR UPDATE`) on balance rows and shared locks on periods.                                                                                                                                                                                                                            |
| Character set                     | `utf8mb4`                   | Architecture v2 data standards.                                                                                                                                                                                                                                                                                                    |

## Application

| Setting                                                 | Why                                                                                                                                                  |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CORE_HQ_HOST`, `CORE_PLATFORM_DOMAIN`                  | Tenant resolution (specs/core/01).                                                                                                                   |
| `CORE_BREACHED_PASSWORD_CHECK`                          | `true` in every real environment: new passwords are checked against Have I Been Pwned (k-anonymity; needs outbound HTTPS to api.pwnedpasswords.com). |
| First HQ staff member                                   | `php artisan tenants:bootstrap-hq --admin-email=…` emails an invitation (no passwords on the command line).                                          |
| Scheduler running (`php artisan schedule:work` or cron) | `tenants:lifecycle` daily, `ledger:reconcile` nightly.                                                                                               |
