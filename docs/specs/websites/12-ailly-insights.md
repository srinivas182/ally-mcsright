# Websites 12: AIlly insights, the closing brief and brokerage answers (Sprint 17D-2)

## Closing brief on every website lead

Every website lead gets a closing brief, whether it came from AIlly or any other website form. It combines the
visitor's **chat with AIlly** and their **website journey**: homes viewed and how often, searches, saved and
compared homes, payment estimates and home-value requests. The agent sees it on the lead under "Closing brief and
everything they did", next to the full chat. It contains:

- a two-sentence summary
- **budget**, **timeline**, **financing**, **best time to reach them** (each "Not shared yet" if unknown)
- **must-haves** and **concerns**
- an **opening line** for the first call or text that shows the agent listened
- a **checklist** of 3–5 next actions, most important first
- **homes they care about**: the top 3 by views, where a save counts as three views, with links
- **homes to send next**: 3 similar homes for sale they haven't viewed, found by the MLS search from their price
  range, beds and area

How it's built:

- **Facts come from code**: the homes, prices and matches. The AI only writes the words
  (`sites.closing_brief`, credit action `ai.closing_brief`, 2 credits, automatic).
- Every text field goes through the figure and Fair Housing guard. The chat is passed as untrusted content.
- Without AI (no route, no credits, or blocked output), a brief written from the facts is shown, with a starter
  checklist.
- **When it's written:** right after a visitor shares details in AIlly, and again whenever the lead's journey
  summary is refreshed (at most every 15 minutes). The first brief is added to the contact's timeline.

## Brokerage answers and unanswered questions

- Owners and staff can save up to **50 questions and answers** (Settings → AIlly assistant → Answers).
    - A visitor's question is matched to a saved one when every meaningful word of the saved question appears in
      it, or when most words are shared.
    - A matched question gets the broker's answer **word for word, for free**.
    - All answers are also given to the AI as facts.
- The AI marks a reply with `answered: false` when the website's facts and answers didn't cover the question. Such
  questions are collected with a count of how often they were asked. The broker can add an answer, which then also
  closes any matching open questions, or dismiss them.
- **Weekly email** to owners (`ailly:weekly`, Mondays 13:40 UTC): chats, leads, calls, cost per lead, and the top 5
  questions to answer.

## Hot-lead alert

When a visitor who has shared details shows a lead score of 70 or more, or asks for a call or a showing, the lead's
assigned agent gets an alert:

- a **text**, if they opted in to lead texts and it's 8am–9pm
- an **email**, "Hot lead from your website chat"

It is sent once per conversation.

## Fair Housing audit

- Each AIlly answer records what the figure and Fair Housing guard removed, and where the answer came from (`ai`,
  `cache` or `answers`).
- Settings → AIlly assistant shows how many answers had something removed. **Export (CSV)** for a month lists
  every answer with what was removed. The export is formula-safe and audited.
- Chats show a "Safety check removed a sentence" badge on those answers.

## Results (Settings → AIlly assistant → Results)

- Period: last 7, 30 or 90 days.
- Chats, leads and lead rate, calls booked, showings requested.
- Credits spent on chat answers and closing briefs, through the Core `CreditSpend` contract, which Billing
  implements from the ledger.
- **Cost per lead** and **cost per booked call**.
- Free answers.
- **Which pages turn chats into leads** (top 10), and **by website** (the brokerage's and each agent's).
- **Chats tab:** filter by outcome (call, showing, lead, no details) and by lead score (hot 70+, warm 40+), with
  transcripts.

## Tests

`tests/Feature/Sites/AillyInsightsTest.php` covers:

- the brief from chat and journey, with the guard and homes to send next
- the fallback brief without AI
- brokerage answers and unanswered questions
- the hot-lead alert
- the audit export, results, cost per lead and filters
- the weekly email
