# 05 — Live two-way sync (Sprint C2a-2, Team A; first app: Follow Up Boss)

After the import (specs/connect/04), RightAlly and Follow Up Boss stay in step without anyone pressing anything.
The engine pieces here are generic (`Core\Connect\TwoWay`), so the next two-way CRMs reuse them.

## From Follow Up Boss into RightAlly

- **Live (within seconds).**
    - When the import finishes, RightAlly registers webhooks for `peopleCreated`, `peopleUpdated`,
      `peopleTagsCreated`, `peopleStageUpdated` and `notesCreated`.
    - Each connection gets its own address on the central host:
      `https://{core.sso_host}/connect/hooks/follow_up_boss/{brokerage ulid}/{connection ulid}`. The address holds no
      keys.
    - Follow Up Boss only lets the **account owner** register webhooks. The webhook ids are saved on the connection
      (`settings.live`, `settings.webhooks`).
- **Every webhook is checked first.** The `FUB-Signature` header must equal
  HMAC-SHA256(base64(raw body), RightAlly's system key).
    - A forged or unsigned webhook gets 401 and nothing is read or queued.
    - Bodies over 256 KB are refused.
- **We answer straight away** (204) and do the work in a queued job, `HandleWebhook` (3 tries).
    - The job fetches the people (`/people?id=…`, up to 100) or notes (`/notes/{id}`, up to 20) and saves them
      with the same rules as the import.
    - Live updates are logged as "Live update: {event}" in the app's history.
- **A removed connection answers 410,** so Follow Up Boss deletes the webhook. A paused connection, or an app HQ
  has switched off, answers 204 and changes nothing.
- **Not the account owner?** Webhook registration is refused, so the connection checks for changes **every 15
  minutes** instead (`interval_minutes = 15`).
- **Safety net, for every connection:** each scheduled run (hourly when live) reads people newest-changed first
  (`sort=-updated`). It stops at the last check (one minute of overlap; repeats are harmless) and brings in any new
  notes for the people who changed.
- **What updates do:**
    - A known person's first and last name follow Follow Up Boss.
    - New tags are added.
    - The stage replaces the old "FUB stage: …" tag (one stage at a time).
    - Emails and phones of known people are not overwritten.
    - A person who is already in RightAlly and is linked for the first time is not overwritten.
    - **Deletions are not copied:** deleting in Follow Up Boss never deletes in RightAlly.

## From RightAlly into Follow Up Boss

- **New contacts:** `POST /v1/people`, with name, primary email and phone, source "RightAlly", and their agent
  (the Follow Up Boss user matched by email).
- **New notes:** `POST /v1/notes`, subject "From RightAlly". The person is added first if they aren't in Follow
  Up Boss yet.
- **Who is sent where:**
    - The brokerage's connection receives company contacts only.
    - An agent's own connection receives only that agent's personal contacts.
    - A personal sphere never goes to the brokerage's Follow Up Boss.
- **How sends run:**
    - Each send is a queued job, `PushToFollowUpBoss`, dispatched after the save. It has 5 tries with backoff and
      sends one at a time per connection.
    - It is safe to repeat: everything sent is remembered in `connect_external_ids`.
    - A failed send never breaks the save in RightAlly.
- **No ping-pong:** changes that Follow Up Boss just told us about are saved "quietly"
  (`FollowUpBoss::$quiet`), so they are never sent back.
- **Not yet:** edits in RightAlly to existing contacts (name, email, stage) going to Follow Up Boss. That is the
  next part.

## Disconnecting

Before the sign-in is revoked, RightAlly deletes its webhooks in Follow Up Boss. Disconnecting always works, even if
Follow Up Boss can't be reached.

## Contracts (for developers)

- `Core\Connect\TwoWay extends Connector`: `verifyWebhook`, `webhook`, `push`, `disconnected`.
- `ConnectorKit` adds `externalId`, `connections`, `connection`, `webhookUrl` and `saveSettings`.

## Tests

`tests/Feature/Connect/FollowUpBossLiveTest.php`:

- webhooks registered at the connection's address;
- 15-minute checks for non-owners;
- signed people updates (names, one stage);
- forged or unsigned webhooks refused, and 410 for a removed connection;
- notes in and never sent back;
- new contacts and notes out, with their agent, never twice;
- personal contacts kept out of the brokerage's account;
- the changed-people check stops at the last check;
- webhooks deleted on disconnect.
