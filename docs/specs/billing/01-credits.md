# Billing 01: RightAlly credits, as built (Sprint 18A)

Design background: [core/07](../core/07-packages-billing-credits.md). This page records what 18A built and where it
differs. Purchases (Stripe top-ups for brokers and agents, receipts, CSV export, 12-month expiry of bought
credits) follow in 18B.

## What a credit is

- HQ sets **what one credit is worth** (default $0.01). It is stored in micro-cents (millionths of a cent) in
  `credit_settings.credit_value_microcents`.
- Every AI action has a **price in credits** in the rate card (`credit_rate_card`, with defaults in
  `Billing\Credits\RateCard::DEFAULTS`). Any feature declared in `FeatureCatalog` without a price costs 1 credit.
- **Automatic actions** (lead auto-responder, intent reading, lead-email parsing, journey summaries, monthly market
  updates, DocIQ reads, AIlly) are marked `automation`. They always use the brokerage pool and are reported
  separately.

## Who pays

| Who starts it            | Pays from                                                                                                             |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| Automation, or no person | Brokerage pool                                                                                                        |
| Owner or staff           | Brokerage pool                                                                                                        |
| Agent                    | The agent's own credits first. Then the pool, if the broker allows agents to use it and the monthly cap isn't reached |

When there aren't enough credits, the charge raises `InsufficientCredits` with a plain message such as
"Jane, you are out of RightAlly Credits. Ask your broker to allot more, or buy your own."

### Enforcement switch (rollout)

`credit_settings.enforce` defaults to **off**. While off, every charge is recorded and shown, and balances can go
below zero, but nothing is blocked. HQ turns it on in HQ → RightAlly credits once every package includes credits.
Turning it on or off needs password confirmation and is audited at warning level.

## Ledger

- `credit_accounts` (cell): one pool per brokerage (`user_id` null) and one account per person. Holds `balance`
  and `included_balance`, the part of the balance that came from the package and ends with the month.
- `credit_ledger` (cell): append-only. Entry types are `grant_included`, `purchase`, `allocate_out`/`allocate_in`,
  `consume`, `refund`, `expire` and `adjust`. Each entry is unique per tenant by `idempotency_key`:
    - the gateway's request key, for charges
    - `refund:<key>`, for refunds
    - `grant:YYYY-MM`, for monthly grants
- Charges lock the account rows (`SELECT … FOR UPDATE`) inside a transaction. A duplicate key from a concurrent
  request is caught and treated as already charged.
- Included credits are spent first. Credits given to an agent come from the carried-over part, so included
  credits stay in the pool.
- **Monthly grant:** `credits:grant-monthly` runs daily at 00:20 and is safe to run again. For each active
  brokerage it ends last month's unused included credits, then grants the package's `included_credits`, once per
  month.

## AI gateway

`Billing\Credits\LedgerCreditMeter` replaces `NullCreditMeter`. The gateway charges before calling the model and
refunds when the output is blocked. HQ requests and requests without a tenant are free.

## Consent before spending

- `GET /credits/quote?action=…` returns `{label, credits, available, payer: you|brokerage, enough}`.
- `confirmCredits(action)` (`components/credit-consent.tsx`) shows "This will use N credits (you have M).
  Continue?" before every AI action a person starts:
    - blog post and page drafts
    - editing help and bio
    - CRM Ask, contact brief refresh, spoken or typed updates
- Actions of 2 credits or less offer "Don't ask again today". This is a per-browser convenience.
- Automatic contact-brief refreshes only run once the person has opted out of the question for small actions.

## Visibility

- **Menu meter** at the foot of the sidebar: "Credits this month 60/100" with a bar, linking to Settings →
  RightAlly credits. Agents see what they can spend, which is their own credits plus the pool share allowed.
  Before enforcement, with nothing granted, it shows credits used instead.
- **Settings → RightAlly credits**:
    - Owners and staff see:
        - the balance
        - included and bought credits
        - use by person
        - automatic use
        - use by feature
        - every agent's balance and use, with Give and Take back
        - the pool rules: agents may use the pool, and an optional monthly cap per agent
        - prices
        - history
    - Agents see their own credits, the pool share they can use, prices and history.
- **Low-balance emails** go to owners at 80% and 100% of the month's credits used, once each per month.

## HQ → RightAlly credits

- Credit value, and the enforcement switch.
- **Economics per action:**
    - the routed model and provider, with input and output price per 1M tokens
    - uses in the last 30 days
    - average tokens in and out
    - average real cost, which includes retries and blocked attempts
    - revenue per use (credits × value)
    - margin, flagged red under 30%
    - **tokens one credit buys** on that model at the action's real input/output mix
- Price per action, editable.
- Credits included in each package, with their dollar value.
- The brokerages that used the most credits this month.
- Add or remove credits for a brokerage, with a reason that the brokerage sees.

## Tests

Covered in `tests/Feature/Billing/CreditsTest.php`:

- payer rules, idempotency and refunds
- caps and the pool switch
- enforcement on and off
- monthly grant and expiry, and the grant command
- low-balance emails
- the gateway charge
- the quote, the meter and the page contracts
- giving credits and pool rules
- HQ economics, prices, value and grants
