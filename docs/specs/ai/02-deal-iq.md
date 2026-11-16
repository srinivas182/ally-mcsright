# AI 02 — Deal-IQ: the AI transaction coordinator (Teams D, B, C and A; see sprint split)

Status: **Module definition approved.** The detailed sprint specification (screens, data model,
APIs) is written before Sprint 32, once Transactions, Forms & e-Sign and DocIQ exist.

## Purpose

Deal-IQ does the work of a transaction coordinator for every deal, from accepted contract to closing,
and makes the agent's life easier: agents **talk or type** to it, it reads the contract, keeps every
deadline, drafts the paperwork, chases every party and answers clients around the clock under the
agent's brand. Agents stay in control: nothing is sent or signed without their approval.

Formerly "AI Transaction Coordinator" in the module catalogue. Positioned as
**"Deal-IQ — your AI transaction coordinator"**, alongside DocIQ.

## Sprint split

| Team and sprints | Delivers                                                                                                                                                                             |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D, 32–36         | Core: talk or type (voice and text), intake by upload, email, photo or voice, contract and addendum drafting, client concierge, all-party portal, deal health in the daily game plan |
| D, 37–38         | Deal paperwork without inbox access (synced platforms, uploads, requests) and the smart timeline engine with state rule packs                                                        |
| D, 39–40         | Multiple-offer management; inspection response and repair requests                                                                                                                   |
| B, 53–54         | Continuous compliance scan, deadline escalation ladder, money milestones and wire-fraud safeguards, TC team mode, results dashboard, post-closing archive                            |
| B, 55–56         | Special deal types (new construction, lease, short sale and bank-owned, probate and estates, 1031 exchange); MLS status compliance reminders                                         |
| C, 53            | Listing-to-contract coordination; vendor scheduling and closing-day logistics                                                                                                        |
| A, 53            | Client concierge in Spanish (multilingual concierge)                                                                                                                                 |

## Delivery plan (approved November 2026)

Leader review: Lone Wolf BoldTrail BackOffice ComplianceAI (required forms, blank fields, signatures and
initials, inconsistencies; report on submission), SkySlope audits, ListedKit Ava (contract reading, timelines,
intake compliance scan), MaxHome.ai (addendum-driven document requests, commission reconciliation). Approved
additions: read every upload and file it; compliance scan; required documents triggered by contents;
commission reconciliation; **continuous** scan with a broker-ready score; **cross-checks against RightAlly's own
data** (licence and E&O on the contract date, form edition, signature certificate, CDA vs plan vs trust money);
evidence on the page with one-click fixes; the brokerage's plain-English rules; finding accuracy tracking;
wire-instruction detection with payout holds.

| Sprint | Delivers                                                                                                                                                                                          |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 15A    | DocIQ reading (specs/ai/03): read, OCR, split and file, facts with checked quotes, required documents, safe copy, ask                                                                             |
| 15B    | Compliance scan: findings graded blocker / warning / info with page evidence and fixes, broker-ready score, cross-checks against RightAlly data, plain-English brokerage rules, accuracy tracking |
| 15C    | Money and wire fraud: settlement statement vs contract vs CDA vs trust ledger, wire-instruction alerts, payout holds                                                                              |
| 15D    | Deadlines that cannot be missed: escalation ladder, at-risk prediction, broker risk view                                                                                                          |

## Delivered in Sprint 15B — compliance scan

- **Continuous**: every deal is re-checked when its file changes (upload, DocIQ read, review decision, status
  change, facts applied) and on "Check again". All checks are code (no AI), so the same file always gets the
  same findings.
- **Findings** graded blocker / warning / note, each with **evidence** (document, page and quote, opened as the
  page with the words boxed in red) and a **fix** (upload to the item, use the document's value, safe copy,
  open the roster or the packet, broker approval). Findings keep their identity across scans: fixed ones
  resolve, dismissed ones stay dismissed, a problem that returns reopens.
- **Built-in checks**: required checklist items for the deal's stage; items sent back for a fix; blank-looking
  price, date and name fields; price, closing date, address, buyer and seller names that disagree with the deal
  (counters and amendments excepted); dates in the wrong year; contracts signed elsewhere with no e-signature
  record; buyer agreement on buyer deals under contract (NAR settlement); wiring instructions; Social Security
  and bank numbers (share the safe copy). **Cross-checks against RightAlly's records**: the agent's licence for
  the deal's state and E&O on the contract date (roster), forms sent on an edition that was already replaced,
  and ownership mismatches the brokerage accepted.
- **Broker-ready score** 100 − 20 per blocker − 5 per warning; the agent sends the file to the broker in one
  click when no blocker or warning is open.
- **Dismissing** needs a reason; agents may dismiss notes only, reviewers anything (audited, critical for
  blockers).
- **Accuracy**: per check, findings fixed vs dismissed; a check dismissed five times or more and wrong more
  often than right is flagged for review on the compliance page, where the broker turns checks on or off and
  sets their severity.
- **Plain-English rules**: the broker writes a rule; AI reads it into a fixed structure (deal types, price range,
  states, words in the documents → a required document or broker approval, blocker or warning); RightAlly shows
  its reading in words built by code, and the rule applies only after the broker confirms it (every open file is
  then re-checked). Rules that cannot be checked automatically are refused with the reason.
- The compliance page (from the review queue) lists files sent for review and open files not yet broker-ready.

## Delivered in Sprint 15C — money and wire fraud

- **Money checks** (part of the continuous scan): the settlement statement or closing disclosure pays the
  commission the CDA says (listing side, buyer side or total, read by DocIQ with page evidence); the CDA still
  matches the deal's price and commission (otherwise "make a new CDA"); commission received from title matches
  what is due or the difference is accepted; earnest money in the contract matches the trust record, and is
  released after closing.
- **Wire fraud**: wiring instructions that **change between documents** (different account numbers, listed with
  the documents that carry each) are a blocker, the classic fraud sign; a settlement statement from a title
  company not verified by call-back is a warning; documents with bank instructions keep their warning and safe
  copy.
- **Payout holds**: while a money or wire blocker is open the deal's payouts are held. An automatic payout
  waits (owners are emailed why) and **goes ahead by itself** once the blocker is fixed or dismissed with a
  reason; a manual payout run that pays an agent with a held deal shows the hold and can only be approved with a
  written override (audited as critical). The hold is shown on the deal.

## Delivered in Sprint 15D — deadlines that cannot be missed

- **Escalation ladder** for every open key date (brokerage settings on the compliance page): the email digest
  (default 2 days before), a **text** the day before and on the day, an **automated voice call** on the day, and
  an email to the broker (owners and staff who review files) when a date is missed. Blockers still open within
  48 hours of closing (configurable) also go to the broker, once per finding. Each alert is sent once.
- **Texts and calls are configured in RightAlly HQ** (Texts and calls): the Twilio account (stored encrypted,
  never shown again; the password is asked first), the numbers to send and call from, the platform switches and
  a per-brokerage switch. Agents opt in themselves (consent is recorded with the time and audited) and need a
  mobile number; nothing is sent between 9pm and 8am in the brokerage's time zone.
- **At-risk prediction** (part of the continuous scan): missed dates (blocker), a financing deadline within six
  days with no appraisal, an inspection or option deadline within two days with no inspection report, closing
  within a week with no closing disclosure or settlement statement, and earnest money past its due date. Within
  two days they become blockers. Each opens the deal's key dates.
- **At risk today** on the agent's deals page, and a **private calendar feed** of key dates and closings
  (Google, Outlook, Apple; a new link cancels the old one).
- **Broker risk register** (Deals → Risk, protect/01 first version): every open risk with level, owner and due
  date, an 8-week trend and a CSV export for E&O insurers (spreadsheet-formula safe, audited).
- Scheduled: `transactions:escalate` hourly, `transactions:scan-open` daily (re-scans open deals so date
  risks appear without anyone opening the deal).

## Delivered in DQ1–DQ2 — start any way, talk to the deal, smart timeline

- **Start a deal any way** (Deals page): upload the contract or a phone photo of it, or describe the deal by
  typing or speaking ("buyer deal for the Garcias, 412 Elm St, $450k VA loan, closing July 3"). Deal-IQ creates
  the deal and opens a **setup page** proposing the kind of deal, address, price, dates, type of home, financing
  and people (with where on the contract each fact was read). While a contract is being read the page waits and
  refreshes. Nothing is assumed silently: confirming fills the people, adapts the checklist and builds the
  timeline.
- **Talk or type to a deal** (Deal-IQ panel on each deal, voice or text): Deal-IQ answers and proposes actions
  in plain English — move a date, add a date, add a task, add a checklist item, send an upload link, email
  someone on the deal, draft a form. The agent ticks what to do and confirms; problems (no email for that
  person, no day given, a closed deal) are shown before confirming. Each proposal can be done once or dismissed,
  and the last ten are kept as history. Deal-IQ cannot delete anything, move money, or send bank or wiring
  details (blocked before and at sending). Messages are sent from the platform with the agent as reply-to.
- **Contract and addendum drafting**: "draft a 9-day closing extension" picks the brokerage's own current form
  (Forms module), fills it from the deal and opens the draft packet with anything to check flagged. Nothing is
  sent for signature until the agent sends it.
- **Adaptive checklist**: the type of home (condo, townhome, multi-family, land, manufactured) and financing
  (FHA, VA, USDA, cash), plus a pre-1978 build year read from the contract, add the documents they need. Items
  are added once and never removed automatically. Audited.
- **Upload links for the other side** (no inbox access, no account): the agent, or Deal-IQ, sends a lender,
  title company or other party a single-use link (14 days) to upload one document. The token is stored hashed;
  the upload lands on the deal (and its checklist item when named) and the agent is told. Audited.
- **Smart timeline with state rule packs**: TX (earnest money 3 days, state holidays), LA, MS, AL, NJ (attorney
  review 3 business days) and a default. Deadlines read from the contract roll past weekends and federal
  (observed) and state holidays; the timeline adds earnest money, attorney review and the final walk-through,
  and warns when closing falls on a non-business day. It rebuilds when the closing or contract date changes or
  a fact is applied; dates set by hand or read from the contract are left alone, and the agent is emailed
  what moved and who to tell (a notification type the agent can turn off).
- Still to come: intake by email, more state packs, multiple offers and repair requests.

## Delivered in DQ3 — the deal portal and the client concierge (English and Spanish)

- **Deal portal** at `/my-deal` on the brokerage's own domain. The agent invites the buyers or sellers and the
  other side from the deal page (one click for anyone already on the deal with an email), choosing English or
  Spanish. There is no account or password. Pages are never indexed or cached.
- **Signing in: link plus code.** The emailed link (single use; 7 days from an invitation, 30 minutes when asked
  for again; stored only as a hash) never signs anyone in by itself:
    1. Opening it changes nothing, so email security scanners cannot use it up. The page asks for the email
       address the link was sent to; a forwarded link without that inbox gets no code.
    2. A 6-digit code goes to that address, or by text to a mobile the client opted in with (8am–9pm, where HQ
       allows texts). It works for 10 minutes and is stored only as a keyed hash.
    3. Five wrong tries (addresses or codes) and the link stops working (audited); the client asks for a new one.
    4. The right code signs that browser in for 12 hours, or 30 days if the client ticks "remember this device"
       (a hashed device cookie, scoped to `/my-deal`, HTTP-only).
    5. Every sign-in emails the client the device and time, with a "this was not me" link. It opens a page with a
       button (so a scanner cannot trigger it); confirming signs out every device and emails the agent.
    6. The agent sees each person's active devices and can sign them out everywhere; removing someone ends every
       session and device at once. Asking for a link never reveals whether an address is known.
- **What clients see**: a progress tracker (offer accepted, inspections, loan and appraisal (not for cash),
  getting ready to close, closed) with a plain note on the current step; the next three things (what is waiting
  on them, then dates in the next two weeks); every key date in their language (internal reminders such as
  "Check the closing date" are hidden); documents the agent chose to share (one click per document on the deal
  page, audited, viewing audited); upload links waiting on them; a button to send the agent a document; the
  agent's contact details; and a wire-fraud warning on every page and in every email.
- **What the other side sees** (lender, title, escrow, attorney, inspector, cooperating agent): only the dates
  that concern their role and the uploads asked of them, answered right in the portal. No progress, people,
  documents or concierge.
- **Client concierge**: questions answered from that deal's facts only, in the client's language, with where
  the answer came from. Money, bank, wiring, legal and tax questions never reach the AI and go to the agent; so
  does anything the AI is not sure of, any answer containing an amount or bank details, and anything the client
  would rather the agent answered. The agent replies from the deal page; the client is emailed the reply (wiring
  details are refused). The client's question is passed to the AI as untrusted content.
- **Updates without typing**, each sent once and in the client's language (`transactions:portal-updates`,
  hourly): an email when the deal reaches the next milestone, plus a text for clients who opted in themselves
  (consent recorded and audited, 8am–9pm in the brokerage's time zone, only where HQ allows texts); a Monday
  summary while under contract; and a check-in a week after closing. The check-in rating and words go to the
  agent, marked private unless the client agreed they may be shared as a review.
- Still to come: client SMS replies into the inbox, more languages, the concierge answering from shared
  documents' contents, and sending happy check-ins to the brokerage's review requests.

## Delivered in DQ4 — multiple offers and repair requests

- **Offers on a listing** (deal page → Offers). Buyers' agents send offers through the listing's offer link
  (no account; a new link stops the old one; never indexed), or the agent uploads one and Deal-IQ reads the terms
  (each kept only when its quote is on the cited page; shown with page and quote for checking), or types it in.
  Offer files are kept apart from the deal's documents, so an offer never feeds the contract's facts or timeline.
  The offer form asks buyers' agents not to send personal letters or photos (fair housing).
- **Comparison worked out in code**, best net first: the price each offer comes to (escalation clauses applied
  against the other offers, up to their cap), net to the seller after the deal's net sheet (listing side %, title
  and closing %, payoff, other; the commission inputs follow field-level hiding) and what the offer asks of the
  seller (concessions, buyer's agent pay), and how likely it is to close (0–100 with plain reasons: financing,
  proof of funds or pre-approval, contingencies, appraisal gap, sale of their home, closing date).
- **Fair rounds**: "multiple offers" and "highest and best" (with a deadline) go to every buyer's agent with an
  open offer in the same words at the same moment, each with a private revise link; revisions after the deadline
  are refused; each send is logged with its recipients as evidence of equal treatment.
- **Counter-offers** drafted on the brokerage's own form (Forms) for signature. **Accepting** puts the offer's
  price (after escalation), closing date, financing, buyer's agent and buyers on the deal, adapts the checklist,
  and tells every other buyer's agent at once.
- **Summary for the seller** in English and Spanish: Deal-IQ writes the words from the figures (any amount not in
  the comparison and the summary is written from the figures instead); the agent edits it, adds advice and shares
  it in the sellers' deal portal, which shows the offers as Offer A, B, C with no buyers' names or agents.
- **Repair requests** (deal page → Repair request): Deal-IQ reads the inspection report into findings with page
  and quote (anything it cannot point to is dropped), grouped safety → major → minor → maintenance; routine
  maintenance is not asked for by default. The agent chooses repair, credit (their amount: the AI never prices
  repairs) or nothing, with notes; the option or inspection deadline is shown; the amendment is drafted on the
  brokerage's form for signature; the buyers can see the request in their portal. Wiring details are refused.

## Market review (November 2026)

| Capability                                                   | ProxiClose | Transaction Wingman | Deal-IQ                                                 |
| ------------------------------------------------------------ | ---------- | ------------------- | ------------------------------------------------------- |
| Reads the contract, builds the timeline                      | ✓          | ✓                   | ✓                                                       |
| Writes contracts and addendums                               | ✓          | ✓ (separate tool)   | ✓                                                       |
| Branded 24/7 client assistant on the client's own deal       | —          | ✓                   | ✓                                                       |
| Portal for title, lender and the other agent                 | ✓          | —                   | ✓                                                       |
| Voice and text commands for the agent                        | —          | —                   | ✓                                                       |
| Intake by email forward, photo or voice                      | —          | upload only         | ✓                                                       |
| Broker file completeness and compliance review               | ✓          | —                   | ✓ (with Transactions, broker review queue, audit packs) |
| Forms, e-sign, commissions, CRM and compliance in one system | —          | —                   | ✓                                                       |
| Multi-state                                                  | Texas      | Texas               | All states with licensed forms                          |

## Capabilities

### 1. Talk or type to it

- **Type** in the chat box on every deal or in the global Deal-IQ chat, or **tap the mic and speak**:
  speech is converted to text and shown on screen so the agent can check or edit it before
  Deal-IQ acts.
- By phone: **text or send a voice note** to the agent's Deal-IQ number (voice notes are
  transcribed), or email the agent's Deal-IQ address.
- Optional **read-aloud** answers for hands-free use (for example while driving), in English and
  Spanish.
- **The mic is on every screen where Deal-IQ appears**: phone, tablet, laptop and desktop,
  including the agent and broker dashboards on the website and the installable mobile web app
  (native apps after version 1.0). It uses the browser's microphone with the user's permission
  (Chrome, Safari, Edge, Firefox); if permission is refused, typing still works and the screen
  explains how to allow the mic.
- Questions: "What's left on 14 Oak Street?", "Which of my deals close this week?"
- Instructions: "Move the inspection deadline to Friday and tell the lender", "Draft a 3-day
  extension", "Remind the buyer to send the HOA documents".
- Deal-IQ shows exactly what it will do (messages, changes, documents) and waits for the agent to
  confirm. Speech-to-text runs through the AI gateway (specs/ai/01).

### 2. Start a deal any way

Upload, forward the contract email, text a photo, or say "new deal at 14 Oak Street". Deal-IQ creates
the transaction, extracts parties, prices and dates, builds the timeline and checklist, and asks the
agent to confirm anything it is unsure of.

### 3. Contract and addendum drafting

"Write an offer on 14 Oak St at $350,000, 1% earnest money, close May 1, 10-day inspection." Deal-IQ
fills the correct **licensed state form** with data from the CRM and MLS, flags missing or unusual
terms, and sends for e-signature after the agent approves. Counter-offers, extensions, amendments
and termination notices work the same way. Uses Forms & e-Sign; only forms the tenant is licensed for.

### 4. Client concierge (24/7, branded to the agent)

- Lives in the client portal with the agent's name, photo and brokerage.
- Answers only from **that client's own deal**: dates, deadlines, what to bring, what happens next,
  document status.
- Never gives legal, tax or financial advice; hands those questions and anything uncertain to the
  agent, who is notified.
- The agent sees every conversation; the brokerage sets what the concierge may discuss.

### 5. All-party portal

Title, lender, the cooperating agent and attorneys get a secure link (no account needed, one-time
code) showing the milestones and document requests that concern them, and upload documents
directly. Replaces email chasing; every view and upload is logged.

### 6. Deal health in the daily game plan

At-risk deadlines, missing signatures and overdue documents appear among the agent's top actions
each morning; the broker sees at-risk deals across the brokerage.

### 7. Deal paperwork without inbox access (decision 2026-11-14)

RightAlly does not read anyone's Gmail or Outlook mailbox. That means no restricted mail permissions, no yearly
paid security assessment, and no "RightAlly wants to read your email" screen. Inbox intelligence is **deferred,
not dropped**: we can revisit it later if it becomes worth that cost. Deal-IQ gets its documents and dates from:

1. **Transaction platforms synced through Connect** (SkySlope, dotloop, Lone Wolf; specs/connect/00, C5), through
   their own APIs.
2. **RightAlly forms and e-signature**: anything drafted and signed in RightAlly is already on the deal.
3. **Asking for what's missing.** The deal's checklist shows what's missing, and Deal-IQ asks the agent in plain
   words ("The inspection report for 12 Oak St is missing. Tap here to upload it."). They can upload it, take a
   phone photo, or text the photo in.
4. **Asking the other side.** Deal-IQ sends a secure, single-purpose upload link (no account needed) to the lender,
   title company or co-op agent. The file is filed on the right deal and read by DocIQ.
5. **An optional per-deal forwarding address.** The agent forwards only the emails they choose. A forwarded email
   is treated like an upload, and nothing else in their mailbox is touched.

Each document is read by DocIQ, which updates the deal's dates and tasks. Replies to the other parties are drafted in
the agent's voice and sent from RightAlly's inbox after the agent approves them.

### 8. Smart timeline engine

- **State rule packs** maintained by RightAlly HQ: business-day counting, state holidays,
  attorney-review states, earnest-money and disclosure deadlines.
- **Counteroffer chains** resolved to the binding terms.
- **Amendments recalculate every dependent deadline** and list who must be told.
- Checklists adapt to **property type** (condo/HOA, land, multi-family, well and septic) and
  **financing** (cash, conventional, FHA, VA).
- The brokerage's own checklist, written in plain English, becomes rules applied to every file.

### 9. Continuous compliance scan

Runs at intake and on every new document; findings graded **blocker / warning / info**: missing
signatures and initials, blank fields, mismatches across documents (names, middle initials, prices,
dates, wrong year), documents referenced but not in the file, required state and brokerage
disclosures, and a signed buyer agreement before touring (NAR settlement). Each file has a
**broker-ready score**; submission to broker review is one click at 100%.

### 10. Deadlines that cannot be missed

Escalation ladder to the right party (in-app → email → text → automated voice call), calendar sync
with invites to all parties, **at-risk prediction** (for example "appraisal not ordered, financing
deadline in 6 days", "lender silent for 5 days"), and automatic escalation to the broker for
blockers within 48 hours of a deadline.

### 11. Money milestones and wire-fraud protection

Earnest money tracked to receipt; commission disbursement authorisation generated from the
compensation engine; the 3-business-day closing-disclosure rule; **wire-fraud safeguards**
(verified wiring instructions, call-back verification reminders, wire details never sent by email).

### 12. Daily brief and ask-across-deals

Morning brief of due, overdue and upcoming items across all deals (inside the daily game plan);
questions across every deal the user may see ("Which deals close this week?"); automatic weekly
status emails to clients.

### 13. TC team mode

For brokerages with human coordinators: assign deals to TCs, workload dashboard (active files,
overdue items, last active, one-click nudge), clean handoffs. Brokerages can **offer TC service to
agents for a per-file fee**, charged through the compensation engine as a deduction.

### 14. After closing

Permanent compliance archive kept for each state's retention period, review request, and past-client
follow-up in the CRM.

### 15. Results dashboard

Days to close, deadline-miss rate, files broker-ready at first submission and hours saved, for agents
and brokers.

### 16. Multiple-offer management (listing agents)

Deal-IQ reads every offer and shows them side by side: price, **net to the seller** (net sheet per
offer), financing type, earnest money, contingencies, closing date, concessions and risk flags, with
a plain-English summary and a recommendation the agent can share with the seller. It sends
"highest and best" deadline messages to every buyer's agent, logs every offer received, and drafts
the counter-offer for the chosen one.

### 17. Inspection response and repair requests

From the inspection report (read by DocIQ), Deal-IQ drafts the repair request or credit addendum item
by item with suggested priorities, lets the agent edit and agree it with the client, sends it for
e-signature, and tracks the response deadline and any re-inspection.

### 18. Listing-to-contract coordination

Deal-IQ also runs the listing side before a contract exists: listing agreement and seller
disclosures, MLS input checklist and required fields, photography, sign and lockbox scheduling,
coming-soon and go-live dates, showing feedback, and price-change reminders. One coordinator from
listing taken to closed.

### 19. Special deal types

Ready-made timelines, checklists and documents for **new construction** (builder contracts and
milestones), **leases** (application, lease signing, move-in), **short sales and bank-owned
properties** (lender approval and addenda), **probate and estates** (court and executor steps) and
**1031 exchanges** (45-day identification and 180-day closing deadlines), on top of the state rule
packs.

### 20. MLS status compliance reminders

Each MLS's rules for status changes (pending, closed, withdrawn, price change) are part of the
rule packs; Deal-IQ reminds the agent before the MLS deadline and shows the broker overdue updates.
RightAlly does not change MLS statuses itself unless an MLS provides approved write access.

### 21. Vendor scheduling and closing-day logistics

Book inspectors, appraisers, photographers, stagers and movers from the brokerage's preferred-vendor
list; coordinate the closing appointment with title, the final walkthrough checklist and a client
"moving list" (utilities, keys, mail forwarding). Vendor lists carry **no paid placement** unless
the brokerage's legal review approves it (RESPA).

### 22. Concierge in the client's language

The client concierge answers in the client's language, starting with English and Spanish, like the
rest of RightAlly.

### 23. Seller and buyer appointment flow

At a listing appointment the agent taps record (**everyone present is asked to consent first**;
several states require all-party consent). The AI extracts the agreed terms (price, commission,
term, exclusions) and drafts the **listing agreement on the licensed state form**; every compliance
check runs (broker approval where required); one click sends it for e-signature with a
**personalised seller guide** (process, timeline, net sheet, marketing plan from List-IQ). The
buyer side does the same for the **buyer agreement** (required before touring) with a buyer guide.
Shown as "passes every check"; the broker remains responsible. Built by Team A.

### Existing capabilities kept

Contract-to-timeline, reminders to every party in the agent's name, missing-document chasing,
closing countdown, broker approval controls, AI document review.

## Who gets it

| User                      | Access                                                                                                                                                                                                                                                                                                                                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Brokerage owner and staff | Oversight within role scope: every deal, broker-file completeness, approvals, at-risk deals. Control: what the concierge may answer, which actions need broker approval, licensed state forms, default reminder schedule.                                                                                                                                                       |
| Agents in a brokerage     | Full use on their own deals. Deals flow into the brokerage's transaction file, compliance review and commission calculation automatically.                                                                                                                                                                                                                                      |
| Solo agents               | The personal side: contract reading and timeline, reminders, voice and text, client concierge, DocIQ, drafting on forms they are licensed to use, plus a one-click **broker packet** (documents, dates, parties) sent to their broker by email or into dotloop or SkySlope. Full broker-file submission unlocks through **Ask my broker** when their brokerage joins RightAlly. |

Sold as an add-on through the package engine; voice, drafting and concierge conversations use
RightAlly Credits.

## Guardrails

Positioning: Deal-IQ **catches issues before the broker does**; the broker remains legally
responsible for file review, so Deal-IQ is never marketed as a guarantee of compliance.

- Agent approval before anything is sent, signed or changed on a deal.
- Concierge answers only from the client's own deal; output policy blocks identifiers outside it
  (specs/ai/01); no legal, tax or financial advice.
- Client text messages only with recorded consent (TCPA, A2P 10DLC).
- State forms only where licensed; drafting flags non-standard terms for agent review.
- Every action, message and portal view is in the audit log.

## Dependencies

Transactions, Forms & e-Sign, DocIQ, client portal, compensation engine (CDA, TC fees), daily game plan,
AI gateway (speech-to-text, text-to-speech), Connect (transaction platforms, calendar sync), Twilio
texting and voice, credits, compliance rule library (state rule packs).

## Acceptance (module level)

1. A forwarded contract becomes a deal with correct dates and parties in under two minutes, with
   uncertain fields flagged.
2. Voice and text instructions produce a preview, and nothing happens without confirmation.
3. Drafted offers and addendums use the correct licensed state form and pass AI document review.
4. The client concierge answers only from that client's deal and escalates advice questions.
5. Third-party portal users see only their own milestones and requests.
6. Solo agents can send a complete broker packet; brokerage features unlock when their broker joins.
7. Spoken instructions are transcribed and shown for editing before any action, on phone, tablet,
   laptop and desktop dashboards alike; voice notes sent by text are transcribed and handled like
   typed messages.
8. A deal email without the address in the subject is filed to the correct deal.
9. Changing the closing date by amendment recalculates every dependent deadline and lists who to notify.
10. The compliance scan grades every finding and blocks broker submission while blockers remain.
11. A blocker within 48 hours of its deadline escalates to the broker automatically.
12. Multiple offers are compared with a correct net-to-seller figure for each.
13. An inspection report produces an editable repair request that can be sent for signature.
14. Each special deal type creates its specific deadlines (for example the 1031 45/180-day dates).
15. MLS status reminders fire before each MLS's deadline; overdue updates appear to the broker.
16. A Spanish-speaking client receives concierge answers in Spanish.

## Packaging

Sold with DocIQ as the **RightAlly AI Transaction Suite** (product/ux-principles.md). Agents see one
assistant per deal: "Ask anything or tell me what to do".
