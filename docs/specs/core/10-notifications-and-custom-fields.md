# 10: Notification centre and custom fields (Sprint N1)

Status: **Built** (Sprint N1, 2026-11-16, Team A). Covers plan item A09: notifications, files and media, custom fields
and CSV import. Files and media were already built (deal documents, website media, roster documents), and so was CSV
import. N1 adds the notification centre and custom fields, and connects both to imports.

## Leader review (November 2026)

| Product            | Strengths                                            | Gaps                                                            |
| ------------------ | ---------------------------------------------------- | --------------------------------------------------------------- |
| Follow Up Boss     | Custom fields usable in smart lists and automations  | Alerts spread across email, app and texts, with no single inbox |
| Lofty              | Notification preferences per channel                 | Noisy; no quiet hours or digest                                 |
| BoldTrail (kvCORE) | Custom fields on contacts                            | Few field types; none on deals                                  |
| HubSpot            | Custom properties everywhere, with field permissions | Generic, not built for real estate                              |

RightAlly's approach:

- **One place for alerts:** one inbox for every alert.
- **Your choice of how:** per-kind choices, quiet hours and a daily digest.
- **Fields everywhere:** custom fields on contacts, deals and agents.
- **Fields that do work:** they can be hidden from roles and required before closing, and they come across in
  imports.
- **AI help:** fields can be filled with AI from notes.
- **Less noise:** a "fewer, better alerts" tip.

## 1. Notification centre (`Modules\Core\Notices`)

- **Every email alert lands in the inbox.** `CaptureEmailNotices` listens to `NotificationSending`. Every email
  alert to a person is also written to `user_notices`, with:
    - the subject as the title;
    - the first line as the text;
    - the action link as "Take me there".

    No module had to change.

- **Kinds of alert.** `NoticeTypes` names each kind. Modules can register a plain-English label, a group, and
  "urgent". Unregistered kinds are named from their class. Access changes (R1) are registered as urgent.
- **Essential messages** (sign-in codes, password resets, verification, security messages, receipts, invitations):
    - always go by email straight away;
    - are never copied into the inbox;
    - can't be turned off.
- **Choices per kind** (Notifications → What you get): in RightAlly on or off, and email on or off.
- **Quiet hours** run in the person's time zone, for example 9pm to 7am. Emails are held, and `core:notice-digests`
  (hourly) sends them in one email when quiet hours end.
- **Daily digest:** one email at 7am instead of one per alert.
- **Urgent alerts** ignore quiet hours and the digest.
- **The bell** in the header shows the unread count (a shared `notices` prop) and the latest eight alerts, with
  "Mark all read".
- **The inbox page** has "Take me there", "Snooze a day" (an alert comes back unread later) and "Mark read".
- **"Fewer, better alerts":** a kind the person opened less than 10% of, over at least 8 alerts in 30 days, gets a
  one-click "Stop emailing these".
- **Not in N1:**
    - phone push, which comes with the native apps;
    - texts beyond those modules already send for urgent cases.

## 2. Custom fields (`Modules\Core\CustomFields`)

- **Where.** Fields go on contacts, deals and agents. They are managed in Settings → Custom fields (permission
  `core.fields.manage`), with up to 50 active fields per record type. Archiving keeps the values.
- **Types:**

    | Type            | Rule                       |
    | --------------- | -------------------------- |
    | text            | —                          |
    | number          | —                          |
    | money           | stored to the cent         |
    | date            | `YYYY-MM-DD`               |
    | one choice      | —                          |
    | several choices | —                          |
    | yes or no       | —                          |
    | link            | `https` web addresses only |

    Values are checked by type. The type can't change once a field has values.

- **Hiding from roles.** A field can be hidden from roles without one of the R1 field groups (commission amounts, or
  bank details). Hidden fields show as "Hidden for your role", and people can't change them.
- **Required to close.** Deal fields can be "needed before the deal can close". An empty one joins the deal's "still
  needed" list (`field_missing`), which already gates closing.
- **Where they appear.** A "More details" panel on:
    - each contact, editable by whoever manages the contact;
    - each deal, editable until it is locked;
    - each roster agent page, editable by roster managers.

    Every change is audited with the old and new values.

- **Smart lists** filter by custom fields: `custom: {key: value}`. For yes-or-no fields the value is true or false;
  for several-choice fields it matches any one choice.
- **CSV import.** A column whose header matches a field name is matched to that field automatically, and custom
  fields can be picked in the column list. A value that doesn't fit its field's type is skipped and reported for
  that row.
- **Connected CRMs.** Follow Up Boss "custom…" fields come across. Any field the brokerage doesn't have yet becomes a
  text field ("Brought over by an import"), so nothing is lost. Other connectors can pass `custom` the same way.
- **Fill with AI** (contacts): a suggested value from the contact's notes, with the words it came from. It is saved
  only when the person confirms it. Suggestions that don't fit the field's choices are dropped. The feature key is
  `crm.field_fill`.

## Tests

`tests/Feature/Core/NoticesAndCustomFieldsTest.php`:

- **Notifications:**
    - the inbox and the bell;
    - snooze;
    - essential messages left alone;
    - email off, quiet hours, urgent access alerts and the daily digest;
    - the noisy tip.
- **Custom fields:**
    - field types and validation;
    - hidden fields;
    - the smart list filter;
    - the deal closing gate;
    - CSV and Follow Up Boss imports;
    - Fill with AI.
