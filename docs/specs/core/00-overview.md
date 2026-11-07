# Platform Core specification — overview and conventions

Scope: Team A, Sprints 1–13 (see `docs/plan/delivery-plan.md`). Platform Core is the foundation
every other module depends on. Nothing in a business module may bypass it.

| Spec                                                                           | Sprints (Team A) | Covers                                                                                                           |
| ------------------------------------------------------------------------------ | ---------------- | ---------------------------------------------------------------------------------------------------------------- |
| [01 Tenancy and cells](01-tenancy-and-cells.md)                                | 1–2              | Tenants, cells, domain resolution, tenant scoping, provisioning, cross-tenant tests                              |
| [02 Identity and login](02-identity-and-login.md)                              | 3–4              | Users, invitations, login on brokerage domains, SSO, optional 2FA, passkeys, sessions, impersonation             |
| [03 Roles, permissions and entitlements](03-roles-permissions-entitlements.md) | 5–6              | Offices and teams, roles, permission matrix with scopes, module registry, entitlements and seats, approval rules |
| [04 Audit log](04-audit-log.md)                                                | 7                | Append-only hash-chained audit, record history, search, export, alerts                                           |
| [05 Settings, branding and domains](05-settings-branding-domains.md)           | 8                | Typed tenant settings, brand palette generation, custom domains via CNAME                                        |
| [06 Workflow engine](06-workflow-engine.md)                                    | 9–10             | Versioned step workflows, instances, builder, monitor, onboarding template                                       |
| [07 Packages, billing and credits](07-packages-billing-credits.md)             | 11–13            | Packages and add-ons, Stripe subscriptions, RightAlly Credits ledger, rate card, allowances, margins             |

Specifications for Teams B, C and D's first sprints (ledger, design system and MLS ingestion,
AI gateway) follow in Sprint 0.4, before Sprint 1 starts.

## Conventions used in every spec

**Code location.** Platform Core code lives in `modules/Core/src`, namespace `Modules\Core\…`,
organised by area: `Tenancy`, `Identity`, `Access`, `Audit`, `Settings`, `Workflow`, `Billing`,
`Credits`. React pages live in `modules/Core/resources/js/pages`.

**Database connections.** Two logical connections from day one, pointing to the same database
locally and in the first cell, so they can split later without code changes:

- `control` — global control-plane data (cells, tenants, domains, packages, rate card, subscriptions).
- `cell` — tenant-owned data (users, roles, settings, workflows, credits, audit).

**Identifiers.** Internal `id` bigint; public `ulid` (26 chars) used in URLs, APIs, events and
exports. Never expose internal IDs.

**Tenant-owned tables.** Created with the migration macro `$table->tenant()` which adds
`tenant_id` (FK to `tenants`, indexed first in composite indexes). Models use `BelongsToTenant`.

**Money and credits.** Money in integer cents with a `currency` column (`USD`). Credits are
signed integers. Never floats.

**Time.** Stored in UTC (`datetime(6)` where ordering matters); displayed in the tenant's or user's
time zone.

**Naming.**

| Thing              | Pattern                         | Example             |
| ------------------ | ------------------------------- | ------------------- |
| Permission         | `{module}.{resource}.{action}`  | `core.users.invite` |
| Audit action       | `{module}.{entity}.{verb-past}` | `core.user.invited` |
| Domain event class | `{Entity}{VerbPast}`            | `UserInvited`       |
| Feature flag       | `{module}.{feature}`            | `core.passkeys`     |
| Route name         | `{module}.{resource}.{action}`  | `core.users.index`  |

**Actions.** Allowed permission actions: `view`, `create`, `edit`, `delete`, `approve`, `export`,
`manage`. Scopes where relevant: `own`, `team`, `office`, `tenant`.

**Errors.** Validation errors return field messages (Inertia) or 422 JSON. Authorisation
failures return 403 without revealing whether a record exists. Unknown tenant hosts return a
neutral 404 page.

**Feature flags.** Laravel Pennant, resolved per tenant, so features can roll out to pilot tenants
first.

**Events.** Every state change emits a domain event (past tense) dispatched after commit. Audit,
notifications and other modules listen to events; they never call each other's internals.

## Definition of done (every story)

1. Behaviour matches the spec's acceptance criteria.
2. Unit tests for rules; feature tests for every endpoint and screen action.
3. **Cross-tenant test** for every new tenant-owned model and endpoint: tenant B cannot read,
   list, update or delete tenant A's records, by ID or by search.
4. Permission tests: allowed role succeeds, other roles get 403.
5. Audit events written for every create, update, delete, approval, export and security change.
6. Pint, PHPStan level 8, front-end check and types pass locally before pushing.
7. Screens are responsive (mobile first), keyboard accessible, and meet WCAG 2.2 AA contrast.
8. CI green and the pull request merged.
