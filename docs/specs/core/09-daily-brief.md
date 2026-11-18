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

## SX6: work without signing in

**The morning email** (7am on weekdays, as before) now starts with **Call today**: up to five people from Today's 10,
each with the reason and their number as a tap-to-call link. One button, **Open today's list (no sign-in needed)**,
opens a page that:

- works without signing in until midnight that day, through a signed link for that agent alone. A changed or expired
  link is refused;
- shows only today's list: names, reasons and Call buttons, with no notes and no other pages;
- logs each call with **We talked**, **Left a message** or **No answer**. These are buttons (a POST), never the link
  itself, so email security scanners that open links change nothing. A link can only log on that agent's own people.

**The morning text** goes only to agents who turn it on themselves ("Text me my list at 8am on weekdays", on their
brief). That choice is their consent, kept with the time and audited.

- It is sent after 8am on weekdays, and only between 8am and 9pm where their phone is.
- It goes only when HQ allows texts for the brokerage, and at most once a day. It is skipped when there is no one to
  call.
- It says how many people there are, gives the same link, and ends "Reply STOP to stop these texts"
  (`crm:morning-texts`, every 15 minutes).

**Concierge setup ("send us your spreadsheet").** Under Help, people can send their list as it is (CSV or Excel,
up to 10 MB) and say what it is: contacts and past clients, listings, or something else.

- It goes to HQ's Support queue as "Set it up for me". The file is stored encrypted, only HQ can download it, and
  every download is audited.
- RightAlly support sets it up through the brokerage's support access, so the brokerage's "ask first" rule still
  applies.
- The file is deleted 30 days after it was sent, or when the request is closed (`team:concierge-cleanup`, daily).

**For the pen test and the attorney:** the no-sign-in list page is a bearer link to today's client names and
numbers. It is limited to one agent, one day, today's list and logging calls, but the outside testers should look at
it.
