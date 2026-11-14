# Billing 04: buying RightAlly credits (Sprint 18B)

## Packs (HQ)

HQ → RightAlly credits → **Credit packs for sale**. Each pack has:

- a name, a number of credits and a price
- who can buy it: brokerages (for their pool), agents (for themselves), or both
- an on-sale switch and a display order

Next to each pack HQ sees the price per credit, roughly what it costs RightAlly if every credit is used (at the
typical cost per credit), and the margin. The margin shows in red below the minimum. Changes to packs are
audited.

## Buying

- **Owners and staff** buy packs for the **brokerage pool**.
- **Agents** buy packs for **their own account**. The brokerage can turn this off with "Agents can buy their own
  credits" (on by default). Agents' own credits are used before the pool's.
- `POST /settings/credits/buy {pack}` creates a pending purchase and sends the buyer to a one-off checkout on
  RightAlly's payment account:
    - an invoice is created, and the payment provider emails its receipt to the buyer
    - sales tax follows the platform's automatic-tax setting
    - the metadata `{kind: credit_pack, tenant_id, purchase}` comes back on the provider's events
- When `checkout.session.completed` arrives (signed, and processed once per event), the purchase is marked paid.
  The credits are then added with idempotency key `purchase:{ulid}`, so they are added only once even if several
  events arrive.
- When `charge.refunded` arrives, the unused part of the pack is taken back, in proportion to the amount refunded
  and never below the account's balance. The purchase is marked refunded.

## Order of use and expiry

1. The month's included credits are always used first.
2. Then bought credits, **soonest-expiring first** (each paid purchase is a lot with `remaining`).
3. Credits given to an agent come out of bought credits in the same order.
4. **12 months after payment**, whatever is left of a pack expires. This is the `credits:expire-purchases` job,
   which runs daily at 00:40 and never takes more than the account's carried-over balance.
5. Credits added any other way never expire: given to an agent, adjusted by HQ, or refunded after a failed AI
   request.

## Receipts and export

- **Settings → RightAlly credits → Purchases and receipts:**
    - Owners and staff see all of the brokerage's purchases; agents see their own.
    - Each purchase shows credits left and their end date.
- **Receipt page** (`/settings/credits/receipts/{ulid}`):
    - shows the brokerage, the buyer, the pack, the price, sales tax, the total, the payment reference and the
      expiry date
    - "Print or save as PDF"
    - open to the buyer, owners and staff
- **CSV export** (`/settings/credits/export?month=YYYY-MM`):
    - columns: date, type, credits, what, person, automatic, note
    - owners and staff get the whole brokerage; agents get their own lines
    - cells that start with `=`, `+`, `-` or `@` are prefixed so spreadsheets don't run them as formulas
    - every export is audited

## HQ visibility

HQ → RightAlly credits shows this month's packs sold, revenue including tax, credits sold, and the latest
purchases across brokerages.

## Tests

`tests/Feature/Billing/CreditPurchasesTest.php` covers:

- who sees which packs
- a brokerage purchase and its receipt, with replayed events ignored
- agent purchases
- order of use, and expiry after 12 months
- refunds
- the CSV export, including formula safety
- HQ packs and sales
