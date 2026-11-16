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
