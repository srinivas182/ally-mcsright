# 04 — Follow Up Boss (Sprint C2a-1, Team A)

Follow Up Boss is the most-used CRM among US teams and brokerages, so it is the first CRM in Connect. It works
through Follow Up Boss's own API and the same one-click connect as the calendars (specs/connect/02).

C2a is split in two so each part is small:

- **C2a-1 (this sprint):** connect in one click, and everything comes over (people, tags, stage, agent, notes).
- **C2a-2 (next):** live two-way sync. Changes in Follow Up Boss arrive within seconds (their webhooks), and
  new people, notes and stage changes made in RightAlly go back to Follow Up Boss.

## For the broker or agent

1. Apps → Follow Up Boss → **Connect**.
2. Sign in to Follow Up Boss and press **Allow**.
3. That's all. People start coming over straight away; the app page shows how many came over and when.

Who connects it (`scope: both`):

- **An owner or staff member** connects the brokerage's Follow Up Boss account. People come in as company
  contacts, each with their agent. Agents are matched by email; a person whose agent isn't in RightAlly stays
  with the person who connected.
- **An agent** connects their own account. People come in as their personal contacts.

## What comes over

| Follow Up Boss               | RightAlly                                                                                    |
| ---------------------------- | -------------------------------------------------------------------------------------------- |
| Person: name, emails, phones | Contact (primary email and phone), source "Import", source detail "Follow Up Boss: {source}" |
| Assigned user                | The contact's agent (matched by email; brokerage connections only)                           |
| Tags                         | Tags (up to 20 per person)                                                                   |
| Stage                        | A tag "FUB stage: {stage}"                                                                   |
| Notes                        | Timeline notes "From Follow Up Boss: …", at their original date (HTML removed)               |

Rules:

- **Safe to repeat.** Every person, note and user is remembered by its Follow Up Boss id
  (`connect_external_ids`), so running again never adds anything twice.
- **No duplicates.** A person already in RightAlly with the same email or phone is linked, not copied.
- **Visibility.** A brokerage contact the connecting person can't see is left alone (not linked, not tagged).
- **Acts as the person who connected it,** through the same services as the screens (ManageContacts, Tags,
  Timeline). If that person is deactivated, the connection asks to be connected again.
- **No texting consent comes across.** Imported people are not texted automatically until they opt in here (TCPA).
- People without a name, or without an email or phone, are skipped and counted.

## How it runs

- The first sync starts as soon as the connection is saved.
- Each run handles up to 5 pages of 100 (users first, then people, then notes) and saves a cursor. If there is
  more, the next run is queued 5 seconds later. Only one run per connection is queued, and only one runs at a time.
- People are paged with Follow Up Boss's `next` marker, notes by offset.
- A 429 ("slow down") keeps the cursor and carries on in the next run. A 401 or 403 marks the connection
  "needs reconnecting" and tells the owner (Health, specs/connect/01).

## Sign-in details (for developers)

- Authorize: `https://app.followupboss.com/oauth/authorize` with `response_type=auth_code`, `prompt=login`, no PKCE.
- Token: `https://app.followupboss.com/oauth/token`, client ID and secret sent as HTTP Basic, `state` included.
  Refresh works the same way.
- Every API call sends `Authorization: Bearer …` plus RightAlly's registered-system headers `X-System` and
  `X-System-Key`.
- The account label comes from `GET /v1/me`.
- These are set in the app's definition (`App::$oauth`: `authorize`, `token`, `userinfo`, `response_type`, `pkce`,
  `token_auth`, `token_state`, `params`, `headers`); `Providers::forApp()` reads them, so the next CRM with
  OAuth needs no new sign-in code.

## HQ setup (HQ → Connect setup → Follow Up Boss)

The card opens with a **go-live tip**, a short checklist that ticks itself off:

1. **Send the request to Follow Up Boss.** Press **Email Follow Up Boss**. HQ's own email opens with a
   ready-written message: what RightAlly is, what it needs, and the return address already filled in. There is
   also **Copy the message** for sending another way. RightAlly itself sends nothing, and HQ can change the
   address before sending. (api@followupboss.com is our best guess: check it with Follow Up Boss.) The message
   asks for the keys through a secure channel.
2. **Paste the keys they send back.** This is ticked once every key is saved.
3. **Switch Follow Up Boss on.** The card then shows "Live for everyone".

Until the app is live, its row shows a "Tip: request to send" badge. Every app can carry its own request
(`setup.request`: `to` or `url`, `subject`, `body` with `{return_address}`), so each new app comes with its own tip.

The full steps:

1. Register RightAlly as a system at apps.followupboss.com/system-registration (free). Copy the system name
   and key.
2. Ask Follow Up Boss to create an OAuth app for RightAlly, using the return address shown on the card.
3. Paste the system name, system key, client ID and client secret (encrypted, write-only, password-confirmed,
   audited). Then switch Follow Up Boss on.

Partner note: system registration is free; being listed in Follow Up Boss's integrations directory is optional.

## Tests

`tests/Feature/Connect/FollowUpBossTest.php`:

- one-click connect, with Basic auth for the token and system headers on API calls;
- import of people with agent, tags, stage and source;
- notes at their original date;
- linking existing contacts;
- running again changes nothing;
- an agent's own connection gives personal contacts;
- a contact the agent can't see is left alone;
- 429 carries on later;
- 401 asks to reconnect;
- the app can't be connected while HQ has it off.
