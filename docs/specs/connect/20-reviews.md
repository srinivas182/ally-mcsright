# Connect 20 — Reviews (C8a)

Sprint **C8a**. Reviews from other sites are kept as written, with their source and date. They are shown on agent and
brokerage websites, and later on Movewise (MW7).

## Sources

| Source           | How                                                                                                                                              | Status                                    |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- |
| Google           | With the Google Business Profile connection: each location's reviews (`v4 …/reviews`), daily. Replies are sent back (`PUT …/reviews/{id}/reply`) | Built                                     |
| RealSatisfied    | The agent's public feed `rss.realsatisfied.com/rss/agent/{vanity key}`, daily. The app is `realsatisfied` (agent enters the key)                 | Built                                     |
| Zillow           | Zillow Agent Reviews API on the Bridge platform, after Zillow approves RightAlly                                                                 | Partner card; built when access is issued |
| Testimonial Tree | Partner access from Testimonial Tree                                                                                                             | Partner card; built when access is issued |

## Rules

- **Never edited.** Reviews are stored in `site_reviews` per agent (or `user_id` null for the brokerage). Each one is
  saved once per source and external id; later syncs update it.
- **Averages per source.** Each source's average is shown on its own and never blended. Movewise's own verified
  reviews (MW7) are kept apart too.
- **Hiding.** Only the owner can hide a review, and only for one of three reasons: abuse, someone's personal details,
  or a proven falsehood. The reason and who hid it are kept, and the review stays hidden after later syncs. Low
  ratings are never hidden. This follows the FTC rule on consumer reviews.
- **Replies.** Google only. A reply is Fair Housing checked and posted to Google; the agent can change it. Other
  sources are answered on their own sites.
- **On the websites.** A Testimonials section with "show my reviews" (`from_reviews`), or with no testimonials
  written, shows the newest reviews with text. Each one shows "Google review · May 2026".
- **No markup.** There is no `AggregateRating` markup, because Google ignores self-serving review markup and it could
  be seen as manipulation.

## Tests

`tests/Feature/Sites/ReviewsTest.php`
