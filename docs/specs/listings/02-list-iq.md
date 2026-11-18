# Listings 02 — List-IQ: getting a listing ready (LI1)

Plan item C12 (listing marketing centre: AI descriptions, sign riders, open house), first half; LI2 adds the marketing centre. New `ListIq` module.

## Leader review

| Leader                      | What they do                       | Where they fall short                                                  |
| --------------------------- | ---------------------------------- | ---------------------------------------------------------------------- |
| BoxBrownie and staging apps | Virtual staging, photo edits       | Paid per photo; staging often not disclosed (MLS rules); another login |
| ListingAI and AI writers    | Listing descriptions               | Fair Housing slips; ignore MLS character limits                        |
| Zillow Showcase             | Premium listing presentation       | Zillow-only, paid                                                      |
| Ally Studio (ours, SA5)     | Enhances photos already on the MLS | Starts after the listing is live                                       |

RightAlly adds: one checklist from photos to "ready", a guided phone shoot, automatic photo order, three AI drafts within the MLS limit and checked for Fair Housing, staging that is always labelled with the original kept, and one MLS-ready package.

## The listing (`Modules\ListIq\Preps`)

Started from one of the agent's listing deals or an address (facts: beds, baths, sq ft, year built, price, features). **Checklist** (automatic unless marked): a front photo; at least 12 photos; description chosen; description clear of Fair Housing wording (`Crm\FairHousing`); showing instructions; seller disclosures signed (ticked); sign and lockbox ordered (ticked). All done = "Ready to go live".

## Guided shoot and photos (`Shoot`, `Photos`)

- Shot list of 16 rooms in buyer order with a tip each, plus general tips (landscape, level, lights on, no people). The camera opens on phones.
- Photos are re-saved as JPEG at most 2,400 px wide, captioned by room (editable), and ordered by room; the agent can move or leave any out.
- **Enhance** (when Ally Studio's enhancer is on): the enhanced copy replaces the original in the set; the original is kept.
- **Virtual staging** (HQ app `listing_staging`: Replicate token and interior model version, set in HQ → Apps): 5 styles; costs credits (`listiq.staging`, default 10, refunded if it fails); the staged copy has **"Virtually staged" drawn in the image**, its caption says so, and the original stays in the set next to it.

### Staging providers (MD1)

Virtual staging now uses the provider HQ chooses in Outside services → Virtual staging (`Staging\ServiceStager`): **Collov AI (recommended)**, REimagineHome or Virtual Staging AI, in Test or Live mode. When that service is off, the Replicate app (above) is used.

- List-IQ rooms and the five styles are mapped to each provider's own room types and styles.
- REimagineHome first finds the furnishing areas (masks), then furnishes only those.
- RightAlly waits up to about two and a half minutes for a result, checking every 3 seconds.
- Whatever the provider, the staged copy carries **"Virtually staged"** in the image and in its caption. The original stays in the set, and credits are refunded on failure.

## Listing video (MD1)

**Make my listing video** creates a vertical 1080 × 1920 video for Reels, Shorts and TikTok on RightAlly's own renderer (`ListingVideo`). It needs at least 5 photos.

- **Content:**
    - a cover with "Coming soon" (or "Just listed" once in the MLS), the address, price and facts;
    - up to 12 photos in the set's order, with room captions (staged photos keep their label);
    - an ending with the agent, their phone and the brokerage;
    - quiet music from the Studio library.
- **Cost:** 5 credits (`listiq.video`), refunded if it fails.
- **Where it shows:** the List-IQ page, as a download, and the public listing page.
- Landscape YouTube tours of MLS listings remain in Ally Studio (SA3).

## Descriptions (`Descriptions`)

Three AI drafts (warm, crisp, luxury; `listiq.description`, credit `ai.listing_description`) from the facts and the agent's notes only (notes go in as untrusted data); each within the MLS limit (default 1,000, editable), cut at a sentence, with links, emails and phone numbers removed. The agent picks and edits one; saving checks the limit; Fair Housing phrases are shown with plainer wording. A Spanish version on request.

## MLS package

A ZIP: photos in order (`01-front.jpg`…; staged ones `-virtually-staged`), `captions.csv`, `description.txt`, `description-es.txt`, `staging-disclosure.txt` listing each staged photo, and `virtual-tour.txt` (the unbranded tour link, for the MLS virtual-tour field) when there is a tour. Most MLSs don't accept uploads by API, so the agent uploads it; direct MLS input comes with MLS add/edit access.

## Tests

`tests/Feature/ListIq/ListIqTest.php`, `tests/Feature/ListIq/MediaTest.php`.
