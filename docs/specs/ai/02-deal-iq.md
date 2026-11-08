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
| D, 37–38         | Inbox intelligence (Gmail and Outlook) and the smart timeline engine with state rule packs                                                                                           |
| D, 39–40         | Multiple-offer management; inspection response and repair requests                                                                                                                   |
| B, 53–54         | Continuous compliance scan, deadline escalation ladder, money milestones and wire-fraud safeguards, TC team mode, results dashboard, post-closing archive                            |
| B, 55–56         | Special deal types (new construction, lease, short sale and bank-owned, probate and estates, 1031 exchange); MLS status compliance reminders                                         |
| C, 53            | Listing-to-contract coordination; vendor scheduling and closing-day logistics                                                                                                        |
| A, 53            | Client concierge in Spanish (multilingual concierge)                                                                                                                                 |

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

### 7. Inbox intelligence

Agents connect Gmail or Outlook. Every deal email is filed to the right deal automatically (by
parties, property references and thread history, even without the address in the subject),
summarised, and turned into tasks and updates. Replies are drafted in the agent's voice and sent
from the agent's own address after approval, with no AI branding.

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
AI gateway (speech-to-text, text-to-speech), Gmail and Outlook connections, calendar sync, Twilio
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
