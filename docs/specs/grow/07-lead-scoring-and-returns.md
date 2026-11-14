# Grow 07 — Lead scoring, return on spend and speed scorecard (Sprint 16B-2)

Status: **Approved November 2026.** Leader review: Follow Up Boss, Lofty, kvCORE/BoldTrail, Sierra Interactive,
Ylopo, Real Geeks.

| Capability        | Leaders                             | RightAlly 16B-2                                                                                             |
| ----------------- | ----------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Lead score        | ✓ (mostly a number)                 | ✓ 0–100, hot / warm / cold, **with the reasons**                                                            |
| Source report     | ✓ leads, contact rate, appointments | ✓ to **closed deals, commission and company dollar**, with spend: cost per lead, cost per closing, return × |
| SEO and AI search | —                                   | ✓ channels as their own rows (AI search, organic, paid, social, portal, email, other sites, direct)         |
| Agent speed       | ✓                                   | ✓ median, % within the brokerage's target, passed-on leads, replies waiting, AI hand-offs not followed up   |
| Slow agents       | —                                   | ✓ on the broker Risk page (5+ leads in 30 days below target)                                                |
| Hot-lead alert    | some                                | ✓ email and text the moment a lead turns hot; top of Today's 10                                             |

## Score

Starts at 10. Inquiry: sell +15, buy +5, a property +10, a price +5, a message +5, email and phone +5.
Engagement: replied +20, replied within 10 minutes of our first message +10, 3+ replies +10, asked to see a home
+15, written texting consent +5. AI findings: pre-approved +15, moving within ~3 months +15, wants a showing +15,
already has an agent −40. No reply in 30 days −20; opted out of texts −15. Capped 0–100; hot ≥ 70, warm 40–69.
Rescored on intake, on every reply and AI finding, and nightly (`crm:score-leads`). The contact's opportunity
score uses the lead's score and top reason.

## Return on spend

Spend is entered per month for a source (Zillow, Facebook, …) or a channel (`channel:organic` for SEO work,
`channel:ai_search` for AEO work). A deal counts for the source and channel of the contact's first lead in the
period (first touch) if the deal started after it. Company dollar comes from the final commission calculation.
Deals and commission are read through the core `DealOutcomes` interface (Transactions implements it).

## Scorecard

Per agent for the period: leads (including ones passed on), contacted, median minutes, % within target (target set
by the brokerage, default 5 minutes for 80%), passed on, replies waiting now and over an hour, AI hand-offs not yet
followed up. Per source: median and % within target.
