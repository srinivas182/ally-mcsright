# 03 — Roles, permissions and entitlements (Team A, Sprints 5–6)

## Purpose

One permission engine for RightAlly HQ and every brokerage. Brokerages create staff roles and
control access module by module, action by action, with a scope. Modules released later appear in
every role editor automatically, with no access until granted. Access to a module also requires
the tenant to be entitled to it (package or add-on) and, for seat-based modules, the user to hold
a seat.

## Access check

A user may perform `{module}.{resource}.{action}` on a record when **all** are true:

1. The tenant has an active entitlement for the module (Core is always entitled).
2. If the module is seat-based, the user holds a seat for it.
3. One of the user's active roles grants the permission.
4. The record is within the granted scope (`own`, `team`, `office`, `tenant`).
5. For sensitive data, the matching sensitive permission is granted (otherwise values are masked).

The **Owner** role is locked and always has every permission in modules the tenant is entitled
to. There is no "super user" that crosses tenants: HQ permissions apply only inside the HQ
tenant and HQ console features.

## Organisation structure

### `offices` (cell)

`id, ulid, tenant_id, name, address_line1, address_line2, city, state_code, postal_code, phone,
licence_number, timezone nullable, is_primary, timestamps, deleted_at`. One primary office is
created at provisioning.

### `teams` (cell)

`id, ulid, tenant_id, office_id nullable, name, leader_user_id nullable, timestamps, deleted_at`.

### `team_members` (cell)

`team_id, user_id, joined_at`. A user can be in several teams.

### `user_offices` (cell)

`user_id, office_id, is_primary`. Defines the offices a user belongs to (scope `office`).

## Module registry

### `modules` (control)

| Column      | Notes                                                      |
| ----------- | ---------------------------------------------------------- |
| key         | e.g. `core`, `crm`, `idx` (from `module.json` id)          |
| name, suite | Display; suite is `core`, `grow`, `run`, `protect`, `know` |
| version     | From manifest                                              |
| is_core     | Always entitled                                            |
| seat_based  | Whether per-user seats are required                        |
| released_at | When the module first became available                     |

Synced from manifests by `php artisan core:sync-modules` during each release.

### `permissions` (control)

| Column                       | Notes                                                                              |
| ---------------------------- | ---------------------------------------------------------------------------------- |
| key                          | `{module}.{resource}.{action}`                                                     |
| module_key, resource, action |                                                                                    |
| scoped                       | Whether a scope applies                                                            |
| sensitive                    | e.g. `core.sensitive.ssn.view`, `core.sensitive.bank.view`, `finance.amounts.view` |
| label, description           | Shown in the role editor                                                           |
| introduced_at                | Used for "New" badges                                                              |

Each module provider registers its permissions through `PermissionRegistry::register()`; the
release runs `php artisan core:sync-permissions`. Removing a permission requires a deprecation
step (kept but hidden for one release).

## Roles

### `roles` (cell)

`id, ulid, tenant_id, key (unique per tenant), name, description, template_key nullable,
is_locked (Owner), is_system (created from a template; editable except Owner), last_reviewed_at,
created_by, updated_by, timestamps, deleted_at`.

### `role_permissions` (cell)

`role_id, permission_key, scope nullable` (scope required when the permission is scoped).

### `user_roles` (cell)

`user_id, role_id, granted_by, granted_at, expires_at nullable` (temporary access). Expired grants
are ignored and cleaned up nightly with an audit event.

### Templates (seeded per tenant type)

| Tenant type | Role templates                                                                                                                                                           |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| HQ          | Super admin (locked), Package manager, Sales, Billing, Onboarding specialist, Support, Content editor, Release manager                                                   |
| Brokerage   | Owner (locked), Admin, Office manager, Transaction coordinator, Accountant, Marketing manager, Content editor, Compliance reviewer, Recruiter, Support, Team lead, Agent |
| Solo        | Owner (locked), Assistant presets: Full, Lead / ISA, Marketing, Admin                                                                                                    |

Template permission sets are defined in code (`RoleTemplates`) and only seeded at provisioning;
later template changes never overwrite a tenant's edited roles.

## Scope resolution

Records that support scoping implement `ScopedResource`:
`ownerUserId(): ?int`, `teamIds(): array`, `officeId(): ?int`.

| Scope    | Allowed when                                                      |
| -------- | ----------------------------------------------------------------- |
| `own`    | Record owner is the user (or, for assistants, their agent)        |
| `team`   | Record owner is in one of the user's teams (or owner is the user) |
| `office` | Record office is one of the user's offices                        |
| `tenant` | Any record in the tenant                                          |

List queries apply the scope as a query constraint (not post-filtering), so pagination and search
stay correct.

## Entitlements and seats

### `tenant_entitlements` (control)

`id, tenant_id, module_key, source (package, addon, trial, manual), seats nullable, starts_at,
ends_at nullable, status (active, expired, suspended), reference (subscription item)`.
Written by billing (spec 07) or manually by HQ (audited).

### `seat_assignments` (cell)

`id, tenant_id, module_key, user_id, assigned_by, assigned_at, released_at`. Assigning beyond the
entitled seat count is blocked with a prompt to buy more seats.

Middleware `module:{key}` blocks routes for modules the tenant is not entitled to (shows an
"available in your plan upgrade" page to admins; a neutral 404 to others).

## Approval rules

Generic primitive used by finance, packages and others.

### `approval_rules` (cell)

`id, tenant_id, action_key (e.g. finance.payout.approve), condition (json: e.g.
{"amount_cents_gt": 1000000}), approvals_required (1–3), approver_permission, active`.

### `approvals` (cell)

`id, tenant_id, rule_id, subject_type, subject_id, requested_by, status (pending, approved,
rejected), decided_at`, plus `approval_votes (approval_id, user_id, decision, comment, voted_at)`.
The requester can never approve their own request.

## Screens

- **Offices and teams:** list, create, edit, archive; assign members and leaders.
- **Roles:** list (with user counts, "New" badge when modules or permissions were added since
  `last_reviewed_at`), create from blank or copy from a template, edit.
- **Role editor:** rows grouped by suite → module → resource; columns for actions; each cell a
  checkbox, with a scope selector where applicable; sensitive permissions in a separate section
  with a warning; "Mark reviewed" clears New badges.
- **User access:** on the user detail page, assign roles with optional expiry; shows effective
  permissions (read-only).
- **Module access and seats:** entitled modules, seats used/available, assign/release seats,
  upgrade prompts.
- **Access review export:** who can do what, by user or by module (CSV/PDF).
- **Approval rules:** list and edit (owner/admin).

## Permissions

`core.offices.manage`, `core.teams.manage`, `core.roles.view`, `core.roles.manage`,
`core.access.export`, `core.seats.manage`, `core.approvals.manage`.

## Performance

Effective permissions per user are cached in Redis (`t:{tenant}:perm:{user}`) and invalidated on
any role, role-permission, user-role, team, office or entitlement change. The check adds under
1 ms per request on a warm cache.

## Domain and audit events

`RoleCreated`, `RoleUpdated` (with the permission diff), `RoleDeleted`, `RoleAssigned`,
`RoleRevoked`, `RoleGrantExpired`, `EntitlementGranted`, `EntitlementRevoked`, `SeatAssigned`,
`SeatReleased`, `ApprovalRequested`, `ApprovalDecided`, `OfficeCreated/Updated`,
`TeamCreated/Updated`, `TeamMemberAdded/Removed`.

Alert rule (spec 04): any grant of a sensitive permission or of `core.roles.manage` notifies the
owner.

## Edge cases

- Last user holding Owner cannot be removed or deactivated.
- A user cannot grant a permission they do not hold themselves (except the Owner).
- Deleting a role in use requires reassigning its users first.
- Entitlement ends → module hidden; data retained; seats released; nothing deleted.
- Scope `team` for a user in no team behaves like `own`.

## Acceptance criteria

1. All five access conditions are enforced on every route and list query.
2. A newly registered module's permissions appear in every role editor with no access granted
   (except Owner) and a New badge.
3. Templates seed correctly per tenant type; editing a template later does not change tenants.
4. Scoped lists return only records in scope and paginate correctly.
5. Seats cannot exceed the entitlement; releasing a seat frees it immediately.
6. Temporary access stops at `expires_at`.
7. Approval rules block the action until the required number of approvals from users other than
   the requester.
8. Access review export matches effective permissions.
9. All changes are audited; sensitive grants trigger the owner alert.
