# Engage 01: Game plan, AI coach, agent activity, database assistant, recognition messages (EN1)

Status: built in EN1. Tracker rows D18, D21, D23 and E21. Module: `modules/Engage`.

## What the market leaders do

- **Daily plans**
    - **BoldTrail (kvCORE)** has "Smart Campaigns" and a hotlist.
    - **Follow Up Boss** has smart lists and an action plan.
    - **Lofty** has an "AI Assistant" that emails leads on its own.
- **Coaching** is a monthly human call.
- **Brokerage-side signals.** **Brokermint** and **Lone Wolf** show production, not engagement. Retention tools
  (for example **Real Estate Exchange**) sit outside the brokerage's platform.
- **Recognition** is a Slack shout-out or a plaque. Some vendors offer AI voice clones with unclear consent.
- **Where they fall short:**
    - AI that sends emails without the agent reading them.
    - Replies are read by connecting the agent's whole mailbox.
    - Coaching is not tied to the day's real work.
    - Brokers learn an agent is unhappy when they resign.
    - Cloned voices are used without clear, narrow consent.

## What RightAlly does

### Game plan and AI coach (menu: Overview › Game plan; D18)

**One page for today**

- people to call from Today's 10, against the goal planner's daily target;
- tasks due and late;
- the ranked daily brief;
- the five-minute practice drill and its streak (Learn).

**"Coach me on today"**

- Returns one focus, 3–5 time blocks for the day, and one encouraging line.
- **The AI sees counts only:** calls, tasks, kinds of brief items, drill done and weekday. It never sees names,
  numbers or notes.
- Costs 1 credit and is kept for the day.

### Agent activity and retention alerts (menu: People › Agent activity, owners and staff; D21)

**What the broker sees for each active agent**

- last time they used RightAlly;
- calls, texts and emails in the last 7 days;
- tasks done in the last 7 days;
- deals opened in the last 30 days;
- courses finished in the last 90 days;
- practice sessions in the last 30 days;
- the "keeping agents" signals with plain reasons.

**Counts only.** The broker never sees messages or practice here.

**Mondays.** Owners get "agents to check in with" by email, using the same rules as Keeping agents (a recent check-in
hides an agent for 60 days). Agents never see this.

### Database assistant by email (D23)

**Setting it up.** The agent turns it on and chooses past clients, sphere or both, and how many emails a week (1–20,
default 5).

**Each Monday, AI drafts check-in emails** to people who:

- are the agent's own contacts;
- have an email address;
- have not been in touch for 90 days;
- have not been emailed by the assistant in the last 90 days;
- have not unsubscribed.

The emails are short and personal: one genuine question, no pitch, no prices, no market claims.

**Nothing is sent without the agent.**

- They read, can change, and press **Send from my Inbox**.
- Every Inbox check applies: unsubscribes, Fair Housing wording, and the email signature.

**Replies** come back through the conversation's own reply address. **We never read anyone's mailbox.**

**When a reply arrives, AI reads what the person wants:** buy, sell, refer, a question, not now, or stop emailing.
The agent then gets:

- a one-line brief;
- a task due today (high priority for buy, sell or refer);
- a suggested reply with one or two qualifying questions to edit and send;
- an email.

**"Stop emailing me"**, in any words, is recorded as an email opt-out straight away, and no task is made.

**Email only.** Texts and AI calls need the person's written consent and are not done by the assistant.

### Recognition messages from the broker (E21)

**Milestones listed for the broker**

- work anniversaries;
- a first closing;
- 5, 10 and 25 closings this year;
- a finished New Agent Launch.

Each milestone comes with a suggested message, from the last or next 14 days, and is not repeated once sent.

**Sending.** The broker sends the words plus either:

- **their own recording** (recorded in the browser), or
- **their AI voice**, only after a **separate signed and recorded consent**. That consent covers recognition messages
  to their own agents only, never clients, calls, texts or marketing. It is not the Ally Studio narration consent.

**Labelling.** Every AI-voiced message tells the agent it is an AI copy of the broker's voice, made with consent.

**Control.** The broker can delete the voice at any time; it is deleted at the voice service too. Nothing is sent
on its own.

## Security and privacy

- **Encryption.** Check-in emails, briefs, suggested replies and coach notes are encrypted at rest.
- **Who sees what.**
    - Check-ins are the agent's own; others get a 404.
    - Recognition audio plays only for the sender and the recipient, and is never cached.
- **Voice set-up** needs the password to be confirmed. Consent text, IP and a hash of the recording are kept.
- **Untrusted content.** Reply text and the agent's "about this season" note go to AI as untrusted content.
- **Audit log.** Saving the assistant, replies read, voice consent and deletion, and recognitions sent are all
  recorded.
- **Credits.**
    - The coach costs 1.
    - Each check-in draft costs 1, and each reply read costs 1. These run as automation.

## Not in this sprint

- Auto-sending check-ins with no approval. This is intentionally not offered.
- Text-message check-ins.
