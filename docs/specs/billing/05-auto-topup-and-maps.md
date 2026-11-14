# Billing 05: saved cards, automatic top-up, paused maps and one-hour map tokens (Sprint 18B-2)

## Saved card and one-click buying

- Every credit-pack checkout saves the card for later (`setup_future_usage: off_session`). If the account already
  has a saved customer, the checkout reuses it. When `checkout.session.completed` arrives, RightAlly keeps the
  customer reference plus the card brand and last 4 digits, for display only, in `credit_autopay`. The brokerage
  pool has one card; each agent who buys their own credits has one.
- **Buy with •••• 4242** charges the saved card immediately, off-session (`POST /settings/credits/buy-saved`).
  **Pay with another card** opens the checkout again.
- **Remove** forgets the card and turns automatic top-up off.

## Automatic top-up

The setting reads: "When credits drop below _N_, buy _pack_". Owners and staff set it for the pool; agents set it
for their own credits.

- After every charge, `TopUpCredits` is queued for the account that paid, and for the pool. It is unique per
  account for 10 minutes. The job buys the pack on the saved card when the balance is below the threshold.
- **Cooldown:** at most one attempt every 10 minutes. After 3 failures in a row it stops until the card is fixed,
  which happens on any successful purchase.
- **What can happen to a charge:**
    - **Succeeded:** credits are added at once.
    - **Processing:** credits are added when `payment_intent.succeeded` arrives.
    - **Requires action or failed:** the purchase is marked failed and owners get "Your card needs attention" by
      email. For an agent's own credits, the agent gets it.
    - A late `payment_intent.payment_failed` for a charge that was processing does the same.

## Website maps beyond the package

- Extra views are paid from credits at HQ's price: `usage.map_views_10`. HQ → RightAlly credits also shows that
  price per 1,000 views, and the brokerage's credits page shows it in dollars.
- **Maps never pause for the platform as a whole.** A brokerage's own map pauses only when credits are enforced,
  its allowance is used, it has no credits, and automatic top-up couldn't help. Pausing starts a top-up attempt
  on the saved card.
- **While paused:**
    - each visitor who tries to open the map is counted (`usage_meters.paused_at`, `missed`)
    - owners get an email when the pause starts, and a daily reminder (`credits:paused-maps`, 14:05 UTC) with the
      number of visitors who missed the map
    - owners and staff see a banner on every back-office page, with a link to add credits or turn on auto top-up
    - nothing in the back office is blocked
- The map comes back on the next visitor after credits are added.

## HQ map-view alert

`credits:margin-watch` (daily) emails HQ staff once a month when all websites together pass HQ's line (default
60,000, set in HQ → RightAlly credits). Nothing pauses.

## One-hour map tokens

A token used in a browser can always be read, so instead of hiding it, visitors get tokens that stop working
within an hour.

- HQ → Websites holds an optional **Mapbox secret token** (`sk.`). It must have `tokens:write` plus the visitor
  scopes: `styles:tiles`, `styles:read`, `fonts:read`, `datasets:read` and `vision:read`.
- Saving it asks for HQ's password. It is checked by minting a test token, stored encrypted, never shown again,
  and audited at critical level.
- `POST /_map` returns a temporary token from `POST https://api.mapbox.com/tokens/v2/{user}`. The account name
  is read from inside the secret token, and the token lasts up to an hour, Mapbox's limit. It is shared for 50
  minutes.
- If Mapbox can't be reached, the public token is used, so maps keep working.
- Listing pages keep the public token for their static map image.

## Tests

`tests/Feature/Billing/AutoTopUpTest.php` covers:

- the card saved at checkout, and one-click buying
- automatic top-up with its cooldown
- failures and the three-strike stop
- charges that clear or fail later
- the paused map: counting, emails, the reminder, and resuming
- one-hour tokens, including falling back to the public token
- the HQ map-view alert
