# AI 02 — DealIQ: the AI transaction coordinator (Team D, Sprints 32–36)

Status: **Module definition approved.** The detailed sprint specification (screens, data model,
APIs) is written before Sprint 32, once Transactions, Forms & e-Sign and DocIQ exist.

## Purpose

DealIQ does the work of a transaction coordinator for every deal, from accepted contract to closing,
and makes the agent's life easier: agents **talk or type** to it, it reads the contract, keeps every
deadline, drafts the paperwork, chases every party and answers clients around the clock under the
agent's brand. Agents stay in control: nothing is sent or signed without their approval.

Formerly "AI Transaction Coordinator" in the module catalogue. Positioned as
**"DealIQ — your AI transaction coordinator"**, alongside DocIQ.

## Market review (November 2026)

| Capability                                                   | ProxiClose | Transaction Wingman | DealIQ                                                  |
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

- Push-to-talk in the mobile web app and a chat box on every deal; also a dedicated text-message
  number and email address per agent.
- Questions: "What's left on 14 Oak Street?", "Which of my deals close this week?"
- Instructions: "Move the inspection deadline to Friday and tell the lender", "Draft a 3-day
  extension", "Remind the buyer to send the HOA documents".
- DealIQ shows exactly what it will do (messages, changes, documents) and waits for the agent to
  confirm. Speech-to-text runs through the AI gateway (specs/ai/01).

### 2. Start a deal any way

Upload, forward the contract email, text a photo, or say "new deal at 14 Oak Street". DealIQ creates
the transaction, extracts parties, prices and dates, builds the timeline and checklist, and asks the
agent to confirm anything it is unsure of.

### 3. Contract and addendum drafting

"Write an offer on 14 Oak St at $350,000, 1% earnest money, close May 1, 10-day inspection." DealIQ
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

- Agent approval before anything is sent, signed or changed on a deal.
- Concierge answers only from the client's own deal; output policy blocks identifiers outside it
  (specs/ai/01); no legal, tax or financial advice.
- Client text messages only with recorded consent (TCPA, A2P 10DLC).
- State forms only where licensed; drafting flags non-standard terms for agent review.
- Every action, message and portal view is in the audit log.

## Dependencies

Transactions (Sprints 12–14), Forms & e-Sign (Sprints 27–30), DocIQ (Sprints 30–31), client portal
(Sprints 31–33), AI gateway speech-to-text, Twilio texting, credits.

## Acceptance (module level)

1. A forwarded contract becomes a deal with correct dates and parties in under two minutes, with
   uncertain fields flagged.
2. Voice and text instructions produce a preview, and nothing happens without confirmation.
3. Drafted offers and addendums use the correct licensed state form and pass AI document review.
4. The client concierge answers only from that client's deal and escalates advice questions.
5. Third-party portal users see only their own milestones and requests.
6. Solo agents can send a complete broker packet; brokerage features unlock when their broker joins.
