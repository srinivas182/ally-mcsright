# Guided listing appointments, buyer consultations, and "agreement needed" (AP1)

Status: Done. Tracker: B27 Partly (consented recording of the appointment is left).

## What it does, in plain words

**Grow → Appointments.** Choose "Listing appointment" or "Buyer consultation" and the client, then go step by step together on a laptop or tablet. Every step saves as you go.

- **Listing appointment** (six steps):
    1. **The home**: prefilled from the contact's address.
    2. **The price**: the seller's CMAs with their likely range, and the list price you agree.
    3. **What you walk away with**: mortgage payoff, listing commission, any buyer's-agent offer, and the state's typical closing costs give "You walk away with about $…". This is the same net sheet as CM1.
    4. **How we'll market it**: a checklist (photos, staging, MLS, its own web page and QR code, open house, social, showing feedback).
    5. **The listing agreement**: commission % (marked negotiable) and term. Buyer's-agent compensation is optional and the seller's choice, and never put on the MLS.
    6. **Next steps**: **Create the listing** makes the listing deal, with its agreement end date for the Listings board, and opens it. **Email the seller** sends the summary.
- **Buyer consultation** (five steps):
    1. **What you're looking for**.
    2. **Budget and financing**: pre-approval and lender.
    3. **How I'm paid**: the buyer agreement, as a percent of the price or a dollar amount.
        - It's refused if open-ended or zero (2024 NAR settlement: "objectively ascertainable").
        - It also records the term in months, whether it's exclusive, and the area.
    4. **Signing**: record the date and the signed PDF. The agreement end date is worked out from the term.
    5. **Next steps**: the buyer guide email, which explains the agreement in plain words, including that the buyer never pays more than agreed.
- **"Agreement needed" guard.**
    - Scheduling a **showing** task for a buyer (a contact with a buyer consultation or a "buy" lead) is stopped until a signed, unexpired buyer agreement is recorded. The stop explains why in plain words.
    - Sellers and other contacts are never stopped.
    - The guard is a Core contract, so other modules can ask the same question.

## Market leaders

| Leader                    | What they do          | Where they fall short                                                  | What we do                                                                       |
| ------------------------- | --------------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Cloud CMA / Remine        | Listing presentations | A slide deck, not a guided conversation; nothing is created at the end | A guided appointment that ends with the listing deal and the seller's summary    |
| Follow Up Boss / kvCORE   | CRM tasks for tours   | No check that a buyer agreement exists (post-settlement risk)          | A showing can't be booked for a buyer without a signed, unexpired agreement      |
| Dotloop / Lone Wolf forms | Buyer agreement forms | The form alone; agents still write open-ended compensation             | Compensation must be a specific percent or amount, with plain-words explanations |

## Compliance

- Commissions are shown as negotiable and not set by law. Buyer-agent compensation offers are never placed on the MLS.
- Buyer agreements must state a specific amount or rate, and a term (NAR settlement, effective August 17, 2024). This is a guardrail, not legal advice; use your state's approved form.
- Signed copies are private to the agent and their managers. Timeline entries record each step.

## Recorded appointments, notes and coaching (VO2, B27)

An optional **Record this appointment** panel sits under the steps.

1. **Consent first, from everyone.** The agent reads a short script aloud ("…I'd like to record our conversation so I can keep accurate notes and get coaching… Is that all right with everyone? If not, I won't record."), types the names of everyone present who agreed, and ticks "I read it aloud and everyone here agreed". The wording, names, time and IP address are kept as proof (`appt_recordings`), and a timeline entry is added. This meets all-party-consent states, because every person present is asked. Only the agent running the appointment can record it.
2. **Recording in the browser.** On a phone, tablet or laptop, the audio is sent in 30-second pieces while recording, so a dropped connection loses seconds, not the meeting. Pieces must arrive in order; a retried piece is accepted once. The limits are 3 hours and 10 MB per piece.
3. **Notes and coaching.** On **Stop**, the audio is written down by the transcripts provider in Outside services (Deepgram recommended, AssemblyAI or ElevenLabs). The AI (`appointments.coaching`) then writes two things:
    - **notes for the file:** a summary, what the client wants, concerns raised with how well they were answered, and the follow-ups the agent promised;
    - **private coaching:** up to three strengths, three suggestions for next time, the share of the time the agent talked (counted from the transcript's words, not guessed), and any **Fair Housing reminder** with the quoted remark.
      The transcript is untrusted data, so spoken instructions are ignored. The audio pieces are deleted as soon as it is written down; the transcript and notes are kept encrypted. The agent gets an email when the notes are ready.
4. **Who sees what.** The agent sees everything. A manager who can open the appointment sees the notes, but not the coaching, talk share or transcript until the agent chooses **Share coaching with my broker**; the agent can stop sharing at any time. The agent can delete a recording and its notes. A privacy erase of the contact removes them too.
5. **My coaching** (`/appointments/coaching`) shows, over the agent's last ten recorded appointments, how much they talk on average, the concerns that come up most, and what to work on. Only the agent sees it.
6. If writing down fails, the audio is kept until the agent chooses **Try again** or deletes it.

Market check: Gong and Chorus-style conversation intelligence is common in sales but rare in real estate. Most real estate tools record only phone calls, keep the audio, and give coaching only to managers. RightAlly records in-person appointments too, gets consent from everyone, deletes the audio, keeps coaching private by default, and adds Fair Housing reminders.

### Defaults to review

- The consent script wording (attorney review recommended).
- Coaching is private to the agent by default.
- Two AI credits per recorded appointment (`ai.appointments_coaching`).

## Settings and review

- Default listing commission (3%), buyer-agent compensation (2.5%) and the default terms (6 months for listings, 3 for buyers) come from Pricing defaults and are marked for **review**.
