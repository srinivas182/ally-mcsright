# 04 — Audit log (Team A, Sprint 7 + additions sprint)

Status: **Part 1 delivered (Sprint 7A)**: outbox in the change's transaction; sealing worker (`FlushAuditOutbox`, after commit, plus `audit:flush` every minute) with per-brokerage gapless `chain_seq` and SHA-256 chain (`audit_heads` holds each tip); `audit:verify` reports the first altered or missing event; read-only `AuditEvent` model; `Auditable` trait (users, contacts, tasks) with masked fields; sign-ins, failed sign-ins (email masked), lockouts, "log in as" start/stop, consent changes and contact views recorded; `asAutomation()` records the automation, the person acted for and its scope (lead pond, follow-up plans, nightly intelligence as AI); only a SHA-256 `session_ref`; owner Audit log screen (area, person, importance, dates, name search, details) and "Who viewed or changed this contact". **Part 2 next**: plain-English activity view, weekly digest, retention and legal hold, streaming, evidence pack, HQ platform events, separate append-only database user.

## Purpose

Every change in RightAlly is recorded: who, what, when, from where, and values before and after.
Nobody can edit or delete the log — not tenants, not RightAlly. Brokerages can search it, see the
history of any record, export it and receive alerts on risky events.

## What is recorded

- Create, update, delete and restore of every audited model (field-level changes).
- Approvals and rejections, imports and exports, logins and failed logins, lockouts.
- Role, permission, seat and entitlement changes; settings changes; domain changes.
- Payouts, bank-detail changes, billing changes (later modules).
- Views of sensitive data (SSN, bank details).
- Impersonation start/end, including HQ support sessions.
- Every AI answer: feature, model, and the IDs of the records used as sources.
- Every action taken by automation or AI, with the person it acted for (see `on_behalf_of`).
- Views of a contact or transaction file (for the "who viewed or changed this file" evidence pack).
- Cross-tenant HQ access (`withoutTenantScope`).

## Data model

### `audit_outbox` (cell)

Written in the same database transaction as the change, so an event is never lost:
`id, tenant_id, payload (json), created_at`.

### `audit_events` (cell, connection `audit`, partitioned by month)

| Column                                             | Notes                                                                                                                                                                                   |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| id (bigint), ulid                                  |                                                                                                                                                                                         |
| tenant_id                                          | Nullable only for platform-level HQ events                                                                                                                                              |
| chain_seq                                          | Per-tenant sequence number (gapless)                                                                                                                                                    |
| occurred_at                                        | datetime(6), UTC                                                                                                                                                                        |
| actor_type                                         | `user`, `system`, `api_client`, `hq_support`, `ai`                                                                                                                                      |
| actor_id, actor_name, actor_roles                  | Snapshot at the time                                                                                                                                                                    |
| impersonator_id                                    | When acting as another user ("log in as")                                                                                                                                               |
| on_behalf_of_user_id, automation, automation_scope | When automation or AI acted for a person: the person, which automation (`follow_up_plan`, `nightly_intelligence`, `ai_assistant`, `lead_pond`, `import`…) and what it was allowed to do |
| ip, user_agent, session_ref, request_id            | `session_ref` is a SHA-256 of the session ID — never the ID itself (OWASP); no tokens, passwords or secrets are ever logged                                                             |
| action                                             | `{module}.{entity}.{verb}`                                                                                                                                                              |
| subject_type, subject_id (ulid), subject_label     | e.g. `Contact`, ulid, "Sarah Lee"                                                                                                                                                       |
| changes                                            | json `{field: {old, new}}`, sensitive values masked (`***1234`)                                                                                                                         |
| metadata                                           | json (e.g. AI source record IDs, export file name, rule matched)                                                                                                                        |
| severity                                           | `info`, `notice`, `warning`, `critical`                                                                                                                                                 |
| prev_hash, hash                                    | sha256 over canonical JSON of the event + `prev_hash`                                                                                                                                   |

Indexes: `(tenant_id, occurred_at)`, `(tenant_id, subject_type, subject_id)`,
`(tenant_id, actor_id, occurred_at)`, `(tenant_id, action, occurred_at)`.

**Append-only enforcement:** the `audit` database user has `INSERT` and `SELECT` only on
`audit_events`; no `UPDATE` or `DELETE`. Partitions older than 13 months are exported to object
storage with write-once retention and then detached.

**Retention has two bounds:** events are kept **at least** the brokerage's required period (7 years
by default; a brokerage may choose longer for its state's record-keeping rules, never shorter than the
platform minimum) and are **deleted after it**, so nothing is kept longer than needed. A **legal hold**
(set by an owner or HQ, with a reason, itself audited) pauses deletion for a brokerage, a date range or a
specific record until released.

## Writing events

- `AuditLogger::record(action, subject, changes, metadata, severity)`.
- `Auditable` trait on models: records create/update/delete/restore with an allowlist of fields
  (`$audited`) and a list of sensitive fields (`$auditMasked`).
- An outbox worker moves events into `audit_events` in order per tenant, assigning `chain_seq`
  and computing the hash chain under a per-tenant lock. Target lag under 5 seconds.

## Integrity verification

- `php artisan audit:verify {tenant} {from} {to}` recomputes the chain and reports the first
  broken sequence, if any.
- HQ console button "Verify integrity" per tenant and date range; result stored and audited.

## Search and record history

- **Audit log screen:** filters for user, module, action, subject type, date range and severity;
  free-text on subject label; results show time, actor, action, subject and a changes summary;
  detail drawer with full before/after.
- **Record history component:** reusable tab on any record page showing that record's events.
- Performance: search over the last 90 days under 1 second p95 for a tenant with 10 million events.

## Export

CSV and PDF, filtered as on screen, up to 100,000 rows per export (larger exports queued with
email delivery). Every export is itself audited with the filters used.

## Alerts

### `audit_alert_rules` (cell)

`id, tenant_id, key, enabled, recipients (roles or users), threshold (json)`.

Default rules seeded per tenant:

| Rule                  | Trigger                                                                 |
| --------------------- | ----------------------------------------------------------------------- |
| Permission escalation | Sensitive permission or `core.roles.manage` granted                     |
| Bulk delete           | More than 50 deletes by one user in 10 minutes                          |
| Bank detail change    | Any change to payout bank details                                       |
| Sensitive export      | Export including sensitive fields                                       |
| Login attack          | 20+ failed logins for one account in an hour, or 100+ across the tenant |
| Support access        | HQ support impersonation started                                        |

Alerts go to in-app notifications and email.

## Support access visibility

Tenant owners see a "Support access" list: every HQ support session, who, when, reason, consent
reference and the actions taken.

## Screens

Audit log; record history tab; alert rules (enable/disable, recipients, thresholds); support
access list; HQ: cross-tenant search (HQ only) and integrity verification.

## Permissions

`core.audit.view`, `core.audit.export`, `core.audit.alerts.manage`; HQ: `hq.audit.view_all`,
`hq.audit.verify`.

## Streaming to the brokerage's security tools (addition 1)

- Destinations a broker sets up themselves under Settings › Security › Audit streaming: **HTTPS webhook**
  (signed with HMAC-SHA256, retries with back-off), **Amazon S3** (hourly JSON Lines files, their bucket
  and role), **Datadog** (Logs API key) and **Splunk** (HTTP Event Collector).
- Same event schema as the screen and export; delivery at least once, in order per brokerage, with an
  event ID so duplicates can be ignored; lag under 1 minute; a delivery-health panel shows the last
  success and any failures; failing destinations alert the owner and pause after 24 hours.
- Adding, changing or removing a destination is itself audited and needs the owner's 2FA.

## Activity view in plain English (addition 5)

- The default view for owners and staff: sentences, not codes — _"Pat reassigned 12 company leads to
  Sam · Tue 3:14 pm"_, _"Maria viewed Pat's account to help with: missing commission · 25 min"_,
  _"Nightly intelligence created 4 follow-up tasks for Pat"_.
- **Important only** filter (sign-in and security changes, exports, role and permission changes, payout
  and bank-detail changes, deletions, "log in as", legal holds); grouping of repeated actions
  ("12 contacts reassigned"); the technical detail stays one tap away.

## Weekly security digest (addition 6)

- Every Monday, owners get one email: new-device sign-ins, "log in as" sessions, large exports, role
  and permission changes, users added or removed, failed sign-in spikes, streaming failures — and **any
  change to payout or bank details, always listed first** (wire and commission fraud often starts with a
  quiet bank-detail change). Nothing to report → a short "all quiet" email. Owners can add staff.

## "Who viewed or changed this file" (addition 7)

- On any contact (and transaction, when Transactions ships): one tap shows every view and change in time
  order, including by automation and HQ support.
- **Evidence pack**: a PDF of that history with the hash-chain verification result and the brokerage's
  details, for E&O insurers, disputes, lawsuits or state audits; generating it is audited.

## Acceptance criteria

1. Every action in "What is recorded" produces an event with actor, time, IP and changes.
2. Events cannot be updated or deleted by the application's database user (test proves it).
3. `audit:verify` detects a tampered event (test alters a row with a privileged connection).
4. Outbox guarantees no event is lost if the worker is down; lag under 5 seconds when running.
5. Search and record history return only the current tenant's events.
6. Sensitive values are masked in `changes`.
7. Exports are audited; default alert rules fire as specified.
8. Owners can see all HQ support sessions in their tenant.
9. No session IDs, tokens, passwords or secrets appear in any event; `session_ref` is a hash (test).
10. Actions by automation and AI record the person acted for and the automation (follow-up plans,
    nightly intelligence, lead pond moves at minimum).
11. Events past the retention period are deleted unless under legal hold; legal holds are audited.
12. Streaming delivers to a test webhook with a valid signature, in order, retrying after failure;
    S3, Datadog and Splunk destinations deliver the same schema.
13. The plain-English activity view and the weekly digest list payout and bank-detail changes first.
14. The evidence pack for a contact lists every view and change with the verification result.
