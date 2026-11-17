# Listings 03 — Listing marketing centre (LI2)

Plan items C12 (listing marketing centre: listing kit, sign riders, open house) and C13 (floor-plan partner), in the `ListIq` module after LI1.

## Leader review

| Leader                        | What they do                   | Where they fall short                                                   |
| ----------------------------- | ------------------------------ | ----------------------------------------------------------------------- |
| Rela, QR sign-rider services  | Scannable sign riders          | Separate app; scans don't reach the CRM as tagged leads                 |
| Spacio, Open Home Pro         | Open-house sign-in on a tablet | Consent not recorded properly; visitors don't flow into follow-up plans |
| CubiCasa                      | Floor plans from a phone scan  | Separate; the plan isn't on the listing page                            |
| Single-property website tools | A website per listing          | Extra cost; leads land in email                                         |

RightAlly adds: one listing page on the brokerage's own website, every QR code counted and every lead tagged to the sign or flyer that brought it, consented open-house sign-ins straight into the open-house plan, and an activity panel for the seller conversation. Public marketing waits for the MLS (Clear Cooperation).

## Clear Cooperation

The agent ticks "It's in the MLS". Until then the listing page, QR redirects and lead form answer 404: under the MLS Clear Cooperation rule, a listing marketed to the public must be in the MLS within one business day.

## Listing page (`/listing/{ulid}` on the brokerage website, via `SiteExtensions`)

Photos (virtually staged ones say so), facts, price, description, floor plan, the listing agent, and a consented lead form ("Ask about this home or book a showing") that creates a lead assigned to the listing agent (source "sign" for yard-sign codes, else website). `RealEstateListing` structured data with the offer and the home, breadcrumbs, indexable. Each view counts.

## QR codes (`Marketing`)

Codes for a yard sign, flyer, social post or postcard: 7 characters, `/q/{code}` on the website redirects to the listing page with `?src=`; each scan counts; a lead from that page counts against the code. PNG download per code.

## Open houses and kiosk

The agent adds an open house (date, from, to; same day, within 12 hours) and opens the kiosk on a tablet: big inputs, "Are you working with an agent?", and the text-and-call consent box (unticked). Each sign-in is a lead for the listing agent (source "open house"); visitors without an agent go into the agent's "Open house follow-up" plan. Ending the open house emails the agent the list of visitors. Sign-ins after the end are refused.

## Floor plan and flyer

- Floor plan: a PDF, JPEG or PNG (up to 20 MB) from the agent's floor-plan app or partner; shown on the listing page.
- Flyer: one-page branded PDF (agent brand kit): hero photo and three more (staged ones labelled), facts, description, agent and licence line, and a flyer QR code.

## Activity

Page views, QR scans, leads from codes, open houses and visitors, on the listing (the seller portal comes in SH1).

## Tests

`tests/Feature/ListIq/MarketingTest.php`.

## Not in LI2

- A floor-plan partner connection (no partner with a public API is chosen yet; agents upload).
- One-tap "just listed" email to the sphere and social posts: Ally Studio and postcards already do these from the MLS listing.
