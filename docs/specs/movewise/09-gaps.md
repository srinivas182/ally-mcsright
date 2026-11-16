# Movewise MW9: closing the gaps

Status: **Built** (sprint MW9). This sprint closes the four gaps listed at the end of MW5 to MW8.

## 1. Status comes from the agent's work in RightAlly (`Outcomes\AutoStatus`)

Agents no longer report a Movewise introduction's status twice. It follows what they already do in RightAlly:

| What happens in RightAlly                                               | Introduction status                                                                                                                   |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| The person replies by text or email, or a call is logged as "connected" | Talked with them                                                                                                                      |
| "Pick a time" booked at accept                                          | Appointment set                                                                                                                       |
| A showing task is completed, or a meeting is logged                     | Appointment held. For per-appointment pricing, the charge still waits for the person to confirm.                                      |
| A deal is created for them                                              | Agreement signed                                                                                                                      |
| The deal goes under contract                                            | Under contract                                                                                                                        |
| The deal closes                                                         | Closed, with the deal's price, its GCI as the commission, and the closing date. For share at close, this raises the referral invoice. |
| The contact moves to a pipeline stage marked "lost"                     | Not going ahead                                                                                                                       |

The status rules:

- **Synced deals count too.** Deals synced from connected transaction apps (dotloop, SkySlope, Lone Wolf, Brokermint, OpenToClose) count the same way. The deal importer and `Deals` now fire `Transactions\Events\DealStatusChanged`.
- **Matching the deal to the person.** The deal is matched through its contact, or any party linked to that contact.
- **Status only moves forward.** A deal falling through doesn't end the introduction, and a closed or lost introduction never changes.
- **Closings with no figures.** A deal that closes without a price or commission moves the introduction only as far as "Under contract", so the agent confirms the figures.
- **History.** Every automatic step is recorded with where it came from, for example "Automatically, from the deal at 12 Oak St".
- **Manual updates still work.** The agent can still update the status by hand, and the 14-day reminder is reset by automatic updates too.

## 2. Ad spend pulled from the ad accounts (`AdBudget::sync`)

- **Which accounts and campaigns.** Spend comes from RightAlly's own ad accounts, the same accounts as RightAlly Boost:
    - **Meta:** campaign-level insights, day by day.
    - **Google Ads:** a GAQL report of `cost_micros` by date.
    - A campaign counts when its name contains the marker, `MOVEWISE_AD_CAMPAIGN_MARKER` (default "Movewise"). A state code in the name, as in "Movewise TX buyers", files the spend under that state.
- **When it runs:**
    - daily at the start of `movewise:outcomes`, covering the last 3 days, before the stop-loss check;
    - from HQ → Movewise money → **Sync now**, covering the last 7 days.
- **Synced and hand-entered rows.** Synced rows replace hand-entered ones for the same day, channel and state. HQ can still enter spend by hand for days or channels the accounts don't cover.

## 3. Home value estimates (`Growth\HomeValues`)

- **Source and cost.** Estimates are ATTOM's automated valuation, paid by HQ, through the new `PropertyData::estimate` (HQ's key, no tenant, no credits).
- **Cost limits:**
    - each address is cached for 30 days;
    - 5 estimates a day per person;
    - a monthly cap, `MOVEWISE_HOME_VALUES_PER_MONTH` (default 1,500). Once it is reached, the page says no estimate is available.
- **The `/home-value` page** ("What's my home worth?", in English and Spanish):
    - The page and its FAQ are public and indexable.
    - The estimate needs a free account, because it costs money and this keeps addresses out of URLs and logs. The address form is a POST, and results are not cached.
    - **What it shows:**
        - the low-to-high range and the most likely value;
        - the home's facts and its last sale;
        - the source and date, with "not an appraisal".
    - **Where it leads:**
        - "Selling? Talk to a local agent", which pre-fills the seller form with the ZIP, city, state and value;
        - the net sheet, pre-filled with the same figures.
- **AIlly.** When someone asks on a home whether it is worth the price ("overpriced", "value", "worth", or "valor" in Spanish), AIlly adds ATTOM's range to the home facts. It explains the range alongside the list price, and says it is an estimate. Prompt version 2.

## 4. Spanish everywhere

- **Translated:**
    - **Pages:** the listing page (facts, "About this home", who's selling, updates); the state page with its FAQ; the coming-soon page.
    - **Cost table:** every line and note.
    - **Calculators:** the affordability steps, the net-sheet lines, and every move-planner task and heading.
    - **Shared parts:** the cards, the footer and the skip link.
- **Legal pages** (privacy, terms, Fair Housing, accessibility, how Movewise is paid, brokers) have Spanish versions under `/es`. Each is marked as a **courtesy translation** where the English version prevails, and links to it. They are in the sitemap in both languages. Counsel reviews them with the state sign-off (MW8 checklist).
- **Still in English:**
    - **MLS listing text and the TREC notices**, which stay as the MLS and TREC publish them.
    - **The agent-facing "For agents" page**, which is linked as "(en inglés)" and kept out of the Spanish sitemap.
- **The test** checks that both string files have exactly the same keys.

## 5. Tests

The MW9 section of `tests/Feature/Movewise/MovewiseMatchingTest.php` covers:

- **Automatic status:** an inbound reply, then a completed showing, then a deal created, then a synced closing with its figures, and that the status never moves backwards.
- **Ad spend:** the Meta sync, filtered by marker and filed under the state from the name.
- **Home values:**
    - signed-out and signed-in requests;
    - the range and the caveat;
    - the 30-day cache;
    - the seller pre-fill;
    - AIlly receiving the estimate.
- **Spanish:** the legal pages, listing, calculators, state page and sitemap.
