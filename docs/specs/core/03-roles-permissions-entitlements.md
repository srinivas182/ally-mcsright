# 03 — Roles, permissions and entitlements (Team A, Sprints 5–6)

Status: **Part 1 delivered (Sprint 8A)**: offices, teams, team members and office membership; roles with
real estate templates (Owner locked); a code-registered permission catalogue with plain-English labels, scopes and
sensitive flags; the permission engine behind every registered gate (owner everything; active unexpired grants;
broadest scope wins; own/team/office/everyone record checks); CRM company-contact access widened by role scope
(never personal spheres); temporary access with end dates (removed hourly by `core:expire-access`); separation of
duties refused in a role, across roles and at every check; role editor with live plain-English preview; offices and
teams page; role assignment on the People screen; all changes audited.

**Part 2 delivered (Sprint R1, 2026-11-16)**: see "R1: roles that cover every area" below.

**Part 3 delivered (Sprint FL1, 2026-11-18)**: the area registry with switches and seats, and approval rules with up to
three approvers; see spec 16.

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

## Approved additions (version 1.0; leader review: BoldTrail, Lone Wolf, Brokermint, Follow Up Boss)

1. **Ready-made real estate roles**: owner, managing broker, office admin, compliance reviewer, transaction
   coordinator, team lead, agent, assistant, read-only bookkeeper; copy and adjust.
2. **Scopes**: everyone, this office, this team, own records — extending the CRM privacy rule.
3. **Field-level hiding**: e.g. an office admin sees deals but not commission amounts or bank details.
4. **Plain-English editor**: "Can approve transactions in the Downtown office", grouped by area.
5. **Preview as this role** before saving.
6. **Expiring access**: temporary roles or grants with an end date (holiday cover), removed automatically.
7. **Separation of duties**: conflicting permissions (change bank details + approve payouts) cannot be held
   together; enforced on save and on every check.
8. **Quarterly access review**: owners confirm who has what in one screen; attestations audited (SOC 2).

## R1: roles that cover every area (delivered)

Leader review (November 2026): Brokermint and BoldTrail Back Office (permission settings per role, office access),
Rechat and Glide brokerage editions (admin roles per office), HubSpot (permission sets, team and owned-only access,
field-level view and edit limits), Follow Up Boss (simple fixed roles). None of them explains access in plain
English, previews the result, or runs an access review. R1 does all three.

1. **Roles bite everywhere.** One manager check, `Can::manage($user, $permission)` (`Permissions::manages`), replaces the
   "owner or staff" checks across modules:
    - **Owners** always pass.
    - **Staff with no role** keep the access their type started with, so nothing changes until an owner assigns roles.
    - **Once a staff member holds a role**, their access is decided by roles alone (`Permissions::governed`).

    New permissions cover the areas that had none:

    | Permission               | Covers                                                                                                               |
    | ------------------------ | -------------------------------------------------------------------------------------------------------------------- |
    | `sites.manage`           | websites, AIlly settings, reviews, live chat take-over                                                               |
    | `marketing.manage`       | Ally Studio and Autopilot, social posts, listing ads, funnels, buyer demand, company email and GoHighLevel campaigns |
    | `apps.manage`            | brokerage connections                                                                                                |
    | `billing.credits.manage` | buying credits                                                                                                       |
    | `core.access.review`     | the access review and the who-can-do-what export (sensitive)                                                         |

    These now go through the same check: roster (`roster.manage`), forms (`forms.manage`), deals (`transactions.review`)
    and CRM settings (`crm.settings.manage`). Menu items follow the same rules.

2. **Company contacts by scope.** A role-governed staff member sees their own contacts plus the company contacts their
   role's scope allows (team, office or everyone), instead of every company contact.
3. **Field hiding** (`Fields`): commission amounts need `finance.amounts.view`, and bank details need
   `finance.bank_details.manage`. People always see their own figures, and assistants see their agent's. Deal pages show
   "Hidden for your role", and the pending-commission forecast is hidden too.
4. **More ready-made roles:** ISA (inside sales), marketing manager, recruiter, lender partner (own contacts only,
   never money) and accountant. They join managing broker, office admin, compliance reviewer, transaction coordinator,
   team lead, agent, assistant and read-only bookkeeper. Templates now include the deal, roster and forms permissions.
   Brokerages that started earlier get a one-click "Add …" for each ready-made role they don't have yet.
5. **Preview as this role:** the role editor shows the exact menu someone with the role would get, and the fields
   hidden from them, for unsaved edits too. A pretend person runs through the real menu rules; nothing is saved.
6. **"Why can I see this?"** on contacts and deals explains, in plain English, which role and scope give access, for
   example: "Your role Office admin lets you see company contacts for your office. Lee Torphy is in your office." It
   also says when access ends, and when commission is hidden.
7. **Risky-change alert.** The other owners are emailed the moment:
    - someone is given a role with sensitive permissions, people management, roles, payouts, ledger changes,
      exports, CDAs, apps or credits;
    - or a role someone already holds gains such access.
8. **Quarterly access review** (Settings → Access review):
    - It lists every role each person holds, risky ones first, with what it gives, the last sign-in (dormant after
      90 days), and when the access ends.
    - Owners keep or remove each line. A removal is revoked at once and audited.
    - Sign-off is password-confirmed and needs every line decided. It is audited as the attestation.
    - "Export who can do what" is a password-confirmed CSV with one row per person and permission (spreadsheet
      formulas neutralised), and is audited.
    - The roles page flags a review that is due. `core:access-review-reminder` emails owners on Mondays from two
      weeks into each quarter until the review is signed off.

Tests: `tests/Feature/Core/Access/RolesR1Test.php`.
