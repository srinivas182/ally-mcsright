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

## Spoken AI conversations (VO1, E10)

When HQ (or a brokerage, with its own account) connects **AI voice calls** in Outside services, the keypad menu is replaced by a real two-way conversation. Providers: **Retell AI (recommended)**, Vapi or Bland AI. The keypad call stays as the fallback whenever the service is Off.

- **What the provider holds.** The AI agent's script, voice, transfer tool and calling number are set up once in the provider's account; Outside services keeps the API key (encrypted, write-only), the agent ID and the calling number.
- **What RightAlly sends** (`Crm\Voice\VoiceAgents::start`): the lead's first name, what they asked about, the brokerage name, the assistant's name, the language, the agent's first name and work phone (for the live transfer) and the signed booking link. Nothing else: no email, notes, price history or other contacts.
- **Same rules as the keypad call.** Written consent to automated calls, never Do Not Call, 8am–9pm where they are, the monthly limit, and the AI says it is an AI in the brokerage's name (configured in the provider's script).
- **Test mode** calls only the listed test numbers; anyone else is skipped with "AI voice calls are in Test mode" on the lead. Live is allowed only after a passing Test connection check.
- **After the call** a job (`PollVoiceCall`) asks the provider every two minutes how it ended (for up to an hour): talked, passed to the agent, or no answer. The transcript is turned into the **agent's hand-off note** by AI (`crm.call_summary`): a two-or-three-sentence summary, the next step, when to follow up, and whether they asked for a person or for no more calls. The transcript is untrusted data; spoken instructions are ignored.
- **The note goes where the agent works:** on the contact's timeline, a follow-up task (today, tomorrow or next week) when one was agreed, Do Not Call when they asked for no more calls, and an email to the agent. Transcript and note are stored encrypted on the call (`crm_ai_calls.transcript`, `summary`) and shown under **Calls with notes** on the contact.

## Recorded calls with automatic notes (VO1, D08)

On any contact, **Call and take notes for me**: RightAlly rings the agent's own mobile first; when they answer, it calls the client from the brokerage number.

1. **Everyone is told.** The agent hears "They will hear that the call is recorded for notes". Before the client is connected they hear, in English and Spanish: "This call is recorded so I can keep accurate notes. If you prefer, hang up and I will call you back without recording." This meets all-party-consent states (California, Florida, Pennsylvania, Washington and others), where everyone must be told.
2. **Not allowed** when call notes are off (the transcripts service), calls are off for the brokerage, the agent has no mobile number, the client has no number, is on Do Not Call, or it is outside 8am–9pm where they are. The reason is shown in place of the button.
3. **After the call** the recording is written down by the transcripts provider chosen in Outside services: **Deepgram (recommended)**, AssemblyAI or ElevenLabs Scribe; speakers are told apart and English or Spanish detected. The AI writes the notes (same `crm.call_summary` prompt), which are logged as a connected call with its length, plus a follow-up task and an email to the agent.
4. **The audio is deleted** from the phone provider as soon as it is written down; only the transcript and notes are kept, encrypted. Calls under five seconds or unanswered are not written down, and their recordings are deleted.
5. If writing down fails, the agent sees **Try again** or **Delete the recording** on the contact; nothing is kept without the agent's choice.
6. Twilio's steps use signed 3-hour links (`/webhooks/calls/recorded/{call}/{agent|client|done}`); forged links are refused. Each start is audited.

### Defaults to review (money or law)

- Recording announcement wording, and announcing to everyone in every state (even one-party states): attorney review recommended.
- AI voice calls follow the same written-consent rule as the keypad call (the FCC treats AI voices as artificial voices).
- Transcription and AI notes are charged as one AI credit per call (`ai.crm_call_summary`); transcription minutes are paid from HQ's transcripts account.
- A recorded call is counted as 5 call minutes when placed; Twilio's real length settles it.

## Not yet

A broker's photo on the assistant and the broker's own cloned voice (rest of D22, decided 2026-11-15: no broker branding on the back-office assistant). Recording in-person appointments with coaching comes in VO2.

## Tests

`tests/Feature/Crm/LeadIqCallsTest.php`, `tests/Feature/Crm/VoiceCallsTest.php`.
