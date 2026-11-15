# Connect 14 — Paid apps: free for everyone, or a monthly add-on

Sprint **C6b** (Team A). Approved 2026-11-15.

## Why

Some apps cost RightAlly money for every brokerage that connects them. Xero, for example, charges per connected
organisation. RightAlly must never lose money on them. Connecting stays free in general, but HQ can make any app a
paid add-on.

## HQ: one setting per app

On each app's HQ card (HQ → Connect), under **Price for brokerages**:

- **Free for everyone.** This is the default and how every app works today.
- **Monthly add-on:** a price in dollars a month, plus the packages that **include it free**, for example
  "Enterprise includes Xero sync".

Changing the price is password-confirmed and audited. A new price applies from each brokerage's next month.

## Brokerage and agent

- **Who pays:** whoever owns the connection.
    - A brokerage connection is added by the **owner** and paid by the brokerage, from its credit pool.
    - An agent's own connection, for apps agents connect themselves, is added and paid by **that agent**, from their
      credits.
    - Staff can't buy for the brokerage.
- **Before connecting:** the app page shows "Add-on: $X a month" and an **Add for $X a month** button, which needs a
  fresh password. Connect is blocked until the add-on is active, for both one-click and key-based apps, with a plain
  message.
- **Included in the package:** the page says "Included in your RightAlly package" and Connect is open.
- **Charged in RightAlly credits,** at the credit value of the day:
    - the first month is charged when the add-on is added;
    - then a renewal runs hourly and charges each month as it starts;
    - each month's charge is keyed once, so it is never charged twice.
- **Not enough credits at renewal:** the add-on **lapses**. The app's connections for that payer **pause** with
  the reason, and nothing already synced is touched. Adding it again resumes them.
- **Cancel:** the add-on stays on to the end of the paid month, then ends and the connections pause. Pressing "Keep
  it" before then undoes the cancellation at no charge.
- **Made free, or now included in the package:** the add-on simply ends at its month's end and the connection
  carries on.

## Later

When Stripe add-ons from the packages and billing spec (specs/core/07) are built, the same add-on can move onto
the brokerage's monthly Stripe invoice instead of credits. The brokerage sees no change.

## Acceptance

- HQ sets an app to a monthly price with included packages, or back to free.
- Connecting a paid app is blocked until the owner adds it. Staff can't add it, and without credits it's refused.
  With credits, the first month is charged and the add-on runs to the same day next month.
- A brokerage whose package includes the app connects without an add-on.
- At renewal without enough credits the add-on lapses and its connections pause. Adding it again resumes them.
- Cancelling keeps it to the end of the paid month, then pauses the connection with no further charge.
