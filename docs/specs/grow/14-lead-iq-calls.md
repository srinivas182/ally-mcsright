# Grow 14 — Lead-IQ calls and the broker-branded assistant (LQ1)

Plan items E10 (part: AI voice within a minute, booking, warm transfer, hand-off) and D22 (part: the assistant in the brokerage's name and voice, with escalation). Builds on the AI first responder (grow/06) and website booking.

## Leader review

| Leader                    | What they do                                            | Where they fall short                                      |
| ------------------------- | ------------------------------------------------------- | ---------------------------------------------------------- |
| Ylopo (AI text and voice) | AI texts and calls new leads, then hands them to agents | Sold with its own ad programme; expensive; vague hand-offs |
| Structurely               | AI texting that qualifies leads                         | Mostly text; voice and booking limited; another login      |
| Lofty AI Assistant        | AI replies and nurture inside the CRM                   | Uneven replies; setup time                                 |
| Human ISA services        | People plus AI follow-up                                | Costly per lead; slower at night and weekends              |
| Most CRMs                 | Timed auto-texts                                        | Not a conversation; no booking; no live transfer           |

RightAlly adds: consent-safe calling, warm transfer with a spoken hand-off, booking into the agent's real calendar, Spanish on one key, plain controls and a monthly limit, all inside the CRM.

## How a call works

1. A new lead is routed. If **Calls within a minute** is on (Leads → Sources and routing), `AiCaller::queue` decides at once.
2. It does **not** call, and writes why on the lead, when: HQ has not switched calls on for the brokerage; the month's limit is reached; there is no phone; they are on Do Not Call; or there is **no written consent to automated calls**.
    - The FCC treats AI-generated voices as "artificial" voices under the TCPA, so these calls need prior express written consent. `Consent::canAutoCall` allows a call only when there is written consent on record (web form or signed, with its wording kept) whose wording covers calls, and no opt-out since. The standard RightAlly form wording covers calls.
3. Calls go out only 8am–9pm where the number is (`SendGuard::quietHours`); otherwise the job tries again every 30 minutes, for up to 14 hours.
4. The assistant says who it is and that it is an AI, in the brokerage's name: "Hi Jane, this is Ava, an AI assistant calling for Acme Homes…", then offers:
    - **1 — talk to an agent now.** The lead's agent is rung first, then up to two more agents who take lead calls. Each agent hears "RightAlly transfer: Jane Doe, buying around 78704 (via Zillow). Press 1 to take the call." and is joined only if they press 1. If nobody takes it, the person gets the link to pick a time and the lead's agent gets a text to call back.
    - **2 — a link to pick a time.** By text with written consent, otherwise by email. The page (`/book/{call}`, signed, 7 days, not indexed) shows the agent's open times (website booking hours, their RightAlly tasks and connected calendar); booking adds a call task, puts it on their calendar and alerts them.
    - **3 — Spanish** (when the language is "English, press 3 for Spanish"); the menu repeats in Spanish with a Spanish voice.
    - **9 — no more calls.** Do Not Call is turned on and recorded.
5. When the assistant will call a lead, the agent's own "press 1 to connect" speed-to-lead call is not placed at the same time.
6. A transfer the lead's agent takes counts as their response for speed to lead.

## Broker controls (the broker-branded assistant)

Assistant's name; the brokerage name as the assistant says it; female or male voice; language (English, Spanish, or English with press 3 for Spanish); most calls a month (0–10,000, default 200). The settings page shows this month's calls, transfers, bookings and leads not called. Text replies keep their existing escalation rules (a person for legal, money, Fair Housing or "talk to a person").

## Security

- Twilio's steps use signed 30-minute links; the booking page a signed 7-day link. Forged or expired links are refused.
- Calls are recorded in `crm_ai_calls` with status and the reason in plain words; every step is on the contact's timeline and audited as an automation.

## Not in LQ1

A two-way spoken conversation (the assistant answering free questions by voice) needs a real-time voice provider; LQ1 uses a keypad menu, which works on every phone and is easy for every age. A broker's photo on the assistant and the broker's own cloned voice (rest of D22) come later.

## Tests

`tests/Feature/Crm/LeadIqCallsTest.php`.
