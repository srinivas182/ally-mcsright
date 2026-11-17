# Outreach: booking links, video messages, call queue, texting registration (MK1)

Status: Done. Tracker: D08 and D09 are Partly (see "Not in this sprint").

## What it does, in plain words

Everything lives under **Grow → Outreach**, as four big tiles.

- **My booking link**: `yourbrokerage.com/meet/your-name`.
    - People see only the times the agent is free: their booking hours, minus their tasks and their connected Google or Microsoft calendar.
    - They pick a time and leave their name, email and phone, with an optional, unticked texting consent box.
    - The person becomes a lead assigned to that agent, and the time becomes a task and a calendar event.
    - A time that was just taken is refused politely.
- **Video messages**:
    - The agent records in the browser (camera and microphone, up to 3 minutes) or uploads a video (MP4, WebM or MOV, up to 150 MB), and gets a private link on the brokerage website.
    - The page plays the video with the agent's Call and Reply buttons.
    - The agent sees how many times it was watched. The first view goes on the contact's timeline ("Watched your video …").
- **Call queue** (a power dialer done safely). The agent picks a list (any smart list) and works through it one person at a time.
    - Each card shows the contact's last notes and their local time, with a big **Call** button that dials from the agent's own phone.
    - One tap logs the outcome: Talked, Voicemail, No answer, Wrong number, or Asked not to be called.
    - The queue skips, and says how many it skipped: Do Not Call contacts, contacts with no phone number, contacts already called today, and anyone for whom it is before 8am or after 9pm in their own time zone.
    - "Asked not to be called" marks the contact Do Not Call for the whole brokerage, and is audited.
- **Texting registration (A2P 10DLC)**, for owners and staff. US carriers block business texts from unregistered senders.
    - The brokerage answers plain questions: legal name, kind of business, EIN, website, address and contact.
    - RightAlly pre-writes the use case, two sample messages (with "Reply STOP") and the opt-in description, matching how RightAlly actually sends texts.
    - Answers can be saved for later or sent. HQ submits them through Twilio Trust Hub and records "Approved" or "Needs changes" with a note.
    - The EIN is encrypted and only its last 4 digits are ever shown again.

## Market leaders

| Leader                                 | What they do                   | Where they fall short                                                       | What we do                                                                                  |
| -------------------------------------- | ------------------------------ | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Calendly / Lofty booking               | Booking pages                  | A separate app; bookings don't become CRM leads assigned to the right agent | On the brokerage website, booked as a lead and task for the agent, with their real calendar |
| BombBomb                               | Video email with view tracking | Separate subscription; videos hosted on BombBomb's domain                   | Videos on the brokerage's own website, views logged on the contact's timeline               |
| Mojo / Vulcan7 / Follow Up Boss dialer | Power and triple-line dialers  | Autodialers raise TCPA risk; DNC and hours are left to the agent            | One-at-a-time manual calls; Do Not Call and 8am–9pm are enforced automatically              |
| Twilio, or every CRM's 10DLC form      | Raw registration forms         | Agents don't know what a "campaign use case" is, so rejections are common   | Plain questions, carrier-ready wording pre-written, status in plain words                   |

## Compliance (TCPA, carriers, privacy)

- No autodialer and no prerecorded calls. The agent places every call.
- Do Not Call is respected everywhere, and calling hours (8am–9pm) use the contact's own state. Contacts with no state use the brokerage's time zone.
- The booking form's texting consent is unticked and uses the standard consent wording.
- Video and booking pages are `noindex, nofollow`, `private, no-store` and `no-referrer`. Video files are never on a public URL; they are streamed through the page's token. Only the agent who recorded a video can remove it.

## Not in this sprint (honest)

- **Call recording** needs a consent announcement and two-party consent rules by state. It is planned with the phone-system work.
- **Market report emails** to a list are planned with MK2's monthly content pack. Drips, alerts and home-value reports were already live.
- **National Do Not Call registry scrubbing** needs a DNC data provider, to be connected from Settings later.

## Settings and review

- Video limits (3 minutes, 150 MB) and calling hours (8am–9pm local) are defaults to **review**.
