# Ads 02 — RightAlly Boost, the stop-loss, and commission-aware results (Sprint C4b, Team A with E)

**RightAlly Boost** lets an agent with no ad account (or who would rather not use their own) run the same listing
ad and pay with RightAlly credits. The ad runs from RightAlly's own Meta ad account and Page. Everything in Ads 01
applies: Housing rules, Fair Housing wording, own listings only, the budget agreement and the safe publishing order.

## Decisions (agreed with the owner)

- **Margin:** 15% on top of the ad spend by default. HQ can change it at any time on the RightAlly Boost card
  (`margin_percent`, 0 to 100). Each boost keeps the margin it was bought with (`margin_bps`).
- **Unspent credits** go back to the wallet automatically when the boost ends.
- **Who pays:** the agent's own credits. The brokerage's pool covers any shortfall only if its credit policy allows
  agents to use the pool, so the broker decides.
- **Stop-loss** is on by default and can be switched off per ad. It pauses the ad when half the budget has brought
  no lead, or when a lead costs more than three times the brokerage's average. The agent is emailed why and can
  run the ad again.

## How Boost works

1. **Builder:** under "Pay with", the agent chooses "My own Meta ad account" or "RightAlly credits (Boost)". When
   they have no Meta connection, Boost is the only choice.
    - The agreement shows the credits: "I agree to use up to 11,500 credits ($100 of ads plus RightAlly's 15%).
      Credits it doesn't spend come back to me." It also shows their balance.
2. **Credits:** `ceil(spend cents × (1 + margin) × 1,000,000 ÷ credit value in microcents)`. At the default credit
   value of 1¢, $100 at 15% is 11,500 credits.
    - On publish, the credits for the most it can spend are **reserved**, as one charge: action `ads.boost`, rate
      1, quantity = credits, idempotency `boost:{ad}`.
    - Not enough credits (when HQ enforces credits): nothing is made, and the message says how many are needed.
    - If Meta refuses the ad, every credit comes back (a full refund).
3. **Running:** on RightAlly's ad account and Page with HQ's system-user token. Every ad's description says who the
   listing is with ("Listed by Jane Doe, Acme Realty"), as state advertising rules require. Own-account ads now
   carry this too.
4. **Ending:** an ad ends on its end date, or when the agent presses **End it now**.
    - The refund is the credits reserved minus the credits for what Meta actually spent, at the margin the boost was
      bought with. It happens once (`CreditLedger::refundPart`, key `refund:boost:{ad}`).
    - The list shows the credits reserved and the credits given back.
5. **Hourly:** `ads:refresh-boosts` runs across all brokerages. It:
    - refreshes results;
    - applies the stop-loss;
    - ends boosts past their end date;
    - refunds.

    Own-account ads are refreshed by their Meta connection's hourly sync, with the same lifecycle.

## Commission-aware results (every ad)

- **Leads:** website leads whose `utm_campaign` is `rightally-{ad}`. The website keeps the ad's UTM on its forms.
- **Commission:** deals (`tx_deals`) with those people that were created after the ad. Closed GCI counts as
  **earned**; under-contract GCI counts as **pending**.
- **The ads list shows:** "Commission from these leads: $15,000 closed, $9,000 under contract · 500× what it cost".
- **Stop-loss average:** the brokerage's average cost per lead comes from its other ads with leads (at least three
  are needed before that rule applies).

## HQ setup (RightAlly Boost card)

1. In RightAlly's Meta Business Manager, choose the ad account (with a payment method) and the Page Boosts run as.
2. Create a system user with `ads_management` on them, and generate its token.
3. Paste the ad account ID, Page ID and token (encrypted, write-only), and the margin. Then switch Boost on.

It uses the Meta app already approved for `ads_management` (Meta Ads card). RightAlly pays Meta for Boost spend.

## Tests

`tests/Feature/Ads/BoostTest.php`:

- pricing with HQ's changeable margin;
- a boost runs from RightAlly's account and reserves credits, with the disclosure;
- an unaffordable boost is refused, and a refused ad gives a full refund;
- ending a boost refunds the unspent credits at the original margin, once;
- the stop-loss pauses the ad and notifies the agent;
- leads and commission are shown on the ad.
