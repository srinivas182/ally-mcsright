# 11. Simple mode (SM1)

Plan item A18 (part). Status: built in SM1. The mobile web app, universal search and the full Spanish back office follow in MB1.

## Why

Most agents and brokers are over 50 and do not want to learn new software. Simple mode gives them the few things they use every day, with big buttons and plain words, in English or Spanish, without hiding anything they have a right to.

## Leader review

| Product                       | What they do                               | Where they fall short                                                            |
| ----------------------------- | ------------------------------------------ | -------------------------------------------------------------------------------- |
| Follow Up Boss                | Clean, focused CRM screens; mobile app     | Still a full menu for everyone; no simpler view; English only                    |
| kvCORE / BoldTrail            | Everything in one menu                     | Agents call it overwhelming; long training; no simple view                       |
| Lofty                         | Modern look, AI assistant                  | Many modules on screen at once; no Spanish interface for agents                  |
| SkySlope / Dotloop            | Task-first transaction screens             | No guided help on the screen itself; mistakes cannot be undone                   |
| Brokermint / Lone Wolf        | Strong back office                         | Dense tables; built for admins, not for agents                                   |
| Gmail / Google (the baseline) | "Undo send", one primary action per screen | Not a real-estate product; shows that undo and one clear action build confidence |

RightAlly adds what they miss: a view switch anyone can flip (asked once, changed any time), a six-tile home, a five-item menu, English or Spanish on these screens, a "Show me" tour on the page itself, undo for ten minutes on everyday actions, larger text, and one button to talk to the assistant.

## What the person sees

- **Asked once.** The first time, a card asks "Would you like RightAlly simpler?". They can say yes or no; the choice is kept on the person (`users.ui_mode`, `users.ui_locale`) and changed any time from the header switch (`POST /settings/view`).
- **Home (`/simple`).** A greeting, one "Talk to RightAlly" button and six big tiles: add a person, start a deal, who to call today, my deals, my to-dos, ask a policy question. Below them, "Needs you today": the first five items of the daily brief.
- **Short menu.** Home, Who to call today, My deals, My people, My to-dos, Ask a question. Only pages the person can already open are shown; simple mode never grants access.
- **Language.** English or Spanish for the simple screens and menu (`SimpleMode::words`). A test fails if a word is missing in either language.
- **Bigger text.** `html.simple` raises the base size to 112.5%.
- **Show me.** A button in the header walks through the current page, outlining each step (`data-tour` markers).
- **Undo.** Completing a to-do, ticking or removing a deal date, skipping a playbook email and removing portal access show an Undo button for ten minutes.
- **Dashboards.** In simple mode `/dashboard` and `/agent` open the simple home.

## Undo

`Modules\Core\Undo\Undo` keeps a row per undoable action in `undo_actions` (kind, payload, label, expiry). Modules register a handler per kind at boot (`Undo::register`). `POST /undo/{ulid}` runs it once, only for the person who did it, within ten minutes; anything else is a validation error. Undo is audited by the handler's own action.

| Kind                 | Registered by                 | Puts back                  |
| -------------------- | ----------------------------- | -------------------------- |
| `crm.task.complete`  | `CrmServiceProvider`          | Re-opens the to-do         |
| `deal.date.toggle`   | `TransactionsServiceProvider` | The date's done state      |
| `deal.date.remove`   | `TransactionsServiceProvider` | The removed date           |
| `deal.step.skip`     | `TransactionsServiceProvider` | The playbook step to ready |
| `deal.portal.remove` | `TransactionsServiceProvider` | The party's portal access  |

Deletes of records, money movements and messages already sent are never offered as undo.

## Security and privacy

- Simple mode filters the existing menu; it never adds a page or permission.
- Undo payloads hold ids only, are tied to the user and tenant, and expire after ten minutes.
- Public pages are unchanged; `/simple` is behind sign-in and not indexed.

## Tests

`tests/Feature/Core/SimpleModeTest.php`: word coverage in both languages; the choose-once flow, short menu and home in English and Spanish; permission filtering; undo once, same user, ten minutes; deal undo kinds.

## Left for MB1

Mobile web app shell, universal search, Spanish across the whole back office.

## SX4: one simple home and a microphone on every page

**Today's 10 comes first on the simple home.** Under the "Talk to RightAlly" button, the people to call today are
listed, each with the reason in plain words and two big buttons. The list comes from the CRM's Today's 10 through
`Core\Simple\HomePeople` (`Crm\Simple\TodaysTenHome`), and needs the plan that includes Today's 10.

- **Call** shows when the person has a phone number and is not on the do-not-call list. It opens the phone. Back on
  the page, three big buttons ask how it went: **We talked**, **Left a message** or **No answer**. The answer is
  logged on the person's timeline, and they drop off today's list.
- **Text** shows only when the person agreed to texts (TCPA: opt-in only), and only between 8am and 9pm where they
  are (by area code; unknown numbers must suit both coasts). Otherwise the button's place says why, such as "It is
  10:30 pm where they are. Texts go between 8am and 9pm their time."
- "What do you want to do?" and the three most urgent "Needs you today" items follow.

All the words are in English and Spanish. The texting-hours sentence is English for now.

**A microphone on every page.**

- In full view, a round microphone button sits at the bottom left; in simple view, it is the big "Talk" button.
- Either one opens Your AI Assistant and starts listening straight away. Recording stops by itself after a minute.
- The words are written down by the AI provider that already writes down voice notes (`/voice/transcribe`). The
  recording is not kept.
- The browser's own speech recognition is not used, because on some browsers it sends the audio to the browser maker,
  which is not a listed subprocessor.
