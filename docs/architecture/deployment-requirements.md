# Deployment requirements

Settings the platform depends on in every environment. Each one was found by a test.

## MySQL / Aurora

| Setting                           | Value                       | Why                                                                                                                                                                                                                                                                                                                                |
| --------------------------------- | --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `log_bin_trust_function_creators` | `1` (DB parameter group)    | The ledger migration creates triggers that make journal history append-only. With binary logging enabled (standard on RDS/Aurora), MySQL only lets a non-super user create triggers when this is on. Without it, the first production deploy fails at the ledger migration. Found in Sprint 2B by running the suite on real MySQL. |
| Default isolation level           | `REPEATABLE READ` (default) | Ledger posting relies on row locks (`SELECT … FOR UPDATE`) on balance rows and shared locks on periods.                                                                                                                                                                                                                            |
| Character set                     | `utf8mb4`                   | Architecture v2 data standards.                                                                                                                                                                                                                                                                                                    |

## Application

| Setting                                                 | Why                                                                                                                                                      |
| ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CORE_HQ_HOST`, `CORE_PLATFORM_DOMAIN`                  | Tenant resolution (specs/core/01).                                                                                                                       |
| `CORE_BREACHED_PASSWORD_CHECK`                          | `true` in every real environment: new passwords are checked against Have I Been Pwned (k-anonymity; needs outbound HTTPS to api.pwnedpasswords.com).     |
| First HQ staff member                                   | `php artisan tenants:bootstrap-hq --admin-email=…` emails an invitation (no passwords on the command line).                                              |
| Scheduler running (`php artisan schedule:work` or cron) | `tenants:lifecycle` daily, `ledger:reconcile` nightly, `transactions:escalate` hourly, `transactions:scan-open` and `transactions:date-reminders` daily. |

## Document tools (forms and DocIQ)

- System packages on every app and queue server: `qpdf`, `poppler-utils` (pdftotext, pdftoppm),
  `tesseract-ocr` and `tesseract-ocr-eng` (OCR of scanned pages and phone photos, run on our own servers so
  no document images go to outside services). All are in `docker/php/Dockerfile`.
- Temporary files handed to these tools always end in `.pdf` (Ubuntu's AppArmor profile for qpdf refuses
  other names).
- A queue worker must run: uploaded deal documents are read by the `ReadDocument` job.

## Deadline texts and automated calls (Sprint 15D)

- Twilio is set up in **RightAlly HQ → Texts and calls**: Account SID and auth token (stored encrypted), the
  number to text from and the number to call from, then switched on per brokerage. `TWILIO_SID`,
  `TWILIO_TOKEN` and `TWILIO_FROM` in the environment are only a fallback.
- US texting needs an approved A2P 10DLC brand and campaign on the Twilio account (or a verified toll-free
  number) before texts are delivered.
- Calls use Twilio's text-to-speech; no webhooks are needed.

## Sign in with Google / Microsoft (Sprint 4A-2)

- `CORE_SSO_HOST` (default `auth.rightally.app`): one host for every brokerage's Google/Microsoft
  sign-in; point it at the app and register `https://<CORE_SSO_HOST>/oauth/google/callback` and
  `https://<CORE_SSO_HOST>/oauth/microsoft/callback` with Google Cloud and Microsoft Entra.
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`,
  `MICROSOFT_TENANT` (default `common`). A provider without a client ID is hidden.
- The cache must be shared across app servers (Redis): sign-in tickets live there for 60 seconds.

## Audit log database users (Sprint 7A-3)

- `CORE_AUDIT_CONNECTION`: a MySQL user with `INSERT, SELECT` on `audit_events`, `audit_outbox`
  (plus `DELETE` on `audit_outbox` only) and `INSERT, UPDATE, SELECT` on `audit_heads` — **no UPDATE or
  DELETE on `audit_events`**.
- `CORE_AUDIT_MAINTENANCE_CONNECTION`: used only by daily retention; `SELECT, DELETE` on `audit_events`.
- Scheduler: `audit:flush` and `audit:maintain stream` every minute, `audit:maintain retention` daily,
  `audit:maintain weekly-digest` on Mondays.
