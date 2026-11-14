# Websites 06 — IDX search, listing pages and market pages (Sprint 17C-2a)

Status: delivered in 17C-2a. Builds on 02 (MLS ingestion, display rules) and 05 (site engine); follows 04 (SEO/AEO).

## Leader review

|                             | Leaders (Zillow, Redfin, Compass, Luxury Presence, Real Geeks, Sierra) | RightAlly 17C-2a                                                                        |
| --------------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Search page                 | Mostly client-rendered; crawlers see little                            | Complete server-rendered results; the map is a small script on top                      |
| Map                         | Pins, draw (some), move-to-search                                      | Clustered price pins, draw an area, search as I move, list/map toggle on phones         |
| Shareable searches          | Partly                                                                 | Every filter in the web address; back/forward work                                      |
| Listing page data           | Schema often missing or partial                                        | RealEstateListing + Offer + residence (beds, baths, floor size, geo) + breadcrumbs      |
| Neighbourhood pages         | Static copy or none (brokerage sites)                                  | Live figures per city and ZIP, answer-first summary, FAQ structured data, hourly        |
| AI answers                  | Rarely considered                                                      | llms.txt lists market reports with figures; FAQs written as the questions people ask AI |
| IDX in iframes / subdomains | Common                                                                 | Never: the brokerage's own domain earns the SEO                                         |

## Search (`/homes`)

- Filters: place (city, ZIP, street, MLS#), price, beds, baths, sort, map area (`bounds`) or drawn area (`poly`, 3–60
  points; bounding box in SQL, then point-in-polygon). All in the query string; `/homes` with no filters is indexable,
  filtered searches are `noindex, follow` with the canonical `/homes` (market pages are the indexable landing pages).
- `/_search` returns the heading, Blade-rendered cards (escaped) and pagination for the script, plus up to 500 pins
  `[ulid, lat, lng, price, beds]` when `pins=1`. Only homes whose address may be shown get a pin.
- Without a Mapbox token the page is a list; with one, Mapbox GL JS 3.8 (light or dark style by template).

## Listing pages (`/homes/{ulid}/{address-words}`)

- The id finds the listing; wrong or missing words redirect (301) to the canonical address.
- Shown only when the brokerage is licensed for the MLS and the MLS rules allow it (ListingPresenter); otherwise 404.
- Gallery with a keyboard and swipe lightbox, key facts, price per sq ft, details, price history with % change,
  monthly cost estimate (P&I + tax + insurance; defaults in `sites.mortgage`, visitors can change them), static map
  (Mapbox), links to the city and ZIP market reports, similar homes (same ZIP, ±25% price), MLS attribution, logo and
  disclaimer.
- "Ask about this home" posts to `/_lead` with the listing id; the server looks the listing up (never trusting the
  form) and adds "About {address} (MLS# …), listed at $…" plus the price and ZIP to the lead.

## Market pages (`/market`, `/market/{city-st}`, `/market/{zip}`)

- `MarketStats` (Listings module): homes for sale, median list price, 10th–90th percentile range, median $/sq ft, median
  days on market, new and price-cut listings in 30 days, ZIPs within a city. Licensed listings only. Areas with fewer
  than 3 homes are not published. Cached hourly.
- Page: answer-first summary, figure tiles, newest 12 homes, FAQ (with FAQPage JSON-LD), ZIP links, breadcrumbs.
- Sitemap: `/homes`, `/market`, every market page and up to 5,000 listing pages. llms.txt: homes link and the top 12
  market reports with their figures and date.

## Maps token

HQ → Websites → Maps: a Mapbox public token (`pk.`), stored encrypted, shown only as its last four characters. Secret
tokens (`sk.`) are refused because visitors' browsers see the token. Fallback: `MAPBOX_PUBLIC_TOKEN`.

## Links from the back office

`listings.public_url` now resolves to the brokerage's live website (`https://{host}/homes/{ulid}/{words}`), so
saved-search alerts, "send to client" texts and the back-office listing page link to the brokerage's own site.

## Also fixed

The back-office listing detail received the photo count instead of the photos (ListingSearch::find).

## Next (17C-2b)

Home valuation page, visitor accounts (favourites, compare, saved searches), visitor journey with AI summary on the
lead, "ready to act" alerts.
