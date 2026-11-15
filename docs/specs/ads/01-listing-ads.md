# Ads 01 — Listing ads on Facebook and Instagram, from your own ad account (Sprint C4a, Team A with E)

An agent makes an ad for one of their listings in about a minute and publishes it to Facebook and Instagram from
their own Meta ad account, without leaving RightAlly. The results come back here. This is the "agent's own account"
billing model; RightAlly Boost (ads paid with credits) is C4b.

## For the agent

1. **Connect once:** Apps → Meta Ads → **Connect**. Sign in to Facebook, choose the ad account and Page, and press
   **Allow** (`scope: agent`; each person connects their own account).
2. **Grow → Ads → Make a listing ad:**
    1. **Which listing?** Agents see only their own active listings (MLS rules), matched by the MLS IDs on their
       roster profile. Owners and staff see every agent's listings.
    2. **The ad:**
        - The listing photo is shown.
        - "Write it with AI" drafts a headline (up to 40 characters) and the ad text. Everything can be edited.
        - The ad links to the listing's page on the brokerage website, tagged with `utm_source=facebook`,
          `utm_medium=paid_social` and `utm_campaign=rightally-{ad}`. Leads therefore come in through the website,
          its forms and AIlly, with attribution.
    3. **Who sees it:** everyone within a radius of the home, from 15 to 50 miles. The screen explains that housing
       ads can't be aimed by age, gender or interests.
    4. **Budget:** $5 to $500 a day, for 1 to 60 days. The agent ticks "I agree to spend up to $X from my own Meta ad
       account…", which shows the total before anything is published.
3. **Publish:**
    - The ad is published in the background, usually in a few seconds.
    - The Ads list shows "Running", or "Didn't publish" with Meta's own reason in plain words.
    - **Pause** and **Run again** take effect immediately. Only the person whose ad account it runs on can change it.
      Owners see every agent's ads, read only.
4. **Results:** spend, impressions ("Seen"), clicks, cost per click and leads are refreshed every hour. An ad whose
   end date has passed shows "Ended".

## Guardrails

- **Fair Housing (Meta's Housing special ad category):**
    - every campaign is `special_ad_categories: ["HOUSING"]` in the US;
    - the audience is only a place: a custom location around the home, at least 15 miles (enforced in code), every
      age from 18, every gender, with no detailed targeting. RightAlly never narrows an audience by anything else.
- **Wording:**
    - the AI prompt forbids describing buyers or protected characteristics;
    - the draft and the published text are both checked with `Crm\FairHousing`. Its list now also covers "perfect
      for families", "ideal for families", "young professionals" and "empty nesters".
    - Publishing is refused, with the exact phrases named, until the wording is fixed.
- **Money:** the most an ad can spend is shown, and the agent must agree to it. Daily budget and length are capped,
  and the publish is audited (`ads.publish`, with the maximum spend). Pause and resume are audited too.
- **Safe publishing order:**
    1. the campaign is created paused;
    2. the ad set, creative and ad follow;
    3. the campaign is switched on last.

    If any step fails, the campaign is deleted, so nothing half-made can spend.

- **The ad needs** a listing photo and a map location, and the brokerage website must be published.

## How it works (developers)

- **Module:** `modules/Ads` (`depends: core, crm, listings, sites, connect`).
    - `ads_campaigns` stores the listing, copy, image, link, location and radius, budget and dates, status, Meta
      ids and results.
    - `ListingAds` handles the rules and publishing; `AdCopy` writes the AI draft (feature `ads.listing_copy`,
      credit action `ai.ads_copy`, 1 credit); `Meta\MetaApi` calls the Marketing API v21; the `PublishCampaign`
      job publishes.
- **The connection:** the `meta_ads` app (Connect) uses the Facebook Lead Ads card's Meta app keys (`keys_from`;
  setup `needs: app`), with the scopes `ads_management`, `ads_read`, `pages_show_list`, `pages_read_engagement` and
  `pages_manage_ads`.
- **Each hourly run** of the driver `Ads\Meta\MetaAdsConnector`:
    1. makes the sign-in long-lived (once);
    2. lists the active ad accounts and the Pages;
    3. refreshes insights (`/{campaign}/insights`: spend, impressions, clicks, lead actions) for running and paused
       ads.
- **Pages:** `ads/index` and `ads/new`, under Grow → Ads in the menu.

## HQ

- Meta Ads uses the same Meta app as Facebook Lead Ads, so set that card up first.
- Request Advanced Access (App Review) for `ads_management` and `ads_read` as well.
- Run `php artisan ai:sync-features` so `ads.listing_copy` gets a model.

## Next

- **C4b (done, Ads 02):** RightAlly Boost, the stop-loss and commission-aware results.
- **Later:** lead-form ads (instant forms), which can reuse the Facebook Lead Ads intake, and an MCP tool for ads
  using confirm-first.

## Tests

`tests/Feature/Ads/ListingAdsTest.php`:

- own listings only;
- the AI draft, with Fair Housing flags;
- a Housing ad with a place-only audience and the safe publishing order;
- the budget agreement and limits;
- clean-up and Meta's reason when an ad is refused;
- pause and resume rights;
- results.
