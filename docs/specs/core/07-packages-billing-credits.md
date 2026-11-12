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

## Brokerage-partner and agent-pays model (approved, version 1.0; ready before the pilot)

Delivery: **10A delivered** (module `modules/Billing`): HQ agent plans (public list price, brokerage floor,
features; floor never above list; increases reach brokerages after 60 days via price notices, decreases at
once) and commission tiers; brokerage settings (who pays: brokerage or agents; agents without a plan keep
the basics or must choose a plan; per-plan price between floor and list, offered, default; increases reach
existing agents after 30 days via `billing:apply-price-changes`, decreases at once; earnings preview =
commission on the floor + 100% markup); the **entitlements engine** (brokerage-pays and every existing
brokerage: no change; agent-pays: owners and staff get the package, agents and their assistants get their
plan's features while active or in grace, else minimal or blocked); `feature:` route guards on the CRM
(back office, AI, Today's 10); agents' Plans page at the brokerage price beside the public price (Plans menu
only under agent-pays); all changes audited. **10A-2 next**: payment gateway (Stripe; fake in tests), cards,
subscriptions, join flow, failed-payment grace, subsidies, leaving a brokerage, Stripe Tax. **10A-3**:
commissions, statements, Stripe Connect payouts, partner dashboard.

**Everyone pays RightAlly directly by card. The brokerage is never responsible for agents' fees.**

| Who                                 | Pays                                                     | Gets                                                                                                                                                                                 |
| ----------------------------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Brokerage                           | A small **flat fee**, monthly or yearly (yearly cheaper) | Full broker access to its package (back office, admin, compensation, compliance, websites, AIlly allowance)                                                                          |
| Agent, no plan                      | Nothing                                                  | The brokerage chooses: **minimal** (default: profile, commission statements, submitting deals and documents, announcements, required training) or **blocked** (only "choose a plan") |
| Agent, Basic                        | A small monthly fee                                      | The agent back office                                                                                                                                                                |
| Agent, upgrades (e.g. Pro, Premium) | More                                                     | The best tools: AI brief, Today's 10, Ask your database, Lead-IQ, Deal-IQ, Learn-IQ, own website with AIlly                                                                          |

### Who sets prices

- **HQ creates the agent plans** (features and **list price** — the public price on rightally.io for agents
  joining directly), the **floor price** for agents joining through a brokerage (list minus the partner
  discount, e.g. 40%), the **commission tiers** (20% for 1–24 paying agents, 25% for 25–99, 30% for 100+),
  the broker flat fee, and special deals per brokerage.
- **Each brokerage**, in its back office: which plans to offer and the default for new agents; **its price
  for each plan, anywhere between the floor and the list price** (never below the floor, never above the
  list price); optional subsidies (its card pays part or all of an agent's price, which may go below the
  floor) and first-month-free offers within HQ limits.

### What the brokerage earns

**Commission on the floor price, plus 100% of any amount above the floor.** RightAlly always receives
the floor price minus commission, whatever the brokerage charges. Example (Pro: list $100, floor $60,
25%): price $60 → broker $15, RightAlly $45; price $75 → broker $30, RightAlly $45; price $100 → broker
$55, RightAlly $45.

### Rules

- Agents see the brokerage price next to the public price.
- Price increases: new agents immediately, existing agents after 30 days' notice; decreases at once. HQ
  list/floor increases: brokerages get 60 days' notice and existing agents keep their price for that period.
  Annual plans lock the price for the year.
- Commission is paid on money actually collected (after refunds and chargebacks, excluding sales tax),
  monthly, with a minimum payout (e.g. $25; smaller amounts roll over).
- Failed agent card: 7-day grace, then the brokerage's chosen level (minimal or blocked); nothing deleted.
  Failed brokerage card: grace, then read-only; agents' own paid tools keep working.
- Joining: invitation, brokerage join link, portal sign-in or back office → plans at the brokerage price →
  card → in. Each agent is tied to the brokerage that brought them; only that brokerage can change it.
- Leaving a brokerage: the agent keeps their account at the direct price, their personal contacts go with
  them, company leads stay; the brokerage's commission stops.

### Screens

- **HQ console**: plans, list and floor prices, commission tiers, broker fees, special deals, payouts.
- **Brokerage back office**: plan prices, agents by plan, revenue from its agents, earnings split into
  commission and markup, payout history.
- **Agent**: plans at the brokerage price, card, invoices, upgrade/downgrade.

### Technology

Stripe Billing (cards, subscriptions, invoices), Stripe Tax (SaaS sales tax by state), Stripe Connect
(brokerage payouts, identity checks, 1099 forms). Delivery: Team A (plans, billing, access, dashboard,
Sprints 20–21), Team B (commissions and payouts), before the pilot at Sprint 26.
