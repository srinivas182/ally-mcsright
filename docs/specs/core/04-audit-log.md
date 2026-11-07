# 04 — Audit log (Team A, Sprint 7)

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
- Cross-tenant HQ access (`withoutTenantScope`).

## Data model

### `audit_outbox` (cell)

Written in the same database transaction as the change, so an event is never lost:
`id, tenant_id, payload (json), created_at`.

### `audit_events` (cell, connection `audit`, partitioned by month)

| Column                                         | Notes                                                            |
| ---------------------------------------------- | ---------------------------------------------------------------- |
| id (bigint), ulid                              |                                                                  |
| tenant_id                                      | Nullable only for platform-level HQ events                       |
| chain_seq                                      | Per-tenant sequence number (gapless)                             |
| occurred_at                                    | datetime(6), UTC                                                 |
| actor_type                                     | `user`, `system`, `api_client`, `hq_support`, `ai`               |
| actor_id, actor_name, actor_roles              | Snapshot at the time                                             |
| impersonator_id                                | When acting as another user                                      |
| ip, user_agent, session_id, request_id         |                                                                  |
| action                                         | `{module}.{entity}.{verb}`                                       |
| subject_type, subject_id (ulid), subject_label | e.g. `Contact`, ulid, "Sarah Lee"                                |
| changes                                        | json `{field: {old, new}}`, sensitive values masked (`***1234`)  |
| metadata                                       | json (e.g. AI source record IDs, export file name, rule matched) |
| severity                                       | `info`, `notice`, `warning`, `critical`                          |
| prev_hash, hash                                | sha256 over canonical JSON of the event + `prev_hash`            |

Indexes: `(tenant_id, occurred_at)`, `(tenant_id, subject_type, subject_id)`,
`(tenant_id, actor_id, occurred_at)`, `(tenant_id, action, occurred_at)`.

**Append-only enforcement:** the `audit` database user has `INSERT` and `SELECT` only on
`audit_events`; no `UPDATE` or `DELETE`. Partitions older than 13 months are exported to object
storage with write-once retention (7 years by default; configurable per tenant upwards only) and
then detached.

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

## Acceptance criteria

1. Every action in "What is recorded" produces an event with actor, time, IP and changes.
2. Events cannot be updated or deleted by the application's database user (test proves it).
3. `audit:verify` detects a tampered event (test alters a row with a privileged connection).
4. Outbox guarantees no event is lost if the worker is down; lag under 5 seconds when running.
5. Search and record history return only the current tenant's events.
6. Sensitive values are masked in `changes`.
7. Exports are audited; default alert rules fire as specified.
8. Owners can see all HQ support sessions in their tenant.
