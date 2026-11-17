# Grow 17 — Recruiting (RC1, RC2)

Plan items E14 (recruiting suite, RC1), E16 and E17 (attraction engine and careers page, RC2). RC3 adds the AI recruiting assistant and retention (E15).

## Leader review

| Leader                         | What they do                                                              | Where they fall short                                                                      |
| ------------------------------ | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Brokerkit                      | Recruiting CRM: agent production data, drip campaigns, retention tracking | Separate from the back office, so it can't show a recruit what they'd really earn with you |
| Courted                        | AI ranking of likely-to-move agents, competitive insights, outreach       | Recruiting only, premium price, scores without reasons                                     |
| Trendgraphix / BrokerMetrics   | MLS market share and agent production reports                             | Reports only: no pipeline, no next steps                                                   |
| BoldTrail / Lofty / other CRMs | Pipelines and campaigns that can be bent to recruiting                    | No MLS production, no comparison of earnings                                               |

RightAlly matches: agent production from the MLS, a recruiting pipeline with notes and next steps, alerts. RightAlly adds:

- **What they'd earn here**: the recruit's own closed sides from the last 12 months, run through the brokerage's real commission plan by the same calculator that pays agents, against their plan today, with every assumption listed.
- **Plain reasons** on every number (sides, volume, change on the year before, when they changed office).
- In the same product as the roster, the brief and the plans, so a recruit who joins is already in the right place.

## Licence

Production comes only from MLSs the brokerage holds an **agent production** licence for (`MlsAuthorization` scope `agent_production`, approved by HQ). An IDX or back-office licence is not enough. Nothing is bought or scraped; recruits' details are entered by the recruiter.

## Agent database (`Modules\Recruiting\AgentDatabase`)

`recruiting:refresh` (daily 10:50 UTC) rebuilds `rec_mls_agents` per brokerage from closed listings:

- **Sides**: a closed listing gives one side to the listing agent, one to the co-listing agent and one to the buyer's agent. Last 12 months by closing date, and the 12 months before for the trend. Volume is the closed price.
- **Office**: from the agent's most recent listing (by contract date) or buyer side. When it differs from yesterday's, the old office and the date are kept.
- **Ours**: agents in the brokerage's own MLS office IDs (from the MLS licence until the broker changes them) are left out of the list.
- Agents with nothing in two years drop out unless someone is recruiting them.

The page (`/recruiting/agents`) filters by name, office or MLS ID, minimum sides and recent office change, and sorts by sides, volume, growth or most recent move. "Recruit" adds the agent to the pipeline (once).

Broker settings: own office IDs, the commission rate used to estimate commission from a closed price (default 2.5%), and the plan to show recruits.

## Pipeline (`Modules\Recruiting\Pipeline`)

Stages: To reach, Talking, Meeting set, Offer made, Joined, Not now (with a reason). Each recruit has a recruiter, a next step and date, and a history (notes, calls, emails, meetings, stage changes, alerts). Recruits added by hand need a name; phones are stored as +1 numbers.

Only people with `recruiting.manage` (owners, and the Recruiter and Managing broker role templates) see recruiting.

**Alerts** (on the pipeline page and in the daily brief), for open recruits only:

- changed brokerage ("Ann moved from KW Realty to CB Realty. A good moment to check in."); their brokerage is updated;
- production jump: at least 3 more sides and 50% more than the year before; at most once in 90 days.

Opening the recruit marks their alerts as seen. The daily brief also shows "N recruits to follow up" when next steps are due.

## What they'd earn here (`Modules\Recruiting\EarnHere`)

- Sides: the recruit's closed sides in the last 12 months from the MLS, or, for a recruit not in the MLS, the deals a year and average price the recruiter enters (spread over the year, alternating listing and buyer sides).
- Commission per side: closed price × the brokerage's rate.
- **There**: their split until their brokerage has its cap, less fees per deal (as the recruiter entered them; a 70% split, no cap, is assumed and said so until then).
- **Here**: the chosen commission plan (the recruit's, else the broker's choice, else the default plan) through `CommissionCalculator`, with the plan's fees and cap carried from deal to deal over the 12 months.
- Shown as a total there, here and the difference, deal by deal, with the assumptions.

## Tests

`tests/Feature/Recruiting/RecruitingTest.php`, `tests/Feature/Recruiting/AttractionTest.php`.

## RC2: likely to move (`Modules\Recruiting\MoveScore`)

Scored daily after the refresh, 0–100, always with the reasons shown next to it:

| Reason                                                               | Points      |
| -------------------------------------------------------------------- | ----------- |
| Closed sides down 30% or more on the year before (3+ before)         | 35          |
| 2 or more agents left their office in the last 90 days               | 25 (4+: 35) |
| About a year at their office (11–13 months since they moved)         | 20          |
| Growing fast (the production-jump rule): may be outgrowing the split | 20          |

The agent database sorts by "Most likely to move"; recruit cards show "Likely to move" at 40 or more.

## RC2: campaigns (`Modules\Recruiting\Campaigns`)

- A campaign is up to 8 steps, each on a day (0–120, in order), by email or text, with `{first_name}`, `{brokerage}`, `{recruiter}` and `{careers}`. Two ready-made: "Introduce us" (3 emails over two weeks) and "After they apply" (thank-you email, then a text the next day).
- The recruiter starts a campaign for one recruit at a time (never in bulk); "After they apply" starts by itself for new careers-page applicants. Each campaign runs once per recruit.
- **Email**: from the recruiter's name, replies to the recruiter, the brokerage's postal address and a signed one-click unsubscribe (`List-Unsubscribe` and `List-Unsubscribe-Post`) in every message (CAN-SPAM). Unsubscribed recruits get no more recruiting emails.
- **Text**: only to recruits who agreed in writing (the careers page asks, with its own wording about working there), only 8am–9pm in the brokerage's time zone (otherwise the step waits), always ending "Reply STOP to opt out." STOP and START replies are honoured through the CRM's new `SmsKeywordReceived` event, even though recruits aren't contacts.
- A campaign stops when the recruit is no longer open (joined, not now), or when the recruiter stops it. Steps a recruit can't receive are skipped. Paused campaigns resume where they were.
- `recruiting:campaigns` runs every 15 minutes.

## RC2: careers page (`Modules\Recruiting\Careers`)

`/careers` on the brokerage's website, through the website's new `SiteExtensions` (routes before the page catch-all, and live pages in sitemap.xml and llms.txt). Off until the owner turns it on, and only while the website is live.

- Headline, introduction and reasons (owner edits them).
- **What would you keep here?** Deals a year, average price, today's split and cap: run through the recruiting plan with `EarnHere`, shown with its assumptions and "an estimate, not an offer". Calculator results are `noindex` and not cached.
- **In our agents' words**: only agents can add their own words (`/recruiting/my-story`), with an explicit consent box, optionally with their closings in the last 12 months; they can take them down any time. Only active users are shown.
- **Talk to us**: name, email, phone, licence, deals a year, brokerage, message, and an unticked text-consent box. Honeypot, a 3-second minimum, and 5 an hour per address. Creates (or updates, by email) a recruit with source "Careers page", an "applied" alert, and starts "After they apply".
- SEO and AEO: one H1, canonical, breadcrumbs, `JobPosting` (contractor, direct apply, the office address), `FAQPage` with three plain answers, in sitemap.xml and llms.txt.

## Not yet

- AI outreach drafts for revenue-share sponsors, retention risk for the brokerage's own agents, source attribution and recruiting ROI (RC3).
- Inviting a joined recruit straight into the roster (today: invite from People as usual).
