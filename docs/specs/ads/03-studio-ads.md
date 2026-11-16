# Ads 03 — Ads made from Ally Studio, promoted posts and instant-form lead ads (Sprint C4c)

Most agents advertise on Facebook and Instagram, so the Meta ads (Ads 01, own account; Ads 02, Boost with credits)
now use everything Ally Studio makes. Every rule of Ads 01 and 02 still applies: Meta's Housing special ad category,
an audience that is only a place (at least 15 miles around the home, every age from 18, every gender, no detailed
targeting), Fair Housing wording checks, own listings only, the budget agreement, the safe publishing order and the
"Listed by" disclosure.

## What an ad can be made of

On Grow → Ads → Make an ad, after choosing a listing, **Made from** offers:

- **The listing photo** (as before).
- **A Studio design:** its carousel (cover, photos, details) becomes a carousel ad, every card linking to the
  listing; a single design becomes a photo ad.
- **A Studio Reel or YouTube tour:** a video ad. The MP4 is uploaded to the ad account from RightAlly's CDN and the
  ad waits (up to three minutes) until Meta has processed it; its first frame is the thumbnail.
- **A post already on the person's Facebook Page** (a listing post that went out from Studio or Social posts):
  promoted as it is (its likes and comments keep counting). Own ad account only, as the Page it was posted on.

Studio offers **Advertise** on each listing design and Reel, and **Promote this post** on the Results page's top
posts that went out on Facebook.

## When someone taps the ad

- **The listing on the website** (as before; `utm_campaign=rightally-{ad}`), or
- **An instant form** (Meta's lead form, opened inside Facebook or Instagram): name, email and phone (prefilled by
  Meta) and "What would you like? A private showing / More details and photos / What my own home is worth". No
  question describes the person (Housing ads). It links the brokerage's privacy policy (required; remembered for
  next time) and thanks them with a link to the listing. The campaign is `OUTCOME_LEADS` with
  `LEAD_GENERATION` delivery on the ad (`destination_type: ON_AD`).
    - Only from the person's own ad account, and only for a Page that Facebook Lead Ads listens to (Apps), so every
      form lead arrives in the CRM through lead intake (routing, speed-to-lead, the AI responder).
    - The campaign name carries the ad's tag (`rightally-{ad}`); Facebook Lead Ads credits the lead to that ad, so
      the ads list and Studio Results count it with its commission.
    - Not for promoted posts (a post keeps its own link) and not for Boost (RightAlly's Page can't hand leads to a
      brokerage yet; with credits the ad sends people to the listing page).

## Results

Studio → Results shows paid ads beside organic posts: the month's ads, spend, people who saw them, clicks, leads
and cost per lead, with a link to every ad.

## Tests

`tests/Feature/Ads/AdFormatsTest.php` (carousel instant-form lead ad, Reel video ad, promoted post, guardrails) and
`tests/Feature/Connect/FacebookLeadsTest.php` (a form lead credited to its ad).
