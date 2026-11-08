# 02 — Identity and login (Team A, Sprints 3–4)

## Purpose

Brokers, staff, agents and assistants sign in on their brokerage's own domain and land on the
screens their role allows. HQ staff sign in on the HQ host. Security is strong by default without
getting in the way: 2FA is optional unless a tenant (or HQ) requires it.

## Delivery

| Sprint | Delivered                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3A     | Tenant-scoped users and per-tenant sign-in; landing by kind; neutral errors, lockout per host+email+IP, alert after 20 failures/hour; 12-character minimum and breached-password check; per-tenant password-reset tokens; self-registration and self-deletion removed; invitations (invite, resend, revoke, accept, 7-day expiry); users admin (suspend, deactivate, reactivate, last-owner protection, assistants follow their agent); sign-out when deactivated or when a session reaches another tenant's host; interim access: owners manage users and use finance, HQ staff use the HQ console |
| 4A     | 2FA policy (required for HQ staff), passkeys policy, Google/Microsoft sign-in, active sessions screen, impersonation, email change with re-verification                                                                                                                                                                                                                                                                                                                                                                                                                                             |

## Decisions

- **Users are tenant-scoped.** `users` has `tenant_id`; email is unique per tenant, not globally.
  The same person can hold accounts in two tenants (e.g. HQ staff and a test brokerage). A solo
  agent folding into a brokerage is a data move, not a shared account.
- Built on the starter kit's Fortify setup (login, registration off for tenants, password reset,
  email verification, 2FA, passkeys).
- Users **cannot delete their own account** (decided in Sprint 3A): owners deactivate users, so
  deals, commissions and audit history are never orphaned.
- Self-registration is **off** for brokerage and HQ tenants; people join by invitation or through
  workflows (agent onboarding, spec 06). Solo signup (Sprints 34–37) uses provisioning.

## Data model

### `users` (cell)

| Column                                                                | Type                                                    | Notes                                            |
| --------------------------------------------------------------------- | ------------------------------------------------------- | ------------------------------------------------ |
| id, ulid, tenant_id                                                   |                                                         |                                                  |
| kind                                                                  | enum `hq_staff`, `owner`, `staff`, `agent`, `assistant` | Drives landing page; permissions come from roles |
| first_name, last_name                                                 | varchar(80)                                             |                                                  |
| email                                                                 | varchar(254)                                            | Unique with tenant_id; lower-cased               |
| email_verified_at                                                     | datetime nullable                                       |                                                  |
| phone                                                                 | varchar(20) nullable                                    | E.164                                            |
| password                                                              | varchar nullable                                        | Null for SSO-only users                          |
| status                                                                | enum `invited`, `active`, `suspended`, `deactivated`    |                                                  |
| locale                                                                | enum `en`, `es` nullable                                | Falls back to tenant                             |
| timezone                                                              | varchar(40) nullable                                    | Falls back to tenant                             |
| two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at | Fortify columns                                         | Encrypted                                        |
| assisted_user_id                                                      | bigint nullable                                         | For `assistant`: the agent they work for         |
| last_login_at, last_active_at                                         | datetime nullable                                       | `last_active_at` updated at most every 5 minutes |
| created_by, updated_by                                                | bigint nullable                                         |                                                  |
| timestamps, deleted_at                                                |                                                         |                                                  |

### `invitations` (cell)

| Column                  | Notes                                                                     |
| ----------------------- | ------------------------------------------------------------------------- |
| id, ulid, tenant_id     |                                                                           |
| email, kind             |                                                                           |
| role_ids                | json array of role IDs to assign on acceptance                            |
| assisted_user_id        | for assistant invitations                                                 |
| invited_by              | user ID                                                                   |
| token_hash              | sha256 of a 40-character random token; the raw token is only in the email |
| expires_at              | 7 days                                                                    |
| accepted_at, revoked_at |                                                                           |

### `user_sessions` (cell)

Tracks devices for the "active sessions" list: `id`, `tenant_id`, `user_id`, `session_id_hash`,
`ip`, `user_agent`, `device_label`, `created_at`, `last_seen_at`, `revoked_at`.

### `impersonations` (cell)

`id`, `tenant_id`, `impersonator_id`, `impersonator_tenant_id` (HQ support may be another tenant),
`target_user_id`, `reason`, `consent_reference` (for HQ support), `started_at`, `ended_at`,
`expires_at` (60 minutes).

## Login and landing

| User kind        | Login at                  | Lands on                |
| ---------------- | ------------------------- | ----------------------- |
| `hq_staff`       | HQ host                   | HQ console dashboard    |
| `owner`, `staff` | Brokerage domain `/login` | Back-office dashboard   |
| `agent`          | Brokerage domain `/login` | Agent dashboard         |
| `assistant`      | Brokerage or solo domain  | Their agent's workspace |

Rules:

- Email + password, with "remember me" (30 days).
- Rate limit: 5 failed attempts per email + IP per minute, then a 1-minute lock with a neutral
  message. 20 failures per account per hour triggers an alert (spec 04).
- Error messages never reveal whether an email exists.
- `suspended`/`deactivated` users cannot log in; `invited` users must accept their invitation.
- **SSO:** Google and Microsoft via Socialite. SSO signs in an existing active user whose verified
  email matches in this tenant; it never creates users unless an open invitation exists.
- **Password policy:** minimum 12 characters, checked against known breached passwords; reset
  links valid 60 minutes and single use.

## Two-factor and passkeys

- Tenant setting `security.two_factor_policy`: `optional` (default), `required_for_roles` (list of
  role IDs), `required_all`.
- HQ tenant: always `required_all` for `hq_staff`.
- When required and not set up, the user is sent to set up 2FA immediately after password login.
- TOTP with recovery codes (Fortify). Passkeys behind flag `core.passkeys` (on by default).
- Owners/admins with `core.users.manage` can reset another user's 2FA (audited, user notified).

## Invitations

- Admin invites by email with kind and roles (and, for assistants, the agent they assist).
- Re-sending creates a new token and revokes the old one.
- Accepting: user sets name and password (or links Google/Microsoft), email is marked verified,
  roles assigned, status `active`, `UserActivated` emitted.
- Expired or revoked links show a friendly message and a "request a new invitation" option.

## Sessions

- Users see active sessions (device, browser, location from IP country/city, last seen) and can
  sign out any other session or all others.
- Admins can sign a user out of all sessions (e.g. lost phone).
- Changing a password signs out all other sessions.

## Impersonation ("log in as")

| Who                                              | May impersonate                              | Conditions                                                             |
| ------------------------------------------------ | -------------------------------------------- | ---------------------------------------------------------------------- |
| Tenant owner/admin with `core.users.impersonate` | Users in their tenant with fewer permissions | Reason required                                                        |
| HQ support with `hq.support.impersonate`         | Any user in a tenant                         | Consent reference from the tenant (support ticket) and reason required |

- Lasts at most 60 minutes; a banner shows "Viewing as … — End".
- While impersonating, these are blocked: changing password, 2FA, passkeys, email; billing and
  payouts; deleting users; exporting data.
- Start and end are audited; tenant owners see HQ support sessions (spec 04).

## Screens

- Login, forgot password, reset password, 2FA challenge, passkey prompt (from starter kit,
  restyled later by the design system).
- Accept invitation.
- Profile and security settings: name, email change (re-verification), password, 2FA, passkeys,
  active sessions.
- Users (admin): list with search and filters (kind, status, role, office), invite user,
  resend/revoke invitation, user detail (status, roles, sessions, 2FA status), suspend,
  reactivate, deactivate, reset 2FA, sign out everywhere, impersonate.

## Permissions

`core.users.view`, `core.users.invite`, `core.users.manage` (status, 2FA reset, sessions),
`core.users.impersonate`, `core.users.export`; HQ: `hq.support.impersonate`.

## Domain and audit events

`UserInvited`, `InvitationResent`, `InvitationRevoked`, `UserActivated`, `UserLoggedIn`,
`LoginFailed`, `UserLockedOut`, `PasswordChanged`, `PasswordReset`, `TwoFactorEnabled`,
`TwoFactorDisabled`, `TwoFactorReset`, `SessionRevoked`, `UserSuspended`, `UserReactivated`,
`UserDeactivated`, `ImpersonationStarted`, `ImpersonationEnded`. Each maps to a `core.user.*` or
`core.auth.*` audit action.

## Edge cases

- Invitation accepted in a different browser session than the inviter's tenant → still resolves by
  host, so the user can only accept on the right domain.
- User deactivated while logged in → next request signs them out.
- Assistant whose agent is deactivated → assistant suspended automatically and owner notified.
- Email change to one already used in the tenant → validation error.

## Acceptance criteria

1. Users can only sign in on their own tenant's domain; the same email in another tenant is a
   separate account.
2. Landing page matches user kind.
3. Lockout, neutral errors and breached-password checks work as specified.
4. 2FA policy `required_for_roles` forces setup only for those roles; HQ staff always need 2FA.
5. SSO signs in existing users only.
6. Invitations expire after 7 days; resend revokes the old link.
7. Users can see and revoke sessions; password change signs out other sessions.
8. Impersonation obeys the who/conditions table, shows the banner, blocks the listed actions, and
   is audited start and end.
9. Every listed event appears in the audit log.

## Out of scope

API tokens (Sprint 23–24), client portal users (Team C), agent self-signup flow (spec 06 template

- Sprint 16–17).
