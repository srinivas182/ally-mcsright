# Insights 01: reports and KPIs (Team A with B, Sprints K1–K3)

Status: **K1 delivered (2026-11-16)**. K2 (report builder and "Ask your numbers", with anomaly alerts) and K3 (business
planner and the monthly ROI report) are next. Covers plan items A26 and A22. Module: `modules/Insights` (depends on
core, transactions, crm and compensation).

## Leader review (November 2026)

| Product                                 | Strengths                              | Gaps                                   |
| --------------------------------------- | -------------------------------------- | -------------------------------------- |
| Sisu                                    | Agent scoreboards, goals, competitions | Separate tool fed by syncs; extra cost |
| Lone Wolf (Foundation broker dashboard) | Real-time back-office dashboard        | Fixed views; little drill-down         |
| Brokermint / BoldTrail Back Office      | Many standard reports                  | No planning or coaching                |
| Follow Up Boss                          | Lead and agent activity                | CRM only                               |

RightAlly's approach:

- **One dataset:** deals, commission, leads and people are already in one place, so nothing is synced in.
- **Drill-down:** every number opens the deals behind it.
- **Role scopes:** team and office views come from the R1 scopes.
- **Hidden pay:** pay follows the R1 field hiding.
- **Coaching:** agents get a coach-style dashboard, not just charts.

## K1: dashboards (delivered)

### Who sees what

- **Permission.** `insights.view` ("See brokerage numbers"), scoped own, team, office or everyone.
- **Everyone's numbers:** owners and staff without a role.
- **By template:**
    - managing broker: office;
    - team lead: team.
- **Agents without the permission** go straight to their own dashboard.
- **Hidden pay.** Roles without `finance.amounts.view` see sides and volume, never GCI, company dollar or caps, on
  every number and drill-down.

### Broker dashboard (`/insights`, menu "Numbers")

- **Periods:** year to date, this quarter, this month, last 12 months, last year.
- **Tiles:** closed sides, volume, GCI and company dollar (from each deal's commission calculation, including post-cap
  fees), each compared with the same period last year.
- **Goal:** the brokerage's yearly GCI and sides goal, with year-to-date progress and on-pace share. Owners set it.
- **Closed by month** (12 months) as a chart with an accessible table. Clicking a bar opens that month's deals.
- **Pending:**
    - deals under contract, with volume;
    - deals closing in the next 30, 60 and 90 days, with the company dollar expected;
    - each bucket opens its deals.
- **Top agents** (up to 10). Each opens the agent's dashboard or their deals.
- **People:** active agents, joined in the period, and left in the period (deactivated).
- **Lead sources back to commission** (everyone scope only): leads, closings, company dollar and return on spend, from
  the CRM lead report.

### Drill-down

- `GET /insights/deals?metric=closed|pending&range…|month=YYYY-MM&agent=&within=30|60|90` returns the deals behind a
  number (up to 500), each linking to the deal.
- Scope and commission hiding apply, and asking about someone outside the viewer's scope is refused.

### Agent success dashboard (`/insights/me`, menu "My numbers"; managers open `/insights/agents/{agent}`)

- **This week:**
    - leads waiting for a first reply;
    - closings in the next 7 days with documents still needed;
    - overdue tasks;
    - people due a check-in (relationship rhythm);
    - "behind your goal pace" with a nudge.

    Each item links to where the agent acts.

- **The year:** year-to-date sides, volume and GCI against their own goal and last year, with on-pace status. The
  agent sets their goal; K3's planner turns it into weekly activity.
- **Cap progress** from their compensation plan.
- **Speed to lead** over the last 90 days (median minutes and the share within the brokerage's target), and their
  follow-up habits.
- **On the way:** their open deals with closing dates.

### Data

- **`insights_goals`:** tenant, user (null for the brokerage), year, GCI, sides, volume.
- **Audit:** every goal change is audited.

### Tests

`tests/Feature/Insights/InsightsTest.php`:

- **Broker dashboard:** totals, last year, months, pending buckets, top agents and people.
- **Drill-down:** by period, month, agent and pending window.
- **Goals:** brokerage and agent.
- **Team lead:** scope limits, hidden pay and refused drill-downs.
- **Agent dashboard:** this week, goal pace, and their own drill-down only.

## K2: report builder and "Ask your numbers" (next)

- **Report builder:** subjects (deals, contacts, leads, agents, payouts), columns including custom fields, filters,
  grouping and totals. Reports can be saved, shared with roles, emailed on a schedule, and exported to CSV. R1 scopes
  and hidden fields apply.
- **Ask your numbers:** plain-English questions answered from the same queries, with the deals behind each answer.
- **Anomaly alerts** through the notification centre, for example a lead source's conversion halving, or the pipeline
  falling below last year.

## K3: business planner and ROI report (next)

- **Business planner:** income goal, then the closings, appointments and conversations needed, using the agent's own
  conversion rates. Weekly targets are compared with actual activity.
- **Monthly ROI report:** an email to owners covering time saved by automation, payout speed, lead response, and
  closings attributed to RightAlly tools.
