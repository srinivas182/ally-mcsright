# 01 — Tenancy and cells (Team A, Sprints 1–2)

## Purpose

Every request, job and record belongs to exactly one tenant, and no code path can read or write
another tenant's data by accident. Tenants live in cells; the first release runs one cell, but
the design supports many from day one.

## Tenant types

| Type        | Example                 | Notes                                                        |
| ----------- | ----------------------- | ------------------------------------------------------------ |
| `hq`        | RightAlly HQ            | Exactly one. Runs the HQ console on the platform domain.     |
| `brokerage` | Jones Homes Realty      | Brokerages, their staff and agents.                          |
| `solo`      | Jane Smith (solo agent) | One agent's private workspace; folds into a brokerage later. |

## Data model

### `cells` (connection: control)

| Column                            | Type                                             | Notes                                   |
| --------------------------------- | ------------------------------------------------ | --------------------------------------- |
| id, ulid                          | bigint, char(26)                                 |                                         |
| name                              | varchar(60)                                      | e.g. `us-east-1-a`                      |
| region                            | varchar(30)                                      |                                         |
| status                            | enum `active`, `draining`, `full`, `maintenance` | Only `active` cells receive new tenants |
| db_connection                     | varchar(60)                                      | Connection name for this cell           |
| capacity_tenants, capacity_agents | int                                              | Soft limits used for placement          |
| created_at, updated_at            | timestamps                                       |                                         |

### `tenants` (connection: control)

| Column                             | Type                                                            | Notes                                        |
| ---------------------------------- | --------------------------------------------------------------- | -------------------------------------------- |
| id, ulid                           |                                                                 |                                              |
| type                               | enum `hq`, `brokerage`, `solo`                                  |                                              |
| name                               | varchar(150)                                                    | Display name                                 |
| slug                               | varchar(40), unique                                             | Used for the platform subdomain; rules below |
| status                             | enum `provisioning`, `active`, `suspended`, `closing`, `closed` |                                              |
| cell_id                            | FK cells                                                        |                                              |
| timezone                           | varchar(40)                                                     | Default `America/Chicago`                    |
| locale                             | enum `en`, `es`                                                 | Default `en`                                 |
| state_code                         | char(2) nullable                                                | Primary US state of operation                |
| suspended_reason                   | varchar(255) nullable                                           |                                              |
| closed_at, purge_after             | datetime nullable                                               |                                              |
| created_by_user_id                 | bigint nullable                                                 |                                              |
| created_at, updated_at, deleted_at |                                                                 | Soft delete only via closing flow            |

### `tenant_domains` (connection: control)

| Column               | Type                                 | Notes                                        |
| -------------------- | ------------------------------------ | -------------------------------------------- |
| id, ulid, tenant_id  |                                      |                                              |
| host                 | varchar(253), unique                 | Lower-cased, no port, no trailing dot        |
| kind                 | enum `platform`, `custom`            | `platform` = `{slug}.{CORE_PLATFORM_DOMAIN}` |
| owner_user_id        | bigint nullable                      | Reserved for agent domains (Team C)          |
| is_primary           | bool                                 | One primary per tenant                       |
| verification_status  | enum `pending`, `verified`, `failed` | Spec 05                                      |
| ssl_status           | enum `pending`, `active`, `failed`   | Spec 05                                      |
| provider_hostname_id | varchar(100) nullable                | Cloudflare custom hostname ID                |
| verified_at          | datetime nullable                    |                                              |

## Slug rules

- 3–40 characters, `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$`, no consecutive hyphens.
- Reserved list (cannot be used): `www`, `app`, `api`, `admin`, `hq`, `mail`, `support`, `help`,
  `status`, `docs`, `blog`, `cdn`, `static`, `assets`, `auth`, `login`, `billing`, `rightally`,
  plus any configured in `config/core.php`.
- Changing a slug keeps a redirect from the old platform host for 90 days.

## Tenant resolution

1. Middleware `ResolveTenant` runs first on every web request. It normalises the host and looks
   it up in `tenant_domains` (cached in Redis for 5 minutes, key `core:domain:{host}`,
   invalidated on any domain change).
2. The platform host (`CORE_HQ_HOST`, e.g. `app.rightally.io`) resolves to the HQ tenant.
3. Unknown host → neutral 404 page with no tenant information. Tenant `suspended` → login allowed
   only to show a suspension notice and billing page. `closing`/`closed` → closed notice.
4. The resolved tenant is stored in the `TenantContext` singleton for the request.
5. API requests (later sprints) resolve the tenant from the token, and the token's tenant must
   match the host if a tenant host is used.

## TenantContext service

- `current(): Tenant` (throws `MissingTenantContext`), `id()`, `check(): bool`.
- `runAs(Tenant $tenant, Closure $callback)` for CLI, scheduled jobs and tests; restores the
  previous context afterwards.
- **Queued jobs:** a job middleware captures `tenant_id` when dispatched and restores the context
  when handled. Jobs dispatched without a tenant must be marked `#[PlatformJob]`.
- **Cache:** tenant cache helper prefixes keys with `t:{tenant_id}:`.
- **Storage:** tenant disk prefixes paths with `tenants/{tenant_ulid}/`.
- **Logs:** every log line carries `tenant_id` and `request_id`.

## BelongsToTenant trait

- Adds a global scope `where tenant_id = current tenant`.
- On `creating`, fills `tenant_id` from the context; throws if there is none or if a different
  tenant ID was set.
- Prevents changing `tenant_id` on update.
- `withoutTenantScope()` is allowed only inside classes marked `#[CrossTenantAccess]`
  (HQ reporting, migrations); each use writes an audit event.
- Route model binding resolves only within the current tenant, so `/users/{ulid}` from another
  tenant returns 404.

## Provisioning

Action `ProvisionTenant` (used by signup in Sprint 28–30, HQ console and tests):

1. Input: type, name, desired slug, owner email and name, optional cell, idempotency key.
2. Validates slug; picks the active cell with the most spare capacity (or the given cell).
3. In one transaction per connection: creates the tenant (`provisioning`), its platform domain
   (primary), default settings (spec 05), system roles from templates (spec 03), the onboarding
   workflow template copy (spec 06), and an owner invitation (spec 02).
4. Sets status `active`, emits `TenantProvisioned`, and sends the owner invitation.
5. Repeating the call with the same idempotency key returns the same tenant.
6. Target: under 10 seconds end to end.

## Suspend, reactivate, close

| Action     | Who                          | Effect                                                                                                              |
| ---------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Suspend    | HQ (billing or support role) | Users see a suspension notice; owner can reach billing; data untouched                                              |
| Reactivate | HQ                           | Normal access restored                                                                                              |
| Close      | Tenant owner or HQ           | Data export offered; status `closing` for 30 days (reversible); then `closed`; purge after 90 days by scheduled job |

Every change is audited with reason.

## Screens (this spec)

- HQ console → Tenants list (search, filter by type, status, cell) and tenant detail (domains,
  status, cell, created, owner) with Suspend / Reactivate / Close actions.
- Neutral 404 and suspended/closed notice pages.

## Domain events

`TenantProvisioned`, `TenantSuspended`, `TenantReactivated`, `TenantClosing`, `TenantClosed`,
`TenantSlugChanged`.

## Audit events

`core.tenant.provisioned`, `core.tenant.suspended`, `core.tenant.reactivated`,
`core.tenant.closing`, `core.tenant.closed`, `core.tenant.slug_changed`,
`core.tenant.cross_tenant_access` (each `withoutTenantScope` use).

## Cross-tenant test harness

- Base test helper creates tenants A and B with users, roles and sample records via factories.
- `assertTenantIsolation(Model::class)` checks list, show, update, delete and search from B
  cannot touch A's records.
- Architecture test: every Eloquent model in `Modules\*` whose table has `tenant_id` uses
  `BelongsToTenant`; every migration creating such a table uses `$table->tenant()`.

## Edge cases

- Host with uppercase or trailing dot → normalised.
- Request to a platform host for a slug that was changed → 301 to the new host (90 days).
- Job processed after its tenant was suspended → runs only if marked `#[RunsWhenSuspended]`
  (billing, notices); otherwise released and logged.
- Two simultaneous provisioning requests for the same slug → one succeeds, the other gets a
  validation error (unique index).

## Acceptance criteria

1. A request to `{slug}.{platform domain}` resolves the correct tenant; an unknown host shows the
   neutral 404 page.
2. Creating any tenant-owned record without a tenant context throws.
3. Tenant B cannot list, view, edit, delete or search tenant A's records (harness passes for
   every tenant-owned model shipped).
4. Queued jobs run with the tenant they were dispatched under.
5. `ProvisionTenant` creates tenant, domain, settings, roles, workflow template and owner
   invitation in under 10 seconds, and is idempotent.
6. Suspend, reactivate and close behave as specified and are audited.
7. Architecture tests enforce trait and migration-macro use.

## Out of scope

Moving tenants between cells (designed for, built in Sprint 50–52), dedicated cells, agent domains.
