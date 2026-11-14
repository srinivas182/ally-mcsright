# Websites 07 — Visitor accounts, home value, visitor journey and ready-to-act alerts (Sprint 17C-2b)

Status: delivered in 17C-2b. Builds on 05 (site engine) and 06 (IDX); CRM side builds on grow/04 (lead intake).

## Leader review

|                      | Leaders (Real Geeks, Sierra, Lofty, BoldTrail, Luxury Presence) | RightAlly 17C-2b                                                                                                                                              |
| -------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Registration         | Forced after N views; passwords or Facebook                     | Emailed 6-digit code, no password; prompt after N homes (brokerage sets, 0 = off), always dismissible                                                         |
| Tracking             | Cookies plus third-party pixels                                 | First-party only, no cookies; Global Privacy Control and Do Not Track honoured; notice in the footer                                                          |
| Activity on the lead | A raw "viewed listings" list                                    | Homes viewed (with times viewed), searches in plain words, saves, comparisons, payment estimates, home-value requests; written or AI summary with a next step |
| Alerts               | "Lead is active" pings                                          | "Ready to act": same home 3+ times in a week, saved a home, or worked out the payment; once per home per week                                                 |
| Home value           | Third-party AVM, often wrong                                    | Range from comparable licensed listings (size ±30%, beds ±1, same ZIP), shown after contact details, agent confirms                                           |
| Devices              | Separate profiles                                               | Signing in on a second device joins both journeys                                                                                                             |

## Visitors

- The browser keeps a random id (`ra_vid`) in local storage; no cookies, so pages stay cacheable.
- `POST /_t` (sendBeacon, text/plain JSON): up to 20 events per call, 500 per visitor per day, 240 calls per minute per
  IP. Types from the browser: page, search, listing, favourite, unfavourite, compare, mortgage. Listing events are
  checked against the brokerage's licensed listings and store a snapshot (address, price, beds, ZIP). Server events:
  lead, valuation, signup.
- Not tracked: `Sec-GPC: 1`, `DNT: 1`, crawlers and scripts.

## Accounts

- `POST /_auth/start` emails a code (15 minutes, 5 tries, stored as an HMAC; 5 per address per 15 minutes, 20 per
  network per hour). `POST /_auth/verify` returns an encrypted 180-day token kept by the browser.
- First sign-in for an address becomes a CRM lead ("Website sign-up" source, intent buy, consent wording, first-touch
  referrer, so AI search is attributed). A second device's journey is merged into the account.
- `GET /_me`, `POST /_me/favourites`, `POST /_me/searches` (CRM saved search with daily alerts, owned by the contact's
  agent or the brokerage owner).
- Hearts and "Compare" on every card and listing; a compare tray (up to 4); `/homes/compare` side by side with the
  best value in each row highlighted (noindex).

## Home value (`/home-value`)

`Valuation::estimate`: comparable homes for sale in the ZIP, narrowed by size and beds and widened step by step until
at least 3 are found; 25th–75th percentile of price per sq ft × the home's size (or of prices by beds when size is
unknown), rounded to $5,000. Every request is a seller lead with the address, details and estimate; the result page
(encrypted, one day) shows the range and three comparable homes and says an agent will confirm with recent sales.

## Journey on the lead

- Core interface `VisitorJourneys` (Sites implements it), so the CRM never reads website tables.
- Leads page: summary, homes viewed, searches, saved, visits; "See everything" loads `GET /leads/{lead}/journey`
  (contact visibility enforced) with each event and a link to the home on the live site.
- `SummariseJourney` (queued after the lead is linked and after ready-to-act): facts worked out in code; the AI
  (feature `sites.journey_summary`, credit action `ai.site_journey`) writes two sentences and a next step from those
  facts only, never personal details or Fair Housing characteristics. Without AI or credits, a written summary from the
  same facts is used. The first summary is added to the contact's timeline.

## Ready to act

On each beacon for a known visitor: the same home viewed 3+ times in 7 days, a saved home, or a payment estimate →
`LeadAlert` "Ready to act: {name}" to the contact's agent (or the lead's agent), a timeline note, and a fresh summary.
Once per home per 7 days.

## Settings

Settings → Website → Visitors: sign-up prompt after N homes (default 3; 0 off).

## Also fixed

Agent alert links built during a website request pointed at the public website host; they now always use the
brokerage's back-office domain.
