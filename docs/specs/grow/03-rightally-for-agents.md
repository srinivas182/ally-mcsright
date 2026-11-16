# Grow 03 — RightAlly for Agents (Team E)

Status: **SO1 built** (solo plans and signup, simple view by default, portability guarantee, goal planner). SO2 next:
reactive follow-up, ready-made follow-up plans, solo website templates. Works with the brokerage-partner and agent-pays model (`specs/core/07`).

## Approved additions (leader review: Lofty, Follow Up Boss, kvCORE solo plans)

1. **Ten-minute self-serve start**: sign up, add a card, import the old CRM with notes, Today's 10 on day one.
2. **"Ask my broker" answered by AI first** from the brokerage's policy manual; escalates to the broker
   only when needed.
3. **Portability guarantee**: an agent's personal sphere is theirs, exportable at any time.
4. **Goal planner**: "I want 24 closings" → the calls and appointments needed, feeding Today's 10.

## SO1 (built)

### Leader review

| Leader                   | What they do                                               | Where they fall short                                                                                                     |
| ------------------------ | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Follow Up Boss           | $69/month one-user plan, calling and texting, action plans | No IDX website or built-in AI lead response; time-based plans; API only on the $499 tier                                  |
| Lofty                    | Website, CRM, drips and an AI assistant                    | No public prices (an industry estimate is $400–600/month for solo agents), possible setup fee, 10–15 hours to build drips |
| kvCORE / BoldTrail       | Full suite, usually paid by the brokerage                  | Agents often lose their contacts when they leave; heavy to learn                                                          |
| Budget CRMs (Wise Agent) | Cheap                                                      | Dated, no websites or deal tools, little AI                                                                               |

### What was built

- **Solo plans and signup.** Packages are for brokerages or for solo agents (`billing_packages.audience`; HQ sets it). `/agents` on the RightAlly host shows solo plans with Product/Offer and FAQ markup and takes signups; the first payment creates a solo RightAlly (`TenantType::Solo`), invites the agent, starts the subscription and a solo go-live plan (sign in, bring people, set a goal, bring deals, website, first deal, first close). Brokerages can't buy solo plans and solo agents can't buy brokerage packages.
- **Simple view by default.** In a solo agent's RightAlly the view is simple until the person chooses (`SimpleMode::mode`); brokerage people are still asked once.
- **Portability guarantee.** "Download my people" (contacts page, `/my-people/download`): the agent's own personal contacts with notes and tags, in the same columns the RightAlly spreadsheet import reads; cells that spreadsheets would run as formulas are neutralised. When an agent is deactivated and has people of their own, they are emailed a private link (`/take-my-people/{token}`, 30 days, 5 downloads, only the hash stored; opening the page uses no download). Company contacts — from the brokerage's own site, ads and lead sources — stay with the brokerage. Both are audited.
- **Goal planner, the short way.** "How many closings this year?" on Today (`POST /insights/plan` with `closings`) uses the business planner (K3) backwards to conversations a working day. Today lists that many people (at least 10, at most 25) with a progress bar; the daily brief says how many conversations are left today, and asks solo agents without a goal to set one.

Tests: `tests/Feature/Grow/RightAllyForAgentsTest.php`.
