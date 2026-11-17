# Grow 16 — Database health, commission forecast and database value (DH1)

Plan items E24 (CRM database health and pipeline commission forecast) and E25 (database value dashboard and proof report). Most of an agent's business comes from people they already know, yet most databases are full of duplicates, people with no way to reach them, and leads nobody is following up. DH1 makes that visible in one score, one click from the fix, and shows what the database is worth in dollars.

## Leader review

| Leader             | What they do                                                | Where they fall short                                                           |
| ------------------ | ----------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Follow Up Boss     | Smart lists, duplicate merge, deal pipeline with commission | No health score; the pipeline shows commission but no forecast range or reasons |
| Lofty (Chime)      | "Database health" style alerts, AI assistant nudges         | Busy screens; numbers without the reasons behind them                           |
| kvCORE / BoldTrail | Smart CRM, behavioural alerts, back-office pipeline         | Hard for agents to find; brokers can't see database value by agent              |
| Real Geeks / CINC  | Lead sources and conversion reports                         | Reports focus on paid leads; the sphere's value is invisible                    |
| Cloze / Wise Agent | Relationship nudges ("haven't talked in a while")           | No dollar value and no brokerage view                                           |
| Revaluate / Fello  | Likely-mover scores on your database                        | Sold separately, on top of another CRM                                          |

RightAlly matches: smart lists, duplicate merge, likely-seller scores, pipeline commission. RightAlly adds:

- **One health score** with each problem counted and a button to the people behind it (no setup).
- **Forecast as a range with its reasons**, not a single made-up number: deals under contract, people being actively helped, likely sellers, each with the rate used.
- **Database value in dollars** from the brokerage's own history (or a stated assumption until there's enough), and **proof**: the last 12 months' business by where the client came from: database, paid portals and ads, or website and signs.
- **Broker view by agent**, sorted with the weakest database first, so coaching goes where it matters.
- A monthly email on the 1st and a line in the daily brief when health is under 90%.

## Health (`Modules\Crm\Health\DatabaseHealth`)

For an agent: contacts they own. For the broker: everyone's. Archived contacts don't count.

| Check                                       | Weight | Fix                                            |
| ------------------------------------------- | ------ | ---------------------------------------------- |
| Duplicates (same email or phone)            | 1      | Merge them (`/contacts/duplicates`)            |
| No email or phone                           | 1      | Smart list "No email or phone"                 |
| Not contacted in 6 months (older than 30 d) | 2      | Smart list "Not contacted in 6 months"         |
| Leads with no next step (no task, no plan)  | 2      | Smart list "Leads with no next step"           |
| Past clients with no closing date           | 1      | Smart list "Past clients with no closing date" |

Score = 100 × (1 − weighted share of contacts with each problem). The four new ready-made smart lists use the new `health` filter in `SmartLists`.

## Forecast and value (`Modules\Insights\DatabaseValue`)

- **Forecast** for 30, 60 and 90 days: deals under contract by closing date (low 85%, high 100%); people being actively helped (10–30% close in 90 days); likely sellers, seller score 70+ (2–8% list in 90 days); the last two scaled to the window. Average commission is the last year's (three or more deals) or the planner's defaults. Every rate appears on the page.
- **Value**: past clients and sphere × deals a year each × average commission. The rate comes from the last two years' deals from past clients, sphere and referrals when there are three or more; otherwise 1 deal a year for every 12–13 people (8%), stated as an assumption.
- **Proof**: the last 12 months' closed deals and commission grouped by the client's source.

## Where it shows

- `/insights/database` (nav: Overview → Database, owners and agents; `insights/database`).
- Daily brief: "Your database is 61% healthy — fix 5 things" when under 90%.
- Monthly email (`DatabaseReportMail`) on the 1st from `insights:daily`: owners get the brokerage, agents their own; nobody with an empty database.

## Tests

`tests/Feature/Insights/DatabaseHealthTest.php`.

## Not in DH1

- Email and phone validation, licensed fill-in of missing details, and "moved" flags from property data (rest of E24; needs a data partner).
- Forecast rates learned from the brokerage's own conversion history (stated assumptions for now).
