# Grow 06 — AI first responder (Sprint 16B-1)

Status: **Approved November 2026.** Part of Lead-IQ (grow/02). Leader review: Ylopo AI (text and voice),
Structurely, Lofty AI assistant, Follow Up Boss + add-ons.

| Capability                                    | Leaders                   | RightAlly 16B-1                                                                                                                   |
| --------------------------------------------- | ------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Answers new leads within a minute, 24/7       | ✓                         | ✓ queued the moment the lead is routed                                                                                            |
| Qualifies (intent, timeline, area, budget, …) | ✓                         | ✓ kept on the lead as it learns; shown to the agent                                                                               |
| Hands over to a person                        | ✓ (rules vary)            | ✓ asks for a person (detected in code, no AI needed), qualified, price/terms, legal or money questions, unhappy, message limit    |
| AI disclosure                                 | varies                    | ✓ always in the first message                                                                                                     |
| Consent                                       | add-on dependent          | ✓ texts only with written consent, else email; same checks as the inbox (quiet hours in their time zone, STOP, DNC, Fair Housing) |
| Stops when the agent writes                   | some                      | ✓ immediately and for good                                                                                                        |
| Hand-off brief                                | ✓                         | ✓ summary, what it learned, a suggested next message; the agent is alerted and the missed-reply nudge starts                      |
| Runs inside the CRM, deals and commissions    | — (add-ons to other CRMs) | ✓                                                                                                                                 |

## Rules

- Brokerage settings (Leads → Sources and routing): on/off, assistant name, tone, most messages per lead (default 6),
  which lead sources, and brokerage facts it may use. Off by default.
- Not for returning contacts (they have an agent), pond leads or sign-code leads.
- AI messages do not count as the agent's response for speed to lead; the response window still applies.
- A reply with Fair Housing wording, an empty reply or an AI outage is never sent: the lead goes to the agent.
- At night where the lead is, with no email address, it tries again later (queue) — or hands over when it cannot.
- Every message is stored in the inbox (marked AI) and on the timeline; qualification, summary and suggested
  message are encrypted.

## Next (16B-2)

Lead scoring with reasons, return on spend by source and channel (including AI search and SEO) to commission,
and the broker speed scorecard.
