# Core 09 — The daily brief across the whole business

Sprint DB1. Teams A with D and E. Addition 1 in `docs/plan/market-2026-additions.md`; answers Lofty's "Morning
Read" with something only one product holding CRM, websites, deals, compliance and payouts can do.

## What it is

One ranked list of what needs the person, at the top of their home page (Today for agents and their assistants,
Dashboard for owners and staff) and in a 7am email on weekdays. Each item says **why** it matters, shows the
**commission at stake** when there is one, and has **one button** to the next step.

## What goes in it (each module's own rules, no AI needed)

| Module   | Agents                                                                               | Owners and staff (also)                                    |
| -------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| CRM      | New leads waiting for their answer; replies waiting; overdue tasks; Today's 10 top 5 | Agents who haven't answered a lead in over an hour         |
| Websites | Hot AIlly chats (lead score 60+) nobody has taken over                               |                                                            |
| Deals    | Contract dates due in 7 days (or late); Deal-IQ blockers and warnings on their deals | The same, across every open deal                           |
| Money    | Money ready for them (commission and revenue share)                                  | Payout runs waiting for approval                           |
| Roster   | Their licence or E&O expiring within 30 days                                         | Everyone's                                                 |
| Credits  |                                                                                      | RightAlly Credits running out within a week at current use |

Access rules are the screens' own (deals through `DealAccess`, chats through AIlly's visibility). A source that
fails is left out; the brief always shows.

## Ranking

1. **Now** (overdue, today, tomorrow, someone waiting), then **this week**, then **good to know**.
2. Within each, the most **commission at stake** first: the deal's gross commission for deal items, 2.5% of the
   lead's price for lead items (a planning figure for ranking only), the payout run's total, the agent's balance.
3. Then by title.

## The email

- 7am on weekdays in the person's time zone (else the brokerage's), at most once a day, top 10 items, only when
  something needs them. "Email it to me at 7am on weekdays" on the brief turns it off or on (on by default).
- `core:daily-briefs` runs hourly on weekdays.

## Later

- AI wording on top of the rules (one short sentence for the whole day) once AI is switched on for the brokerage.
- A text version for people who opt in (A2P registration needed).
- One-click actions with a preview (send the drafted reply, approve the run) inside the brief.

## Acceptance (DB1)

- An agent sees leads, replies, tasks, deal dates, Deal-IQ problems, money and licences in one list, ranked by
  urgency then commission at stake, each linking to its page.
- An owner also sees slow agents, payout runs to approve and low credits.
- The 7am weekday email goes once a day and can be turned off; a failing source never breaks the brief.
