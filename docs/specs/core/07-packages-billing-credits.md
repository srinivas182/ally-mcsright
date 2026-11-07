# 07 — Packages, billing and RightAlly Credits (Team A, Sprints 11–13)

## Purpose

HQ builds packages and add-ons for brokerages and solo agents, sees what they cost RightAlly and
the margin, and sells them through Stripe. Tenants subscribe, add seats and add-ons, pay invoices
and buy credits. RightAlly Credits pay for AI and other usage, priced per action, with allowances
that brokerages pass down to agent plans.

Sprint split: **11** packages and HQ package builder; **12** Stripe subscriptions, entitlement
sync, tenant billing page; **13** credits ledger, rate card, allowances, top-ups, margin view.

## Packages and add-ons (control)

### `packages`

| Column                                                          | Notes                                                                        |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| id, ulid, family_key, version                                   | A package family has versions; subscribers stay on their version until moved |
| audience                                                        | `brokerage` or `solo`                                                        |
| name, slug, description, highlight (marketing bullet list json) |                                                                              |
| status                                                          | `draft`, `pending_approval`, `published`, `retired`                          |
| billing_interval                                                | `month` or `year`                                                            |
| base_price_cents, setup_fee_cents                               |                                                                              |
| included_seats, seat_price_cents, min_seats, max_seats nullable | Agent seats                                                                  |
| assistant_seat_price_cents nullable                             | Solo and brokerage assistants                                                |
| included_credits_monthly                                        |                                                                              |
| credit_pool                                                     | `tenant` (shared pool) or `per_seat`                                         |
| max_custom_domains                                              | Spec 05                                                                      |
| trial_days                                                      |                                                                              |
| stripe_product_id, stripe_price_ids (json)                      | Created on publish                                                           |
| created_by, approved_by, published_at, retired_at               |                                                                              |

### `package_modules`

`package_id, module_key, seat_based (bool)` — modules included in the package.

### `addons`

`id, ulid, kind (module, seats, assistant_seat, credit_pack, domain), module_key nullable,
name, price_cents, interval (month, year, one_time), credits nullable, audience, status,
stripe_price_id`.

### Approval

Package and add-on changes go through the approval rule `hq.packages.publish` (spec 03):
draft → pending approval → published. Published versions are immutable; edits create a new
version. Retiring hides a package from signup but existing subscribers keep it.

## HQ package builder

- List packages by audience and status; create from blank or copy.
- Editor sections: details, modules (checklist grouped by suite, seat-based flag), pricing
  (base, setup fee, seats, assistant seats, interval, trial), credits (monthly included, pool
  type), limits (custom domains).
- **Margin panel** (live as you edit):
    - Estimated monthly cost = included credits × weighted provider cost per credit (from the rate
      card and the last 90 days' usage mix) + per-seat infrastructure cost (HQ setting).
    - Revenue per month at minimum seats and at 25 seats.
    - Margin % with a warning below the HQ threshold (default 70%).
- Submit for approval; approver sees a diff against the previous version.

## Subscriptions and Stripe (Laravel Cashier, Tenant as billable)

### `tenant_subscriptions` (control)

`id, tenant_id, package_id, status (trialing, active, past_due, paused, cancelled),
stripe_customer_id, stripe_subscription_id, seats, current_period_start, current_period_end,
trial_ends_at, cancel_at, cancelled_at, timestamps` plus Cashier's subscription item tables for
add-ons.

Rules:

- Seat changes prorate. Seats cannot go below assigned seats; releasing seats first is required.
- Upgrades apply immediately (prorated); downgrades apply at period end and are blocked if the
  tenant uses more domains or seats than the target allows.
- Setup fee charged on first invoice.
- Dunning: Stripe smart retries plus RightAlly emails at failure, day 3 and day 6; `past_due`
  for 7 days (HQ setting), then the tenant is suspended (spec 01) except billing.
- Cancellation at period end; data retained per the closing flow.
- **Webhooks:** signature verified; each Stripe event ID stored in `stripe_events` and processed
  once (idempotent); out-of-order events handled by re-fetching the subscription.

### Entitlement sync

Any subscription change recomputes `tenant_entitlements` (spec 03) from the package modules and
active add-ons, within one minute, and emits `EntitlementsChanged`.

## RightAlly Credits

### Rate card (control)

`credit_rate_card`: `id, action_key (e.g. ai.listing_description, sms.outbound, voice.minute,
enrich.lookup, video.tour), category, credits, provider_cost_cents_estimate, included_fair_use
(bool), fair_use_monthly_per_seat nullable, version, active_from, active_to, created_by`.
HQ edits create a new version effective from a date; history kept.

Retail value of a credit (e.g. 1 credit = $0.01) is an HQ setting used for packs and margins.

### Accounts and ledger (cell)

`credit_accounts`: `id, tenant_id, owner_type (tenant, user), owner_id, cached_balance,
lock_version`.

`credit_ledger`:

| Column                          | Notes                                                                                                            |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| id, ulid, tenant_id, account_id |                                                                                                                  |
| entry_type                      | `grant_included`, `purchase`, `allocate_out`, `allocate_in`, `consume`, `fair_use`, `refund`, `expire`, `adjust` |
| amount                          | Signed integer credits (`fair_use` entries are 0 and record usage only)                                          |
| bucket                          | `included` or `purchased`                                                                                        |
| expires_at                      | Included: end of billing period; purchased: 12 months                                                            |
| action_key, quantity            | For consumption                                                                                                  |
| provider_cost_cents             | Actual cost reported by the AI gateway or provider                                                               |
| reference_type, reference_id    | e.g. AI request ID, SMS ID                                                                                       |
| idempotency_key                 | Unique                                                                                                           |
| created_by, created_at          |                                                                                                                  |

Balances are the sum of ledger entries; `cached_balance` is updated with optimistic locking and
reconciled nightly. Entries are never updated or deleted; corrections are `adjust` entries.

### Charging (`CreditMeter::charge`)

Inputs: tenant, user, action key, quantity, reference, idempotency key.

1. If the action is `included_fair_use` and the user is under the fair-use limit this month →
   write a `fair_use` entry (0 credits) and allow.
2. Otherwise draw from the **user's allowance account**, then (if the brokerage allows) the
   **tenant pool**.
3. Within an account, consume `included` before `purchased`, earliest expiry first.
4. Not enough credits → `InsufficientCredits` with a friendly message and, where allowed, a
   one-click top-up.
5. Same idempotency key → same result, never double charged.

Target: under 10 ms p95.

### Allowances

`credit_allowances` (cell): `id, tenant_id, target_type (plan, role, user), target_id,
monthly_credits, monthly_cap, can_self_topup (bool)`. Agent plans come from the Compensation
module (Team B) and are referenced by ID. A monthly job moves allowances from the tenant pool to
user accounts (`allocate_out`/`allocate_in`) at the period start; if the pool runs short, it
allocates proportionally and alerts the owner.

### Top-ups and auto-recharge

- Credit packs (add-ons of kind `credit_pack`) bought by card through Stripe; credits granted on
  payment success (webhook, idempotent).
- Auto-recharge per account: threshold, pack, monthly maximum spend.
- Low-balance alerts at 20% and 5% of the month's starting balance.
- Agents can buy packs for their own account only if their allowance has `can_self_topup`.

### Expiry

Nightly job writes `expire` entries for included credits at period end and purchased credits at
12 months.

## Screens

**HQ:** package list and builder (with margin panel and approval), add-ons, rate card editor
(versions), credit settings (credit value, margin threshold), usage and margin dashboard (provider
spend vs credits by tenant, feature and model; top consumers).

**Tenant (owner/accountant):** Billing (package, seats, add-ons, next invoice, invoices with PDF,
payment method, change plan, cancel); Credits (pool and account balances, usage by feature and by
user over time, buy packs, auto-recharge, allowances by plan/role/user).

**Agent/assistant:** My credits (allowance, used this month, by feature; buy more if allowed).

## Permissions

Tenant: `core.billing.view`, `core.billing.manage`, `core.credits.view`, `core.credits.manage`,
`core.credits.self_topup`. HQ: `hq.packages.manage`, `hq.packages.approve`,
`hq.ratecard.manage`, `hq.billing.manage`.

## Events

`PackagePublished`, `PackageRetired`, `SubscriptionStarted`, `SubscriptionChanged`,
`SubscriptionPastDue`, `SubscriptionCancelled`, `EntitlementsChanged`, `CreditsPurchased`,
`CreditsConsumed` (aggregated per minute for analytics), `CreditsLow`, `CreditsExhausted`,
`AllowancesAllocated`.

## Edge cases

- Stripe webhook delayed: the billing page shows "processing" and re-syncs on refresh.
- Rate card changes mid-month apply to new charges only.
- Refund of a credit pack: only unused purchased credits can be refunded; ledger `refund` entry.
- Tenant suspended: consumption blocked except actions marked essential (e.g. sending a password
  reset email).
- A plan's allowance changed mid-month: difference allocated or reclaimed pro rata the next day.

## Acceptance criteria

1. HQ can build, approve and publish brokerage and solo packages; the margin panel matches a
   hand-calculated example within 1%.
2. Subscribing in Stripe test mode creates entitlements within one minute; seat and add-on
   changes prorate correctly; downgrades are blocked when limits would be exceeded.
3. Dunning follows the schedule and suspends after the grace period.
4. Webhooks are verified and idempotent (replaying an event changes nothing).
5. `CreditMeter::charge` follows the fair-use → user → pool, included → purchased order, never
   double charges, and handles insufficient credits gracefully.
6. Allowances allocate monthly from the pool; proportional allocation and alert when short.
7. Purchases, auto-recharge, low-balance alerts and expiry work as specified.
8. All credit movements are ledger entries, visible by feature and user, and audited.
