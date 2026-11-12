# Websites 03 — AIlly, the website and agent-page assistant (module Home-IQ)

Status: **Module definition approved** (owner, Sprint 4 planning); all in version 1.0. Detailed
specification after the website/IDX sprints it builds on. Teams: C (assistant), D (CRM hand-off and
calendar sync), E (cost dashboard).

## Name and branding

- Default name **AIlly**. Each **brokerage** can rename it and add a photo (broker or logo) for its IDX
  website; each **agent** can do the same on their own website and landing pages (e.g. "Ask Maria"),
  within the brokerage's Brand Kit rules. Always labelled as an AI assistant.
- On by default on every brokerage IDX website, and on agent websites for paid agent plans.

## What it does

| Capability                                   | How                                                                                                                                                                                                                                               |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Plain-language home search (typed or spoken) | "3 beds near a school, no car needed, single level, under $350k" → every listing in the brokerage's **licensed** MLSs (never scraped portals), with "why this home" (walking distance to a school or coffee shop, transit, single level, commute) |
| Listing questions                            | From MLS data only: HOA, taxes, year built, lot, days on market, price history                                                                                                                                                                    |
| Area questions                               | Distances and walking times to schools, groceries, coffee, parks, transit; commute times (Google Maps Platform); school locations from federal data, ratings only with a licence                                                                  |
| Buying and selling questions                 | From the brokerage's approved knowledge; a payment estimate clearly marked as an estimate                                                                                                                                                         |
| Sellers                                      | "What's my home worth?" → home valuation → offer to meet an agent                                                                                                                                                                                 |
| Booking                                      | Showing, call or meeting with the listing agent, the routed agent or the broker owner, at real free times (Google/Outlook calendar sync; simple available hours until connected)                                                                  |
| Human hand-off                               | "Talk to someone" notifies the agent at once (app, email, text to the agent's own phone); unanswered requests move to the next agent by the brokerage's routing rules                                                                             |
| CRM sync                                     | New or matched contact with conversation summary, homes viewed, criteria, an AI lead brief, consent records (including TCPA consent wording when a visitor agrees to texts) and the booking as a task; Lead-IQ follow-up starts                   |
| Languages and hours                          | English and Spanish, 24/7                                                                                                                                                                                                                         |

## Guardrails

- Answers only from approved sources (MLS data, brokerage knowledge, maps, calculators); no legal, tax or
  lending advice; never guesses — out-of-scope questions are offered a person.
- **Fair Housing**: needs become home features and distances ("near a school", "single level", "near
  transit"), never who lives somewhere; no comments on school quality, crime or demographics; no
  "family-friendly" or "great for seniors" language; legal 55+ communities only as a factual listing
  attribute. Visitors' own statements about family or age are never stored as search filters.
- Bot protection, rate limits, conversation allowance per package; Google Maps data shown on Google maps
  and cached only as its terms allow.

## Costs and packaging

- **HQ cost dashboard**: per brokerage and per agent — conversations, AI cost, Maps cost, cost per
  conversation, leads and bookings per 1,000 conversations — from real usage logs; **what-if calculator**
  ("1,000 conversations a month ≈ $X") for pricing.
- Included in every brokerage flat fee with a monthly conversation allowance set by HQ; agent paid plans
  (Pro and above) include their own allowance; more conversations as credits.

## Benchmarks

Zillow natural-language search (commute, schools, points of interest), Realtor.com RealAssist AI (built
with Google Gemini and Google Cloud), Homes.com Homes AI. AIlly's difference: on the brokerage's and the
agent's own sites, booking real meetings and feeding the brokerage's CRM and Lead-IQ.
