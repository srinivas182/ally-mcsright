# Team 01: Team chat, help centre and support (CH1)

Status: built in CH1. Tracker row B22. Module: `modules/Team`.

## What the market leaders do

- **Brokerage platforms (Lone Wolf, Brokermint, kvCORE / BoldTrail, Real's reZEN)** offer no real team chat.
  Brokerages bolt on Slack, WhatsApp or Facebook groups. That means client details sit in tools the brokerage
  does not control, with no way to remove a bad post. **eXp** runs Workplace and Slack, outside the platform.
- **Help centres** are separate websites (Zendesk or Intercom), written for the product team rather than the agent.
  Support means emailing and waiting, with no record inside the product.
- **Where they fall short:**
    - **No broker's voice.** There is no announcements channel that only the broker posts to.
    - **No real privacy promise.** Direct messages carry no clear rule on who can read them.
    - **Mentions get missed.** An @mention is easy to miss, and agents aged 50+ do not live in Slack.

## What RightAlly does

### Team chat (menu: Overview › Team chat)

**Channels**

- **Announcements.** Everyone reads; only the owner and staff post.
- **Everyone.** The whole office.
- **Groups.** Made by the owner or staff, such as an office, a team or a committee. Only members see them.
- **Direct.** Private to the two people. **Even the owner cannot read direct messages**, and the screen says so.

**Messages**

- Encrypted at rest.
- The author can change or take back a message for 15 minutes.
- The owner or staff can remove a message from a shared channel.
- A removed message keeps its row and an audit entry; nothing is truly deleted.

**@mentions**

- Type `@` and pick a name. Only people who can see the chat can be mentioned.
- If the mention is still unread after 10 minutes, the person gets one email. They can switch that email off
  under notice settings: "Unread @mentions in team chat".

**Built for agents aged 50+**

- Large text and plain labels ("Broker news", "Whole office").
- Unread counts on each chat.
- New messages appear every 10 seconds without reloading.

### Help centre (menu: Settings › Help)

- **Articles.** Short guides written for agents, kept as Markdown files in the code, so they ship with each update
  and match the screens. There are 13 to start: first day, leads, contacts, deals and documents, DocIQ, forms,
  smart forms (broker-only), team chat, texting rules (TCPA), getting paid, security, website, and getting help.
- **Search.** Plain word matching, with title words counting most. It works with no AI and no outside service.
- **Feedback.** "Did this help?" Yes or No on each article. "No" offers a link to ask a person.
- **Safe display.** Article HTML is made on the server, with any raw HTML escaped.

### Support requests

- **Ask us.** Pick a topic, add a short title and describe what you were trying to do. Requests go to one HQ
  queue (`/hq/support`) in the control database. The text is encrypted.
- **Answers.** HQ's answer is emailed to the person and shown on their Help page under **Your requests**. People can
  mark a request as solved.
- **Safety.** The form says never to send passwords, card numbers or bank details.

## Security

- **Who can use it.** Only active internal people (owner, staff, agent, assistant). Chat and help need sign-in
  and are not indexed.
- **Data storage.** Direct-message privacy is enforced on every read. Support requests are scoped to the person
  and brokerage that made them; only HQ staff see the queue.
- **Rate limits.** Posting is limited to 60 a minute; support requests to 10 a minute.

## Not in this sprint

- File attachments in chat.
- Voice messages (EN1).
- Supervised review of direct messages for brokerages that need it (a later setting with clear notice to agents).

## SX5: help everywhere

**A "Help" button on every back-office page** (bottom left, above the microphone) opens "How can we help?" with big
buttons for the page the person is on (`Team\Help\PageHelp`):

- **Watch a 1-minute video.** Shown when HQ has added one under HQ → Help videos, set per page by the start of its
  address. Links must be YouTube, Vimeo or an https MP4. YouTube plays from youtube-nocookie.com and Vimeo with "do
  not track". An MP4's captions are read from the same address ending `.vtt`.
- **Print a cheat sheet.** The page's how-to, on one large-print page (`/help/{article}/print`).
- **Call me back.** The person gives their number (filled in from their profile) and the best time: morning,
  afternoon, early evening or any time. HQ gets it in the Support queue as "Please call me back…". People can ask at
  most 3 times an hour.
- **Practice with made-up clients**, or **Remove my practice people** once they have some.
- **See all how-tos** opens the help centre.

**Practice mode** (`Crm\Practice\PracticeClients`) adds five made-up people with notes and a to-do:

- Rosa, Tom, Ana, Walt and June, each with the last name "Practice".
- Their numbers are 555-01xx and their emails are at example.com. Both are reserved for fiction, so nothing can reach
  a real person.
- Only the person practising (and their assistant) sees them. Owners, managers and teammates never do.
- Nothing is ever sent to them; the send check says so. They start no automations, and they cannot be put on a real
  deal.
- "Remove my practice people" deletes them and their timelines. They are also removed by themselves after 14 days
  (`crm:practice-cleanup`, daily).
