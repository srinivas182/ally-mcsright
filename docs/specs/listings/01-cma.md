# Listings 01 — CMA, seller net sheet and buyer cost estimate (CM1)

Plan item C14 (home valuation and CMA, net sheets, buyer cost estimates). The website's "What's my home worth?" (specs/websites/07) and Movewise's net sheet already existed; CM1 gives agents and brokers their own CMA tool and estimates, in the new `Pricing` module.

## Leader review

| Leader                               | What they do                      | Where they fall short                                                             |
| ------------------------------------ | --------------------------------- | --------------------------------------------------------------------------------- |
| Cloud CMA (Lone Wolf)                | Polished CMA reports, buyer tours | Separate subscription; comps and adjustments by hand; not tied to the CRM or deal |
| RPR (NAR)                            | Free valuations and data reports  | Dated, unbranded, long reports sellers don't read                                 |
| MoxiPresent                          | Listing presentations             | Expensive add-on, another login                                                   |
| Lofty / BoldTrail CMA                | Basic CMA from MLS data           | Thin; no net sheet or buyer costs in the same flow                                |
| Title company and lender calculators | Net sheets and payment estimates  | Generic, separate, re-typed                                                       |

RightAlly adds: comps chosen automatically with a reason each; every adjustment shown in dollars and editable; a range with a confidence and why; a short branded report in English or Spanish; "opened" alerts; the net sheet at three prices and the buyer's costs in the same place, saved to the contact.

## Comps (`Modules\Pricing\Comps`)

- **Sold** homes only from MLSs the brokerage holds a **back-office or VOW** licence for. Without one, **asking prices** of homes for sale (IDX), always labelled, with low confidence.
- Search widens until 6 are found: sold in 6 then 12 months; 1, 2 then 3 miles (or the same ZIP code when the subject has no map position); ±25% then ±40% of the living area; beds ±1.
- Score: closer, more recent, more similar size and beds score higher; same street +5. Reason, e.g. "Same street · same beds · similar size · sold 62 days ago".
- The top 6 are used; up to 12 more are kept as "Other homes found". The agent can leave any out, use another, or add a home by street.

## Adjustments and range (`Modules\Pricing\Valuer`)

Each comp's price is adjusted to be like the subject (rates in `config/pricing.php`, rounded to $1,000, every line editable and resettable):

| Line              | Default                                                      |
| ----------------- | ------------------------------------------------------------ |
| Size              | difference in sq ft × half the comps' median price per sq ft |
| Bedrooms          | 1.5% of the comps' median price per bedroom                  |
| Full / half baths | 1.5% / 0.75% per bath                                        |
| Age               | 0.25% per year of difference, up to 30 years                 |
| Pool              | 2%, when both are known (MLS `PoolPrivateYN`)                |
| Garage            | 1% per space, up to 3 (MLS `GarageSpaces`)                   |
| Lot               | 0.5% per 10% of difference, up to ±5%                        |

Most likely price: the average of the adjusted prices weighted by score. Range: the 25th to 75th percentile of the adjusted prices (always around the most likely), rounded to $1,000. Confidence: high (5+ comps within 8%), medium (3+ within 15%), else low, with the reason; always low on asking prices.

Market in the ZIP code: sold in 90 days, median days on market, sale-to-list price, homes for sale now, months of inventory, and the change in price per sq ft against the 3 months before.

## Net sheet and buyer estimate (`Modules\Pricing\Calculators`, `Defaults`)

- **Seller net sheet** at the low, likely and high price: payoff, listing commission and buyer's agent compensation (always "negotiable"; the buyer's agent is the seller's choice), closing costs, repairs, seller concessions.
- **Buyer estimate**: conventional (3% minimum down, PMI 0.5% a year under 20% down), FHA (3.5% minimum, 1.75% upfront and 0.55% a year), VA (0% down, 2.15% funding fee); principal and interest, taxes, insurance, mortgage insurance, HOA; closing costs less any seller credit; cash to close; plain notes. Live as the agent types; saved to a contact; share link and PDF.
- Defaults by state (TX, MS, LA, AL, otherwise a general figure) in `config/pricing.php`, all **marked "review"**; the owner can set the brokerage's own on the Pricing page. They should be confirmed with a title company and a lender per state before launch.

## Report and sharing

- `pricing::cma` (page and PDF via DomPDF): agent and brokerage brand (logo, headshot, colours, fonts, licence line), range and confidence, the summary, comps with adjustments, the market, the net sheet, and "your agent's opinion of price, not an appraisal".
- **Summary**: "Write it for me" uses AI (`pricing.cma_summary`, credit `ai.cma_summary`) from the figures only; any dollar amount not in the CMA throws the AI text away for a plain template summary (English or Spanish). Never about who lives in an area.
- **Share link** `/cma/{token}` on the brokerage's back-office address: random 40-character token (only its hash is kept), 90 days, a new link ends the old one; `noindex`, `no-store`, no referrer. Comp photos only where the MLS allows sold photos (`sold_allowed`). Opening it counts a view, adds a timeline line on the contact, emails the agent (at most every 12 hours), and puts "Your CMA for … was opened" in their brief for two days.
- Buyer estimates share the same way at `/estimate/{token}`.

## Where it shows

Nav: Grow → CMA and estimates (owners and agents; agents see their own, owners all). Simple mode: a "Price a home" tile. A CMA can start from a listing (`?listing=`) or a contact (`?contact=`).

## Tests

`tests/Feature/Pricing/CmaTest.php`.

## Not in CM1

- Fetching the subject's facts from property data (agents type them or start from a listing).
- Buyer-estimate notes in Spanish (the figures and labels are translated).
