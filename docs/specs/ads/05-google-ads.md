# Ads 05 — Google search and YouTube listing ads

**Sprint C7d-2.** This is part of C7d (specs/connect/15). Listing and funnel ads can now run on Google as well as
Facebook and Instagram:

- **Google search:** a responsive search ad shown to people searching for the home, the street or homes for sale in
  the city.
- **YouTube:** a Demand Gen video ad. It is made from a Studio Reel or listing tour already on the person's YouTube
  channel, and shown on YouTube (in-stream, in-feed and Shorts).

They can be paid from the person's own Google Ads account (one-click Google sign-in) or with RightAlly credits
(RightAlly Boost, from RightAlly's Google Ads account). Results, the automatic stop-loss, end dates and Boost refunds
work exactly as for Meta ads (specs/ads/01, 02).

## Housing ads policy (built in)

Google's personalized advertising policy for housing in the US:

- **Not allowed:** targeting by age, gender, parental status, marital status or ZIP code.
- **Allowed:** a radius of at least 1 km, a city or a country.

RightAlly never adds an audience or demographic criterion to any Google ad. Location works like this:

- **Search ads:** a radius around the home (at least 15 miles, the same as Meta, and at most 50 miles).
- **YouTube ads:** the home's city. Demand Gen targets by place, not by radius.

The ad also has these protections:

- Ad text, headlines and keywords pass the Fair Housing check (channel `ads`).
- Keywords never include ZIP codes.
- Every ad names the brokerage and the listing agent ("Listed by …").

## What is sent to Google

Everything is created in one `googleAds:mutate` request using temporary ids, so nothing is half-made. The API version
is from `connect.google_ads_version` (default **v26**; v22 was sunset in October 2026, and Local Services leads move to
v26 too).

**Search ad**

- A budget: the daily amount, not shared.
- A SEARCH campaign:
    - Maximize clicks (`targetSpend`), Google Search only;
    - start and end dates (`startDateTime` / `endDateTime`);
    - `containsEuPoliticalAdvertising = DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING`;
    - the name ends with `rightally-{ulid}`.
- Campaign criteria: a proximity radius and the English language.
- One ad group, with phrase-match keywords made from the listing:
    - the address;
    - the street and the city;
    - homes for sale, and N-bedroom homes, in the city.
- One responsive search ad:
    - 3–15 headlines of up to 30 characters, and 2–4 descriptions of up to 90;
    - the final URL is the listing page with `utm_source=google&utm_medium=cpc&utm_campaign=rightally-{ulid}`.

**YouTube ad**

- A budget.
- A DEMAND_GEN campaign (Maximize clicks).
- One ad group:
    - channel controls set to YouTube only (in-stream, in-feed, Shorts);
    - the city (from geo-target suggest) and English as criteria.
- Assets: the YouTube video and a square logo. The logo is made from the brokerage logo, or the agent's headshot when
  there is no logo.
- One Demand Gen video responsive ad: headlines, a long headline, descriptions, the business name (the brokerage) and
  the final URL (`utm_medium=video`).

**Changes and results**

- Pausing, resuming and ending update the campaign status.
- Results come from GAQL: `metrics.cost_micros`, impressions, clicks and conversions for the campaign, all time.

## Accounts

**Own account (app `google_ads`)**

- One click with Google, using the `adwords` scope and RightAlly's developer token (HQ).
- Each daily sync:
    - lists the accounts the person can reach (up to 10), with the manager account each is reached through
      (`login-customer-id`);
    - refreshes their running ads' results and applies the stop-loss.

**RightAlly Boost on Google**

- HQ adds these to RightAlly Boost: RightAlly's Google Ads customer id, the manager id (optional) and a refresh token
  for RightAlly's Google account.
- Boost Google ads are refreshed hourly with the Meta ones.

## Rules

- Instant forms and promoted posts are Meta only. Google ads send people to the listing or funnel page.
- A YouTube ad needs a Reel or tour that went out on the person's YouTube channel, and a logo or headshot in the brand
  kit.
- Only the person who made an ad can pause or end it.

## Tests

`tests/Feature/Ads/GoogleAdsTest.php`
