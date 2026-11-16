# Movewise MW7: reviews and the growth engine

Status: **Built** (sprint MW7). Follows [00-overview](00-overview.md) §8, §11 and §13 (SEO and AEO).

## 1. Verified reviews (FTC consumer-review rule)

- **Who can review.** Only people introduced to an agent through Movewise can review that agent. They rate the agent in My Movewise a week after the introduction, or at closing (MW6).
- **The Movewise average.** Every rating counts in the agent's Movewise average. None is hidden for being bad, and nobody pays for reviews.
- **Publishing the words.** The words are published only when the person ticks "Show my review on their Movewise profile". Only their first name is shown. Phone numbers and email addresses are replaced with "[removed]" before saving.
- **Agent replies.** The agent can reply publicly once. The reply is Fair-Housing screened.
- **Removal.** HQ removes a review only for abuse, personal information or a proven falsehood. A note is required, and the reason and HQ user are logged. A removed review leaves the average.
- **Imported reviews** (Google, RealSatisfied, from the agent's own account through Connect C8a):
    - shown in a separate "Reviews from elsewhere" section;
    - each one shows its source and date;
    - never counted in the Movewise rating, and the page says so.

## 2. Agent profiles

- **Address:** `/agent/{id}/{name}`, in English and under `/es`.
- **Shown:** photo, brokerage, licence number with the state's lookup link, years, languages, the approved introduction, the Movewise rating and reviews, and the imported reviews.
- **Markup:** `RealEstateAgent` with `AggregateRating` and up to five `Review` nodes. These come from verified Movewise reviews only.
- **Live agents only.** Only agents who are live in a live state have a profile; anyone else gets a 404.
- **On the "choose one agent" cards:** the rating, linking to the profile, or "New to Movewise: no reviews yet".
- **No direct contact.** The profile has no phone number or contact form. People reach agents only by choosing them on a home.

## 3. City, ZIP code and neighbourhood pages

- **Addresses:** `/{state}/{city}`, `/{state}/{zip}` and `/{state}/{city}/{neighbourhood}`, plus the Spanish versions. Cities and neighbourhoods come from the MLS data (city and subdivision).
- **Market facts now** (`Growth\Places`), from the homes Movewise may show:
    - homes for sale;
    - median asking price;
    - median price per square foot;
    - median days on market;
    - new this week.
- **Six-month market report:** new listings each month. Where the MLS shares them, it also shows sales and the median sale price. The page says so.
- **Plain-English answers:** how many homes are for sale, what homes cost, how fast homes sell. Each is marked up as `FAQPage`, with a `Place` node and breadcrumbs.
- **Links between pages:**
    - the state page links to its cities;
    - city pages link to their neighbourhoods and ZIP codes.
- **Sitemaps:** each state's sitemap lists every city page (both languages) and every ZIP code page.
- **Missing places:** a page with no homes is a 404, so there are no thin or empty pages.

## 4. Movewise's own ads: top-ups with a stop-loss

- **HQ settings** (HQ → Movewise money): monthly budget, weekly top-up, target cost per accepted introduction, and a stop-loss. The stop-loss must be at or above the target.
- **Spend:** recorded by day, channel (Meta, Google, other) and optionally state.
- **Daily check**, inside `movewise:outcomes`:
    - The top-up is **held**, and HQ staff are emailed once, when either:
        - the last 7 days cost more per accepted introduction than the stop-loss (or had spend and no accepted introductions);
        - the month's budget is spent.
    - When the numbers come back under, the status returns to OK.
- **Movewise doesn't move ad money itself.** The hold tells HQ not to top up the ad accounts. Pulling spend automatically from HQ's Meta and Google accounts can follow once Movewise campaigns run through the Ads module.

## 5. Tests

The MW7 section of `tests/Feature/Movewise/MovewiseMatchingTest.php` covers:

- **Place pages:**
    - city, ZIP code and neighbourhood pages, with their facts, FAQ, Place markup and hreflang;
    - the Spanish answers;
    - a 404 for an unknown place;
    - links from the state page;
    - sitemap entries.
- **Reviews:**
    - publishing and redaction;
    - the profile with its rating markup and imported reviews kept separate;
    - one agent reply;
    - removal only with a reason.
- **Ads:** settings validation, recording spend, the hold with HQ's notice, and the release once the cost per introduction falls.
