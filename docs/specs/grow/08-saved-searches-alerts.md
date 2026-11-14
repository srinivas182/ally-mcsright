# Grow 08 — Saved searches and listing alerts (Sprint 17B-2)

Status: **Approved November 2026.** CRM addition 8 (property matches). Leader review: Follow Up Boss + IDX
add-ons, Lofty, kvCORE/BoldTrail, Sierra Interactive, Real Geeks.

| Capability                  | Leaders            | RightAlly 17B-2                                                                                          |
| --------------------------- | ------------------ | -------------------------------------------------------------------------------------------------------- |
| Saved searches on a contact | ✓                  | ✓ place, price, beds, baths, size, type, status, map area                                                |
| New-listing alerts          | ✓                  | ✓ instant (within 15 min), daily or weekly at 8am brokerage time                                         |
| Price-drop alerts           | some               | ✓ from price history; each listing sent once per kind and price                                          |
| Unsubscribe                 | link               | ✓ one-click (RFC 8058 `List-Unsubscribe-Post`); the confirm page means link scanners never unsubscribe   |
| Email consent               | varies             | ✓ never after an email opt-out                                                                           |
| MLS rules in emails         | varies             | ✓ listings only from licensed MLSs, through ListingPresenter, with each MLS's attribution and disclaimer |
| Send a home to a client     | ✓                  | ✓ one-to-one email through the inbox (its checks, the timeline, replies come back to the inbox)          |
| Replies                     | to the agent email | ✓ to the conversation's reply address: the reply lands in the inbox and stops follow-up plans            |

## Rules

- Searches need a place, a map area or a price range. Alerts need an email address.
- "Since" is the last check; a listing counts as new when added after it, as a price drop when its price fell after
  it. Up to 12 listings per email.
- Listing links point to the brokerage website's listing page once the site engine provides it (Sprint 17C).
- `crm:search-alerts` every 15 minutes.
