<!-- markdownlint-disable MD013 -->

# Know 03 — "Your AI Assistant": one back-office assistant over everything

Sprint **AI1** (Teams D and A). Approved 2026-11-15, after C7a-2 and ahead of C7b. It replaces the broker-branded
back-office assistant in Know 02. The broker's voice recognition messages in Know 02 stay as they are.

## Names (fixed)

| Where                              | Name                  | Can the brokerage change it?           |
| ---------------------------------- | --------------------- | -------------------------------------- |
| Back office (every signed-in page) | **Your AI Assistant** | No: the name and look are fixed        |
| Public websites (visitors)         | **Ask Ally** (AIlly)  | Name and photo only (Settings → AIlly) |

## What it is

One chat, bottom-right on every back-office page, for owners, staff, agents and assistants. It can also be opened
with Ctrl/⌘ J. People can type or hold to talk. Everything it does, people can still do on the pages themselves;
the assistant is a faster way in, not a second system.

## How it answers: skills

Every capability the assistant can use is a **skill**: a RightAlly MCP tool (specs/connect/03). It's the same
registry, with the same `allows()` permission rules, write flag and confirm-first preview. So:

- Each module declares its skills once, and both "Your AI Assistant" and outside AI apps (ChatGPT, Claude,
  Copilot via MCP) get them.
- New features and new connectors bring their own skills; nothing is added to the assistant by hand.
- A skill the person's role can't use isn't offered to the AI for that person at all.

First skills (existing tools, plus new ones):

- **CRM:** search contacts, contact details, add contact, log note, tasks, Today's 10, open times.
- **Deals:** my deals, deal details, deal deadlines.
- **Brief:** the daily brief (specs/core/09).
- **Connected apps, every category** (not only marketing). Each connector can declare its own skills, and every
  connected app gets these at least:
    - **Which apps are connected**, and each one's status: last sync, what came over, any error and how to fix it.
    - **Sync now** (confirm-first): "bring my dotloop deals over now", "post my journals to QuickBooks now".
    - **What came from it**: for transaction apps (dotloop, SkySlope, Lone Wolf, Open to Close, Brokermint,
      DocuSign), deals and documents brought over; for CRMs, contacts imported; for calendars, busy times and
      bookings; for accounting, journals posted and what is waiting.
- **App-specific actions:**
    - GoHighLevel: list workflows and campaigns, then add a smart list or tag to one (confirm-first, consent
      checked, specs/connect/16).
    - Mailchimp and Constant Contact: the audience and who goes.
    - Google and Microsoft calendars: open times and booking.
    - Later connectors (phones, direct mail, ads, showings, data) add theirs as they're built.
- **Connections:** "which apps are connected", and connecting an app (see below).

Later skills plug in the same way: Deal-IQ findings, DocIQ answers, compliance knowledge, Agent Wallet figures,
funnels, buyer demand, Fair Housing.

## The loop

1. The person asks. The assistant knows the page they're on (a contact, a deal), so "her" or "this deal" works.
2. The AI (through the AI gateway, structured output) picks one step:
    - **answer** from what it already has;
    - **use a skill** with arguments;
    - **ask** one short question when something is missing (e.g. "Which campaign? You have 3: …");
    - **connect** an app that isn't connected;
    - **hand over** to the broker or staff.
3. **Read skills** run at once, and their results go back to the AI as data, never instructions. There are up to
   five steps per question.
4. **Write skills** never run straight away. The assistant shows a **preview card**: what, who, how many, who is
   left out and why. Nothing happens until the person presses **Do it**; then it runs through the same services as
   the screens.
5. **Not connected:** a card says "GoHighLevel isn't connected" with **Connect** (the app's own page or
   one-click). The request is kept, and after connecting the person presses **Carry on**, so they don't start over.
   If the app is switched off by HQ or is a paid add-on, the card says so and offers "Ask your broker" or the
   add-on.
6. **No skill fits:** it says so plainly and suggests the page or the person to ask.

## Rules

- **Permissions:** exactly the person's own, as on the screens. Agents act on their contacts and deals; brokers on
  the brokerage's.
- **Never:**
    - bulk sending by the AI itself (campaign skills add people to a campaign the person built, after **Do it**);
    - deleting anything;
    - money movements, bank details or commission splits.
- **Every question and action is audited.** AI use is charged to RightAlly Credits (`ai.assistant`). It is always
  labelled as AI.
- **Fair Housing** is checked on anything the assistant writes (channel `ai_content`).
- **Conversations** are kept for 30 days for the person, then deleted. Each person only ever sees their own.

## Acceptance (AI1)

- From any page, a person can ask "who should I call today?" or "what's due on my deals this week?" and get an
  answer from their own data.
- "Put my spring sellers in my GoHighLevel spring campaign" shows a preview with consent counts, and runs only on
  **Do it**.
- If GoHighLevel isn't connected, the same request offers Connect and carries on after.
- An agent can't reach another agent's contacts or brokerage-only skills; nothing is deleted; every step is in the
  audit log.
