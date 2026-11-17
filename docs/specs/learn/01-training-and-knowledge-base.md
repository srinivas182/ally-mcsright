# Learn 01: Training and the knowledge base (LN1)

Status: built in LN1. Tracker row E19. Module: `modules/Learn` (menu: People › Learn).

## What the market leaders do

- **Real estate platforms** keep training in a separate tool:
    - **kvCORE / BoldTrail** has BoldTrail Academy videos.
    - **eXp** has eXp World and University.
    - **Keller Williams** has KW Connect.
    - **Brokermint and Lone Wolf** link out to a learning platform the broker pays for separately.
- **Brokerage how-to knowledge** lives in Google Docs, shared drives or a Facebook group: the vendor list, the
  lockbox rules, the commission-day checklist.
- **Where they fall short:**
    - Training is not tied to the agent's real work.
    - A broker cannot easily see who finished what, or prove a policy was read after it changed.
    - Videos and long modules do not suit busy agents aged 50+.

## What RightAlly does

### Courses

- **Short lessons.** Each lesson is one idea:
    - **Text**, written in Markdown;
    - **Video**, from YouTube (no-cookie player), Vimeo or Loom only, shown in a sandboxed frame;
    - **Quiz**, multiple choice.
- **Quizzes teach, not just test.** After each try, every answer shows whether it was right, the right answer,
  and _why_. The pass mark is set per course (80% by default). The right answers are never sent to the browser
  before someone answers.
- **Progress and certificates.** Text and video lessons are done when marked "I have read this". A quiz is done
  when passed. The course is complete when every lesson is done, and a PDF certificate can be downloaded. The
  certificate notes that it is brokerage training, not state CE credit unless the state approves it.
- **Starter courses from RightAlly.** These ship as files and are copied into the brokerage's library with one
  click, published and ready to assign:
    - "RightAlly in 20 minutes";
    - "Texting rules and fair housing basics". This is general training, not legal advice; the broker can change it.
- **Assigning.** The owner, staff, or anyone with **Manage training** assigns a course to chosen people or
  everyone, with a due date. Assigned courses show first on the agent's Learn page, soonest due date first.
- **Report.** A CSV of who was assigned what, when it was due, started and completed. It is formula-safe, for
  the broker's records or a state audit.

### Knowledge base

- **What it holds.** "How we do things here" pages in categories, such as Office, Vendors and Scripts, with plain
  word search.
- **Required pages.** A page marked "Everyone must read" asks each person to confirm it. **Changing the page asks
  everyone to confirm again**, because the version number goes up.
- **Reminders.** The Learn page shows how many pages still need confirming.

### Built for agents aged 50+

- Large text.
- One lesson on screen at a time, with clear Back and Next buttons.
- A progress bar.
- Plain labels ("I have read this — next", "Check my answers").

## Security

- **Who can do what.** Only owners, staff, or people with `learn.manage` can write, assign or see the report.
  Everyone else sees published courses and pages only.
- **Safe display.** Lesson and page Markdown is turned into HTML on the server, with raw HTML escaped. Video
  addresses are checked against an allow-list and turned into player addresses on the server.
- **Records.** Saving, publishing, assigning, completing and confirming are all written to the audit log.

## Next

- LN2: Learn-IQ courses, including the AI course builder, New Agent Launch, certifications that unlock tools,
  and mentors.
- LN3: the AI role-play academy.
