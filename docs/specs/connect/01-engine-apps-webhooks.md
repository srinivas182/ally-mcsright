# 01 — Connect engine, Apps page, webhooks (Sprint C1a, Team A)

## Apps page

- **Where:** `/apps`, for owners, staff and agents (Settings → Apps).
- **The catalog:**
    - grouped by category, with search and filters (All, Connected, Ready to connect);
    - each tile shows Connected, Needs attention or Coming soon;
    - apps are shown by name with a monogram tile, never another company's logo.
- **One app's screen** (`/apps/{key}`) shows:
    - what the app syncs, its cost (free in RightAlly, plus whatever the vendor charges), and who connects it;
    - the connect form;
    - each connection's status, its last 5 sync runs and, for webhooks, its last 15 deliveries;
    - buttons to sync now, pause or resume, and disconnect.
- **Coming-soon apps** have an "I want this app" button. Requests are counted per brokerage on the control
  plane (`hq_app_requests`), and HQ sees them on **HQ → App demand** (`/hq/apps`).

## Who connects what

| App scope | Owners and staff        | Agents                       |
| --------- | ----------------------- | ---------------------------- |
| brokerage | connect it for everyone | see it, but can't connect it |
| agent     | connect their own       | connect their own            |
| both      | connect the brokerage's | connect their own            |

- Nobody sees anyone else's personal connection.
- Credentials are encrypted at rest (`encrypted:array`), hidden from serialisation, and never sent to the
  browser.
- Connecting requires a password confirmation. Connecting, disconnecting and app requests are audited.

## Engine

- **Contracts (Core):**
    - `Core\Connect\App` and `AppCatalog`: modules declare apps.
    - `Connector` (`verify`, `sync`) with `ConnectionData`.
    - `NeedsReconnect`.
    - `Outbox`, with the default `NoOutbox`.
- **Connect module:**
    - `connect_connections`, `connect_sync_runs`, `connect_webhook_deliveries`;
    - `Connections` (connect, disconnect, sync, pause, ping, request);
    - `SyncRunner` with the `SyncConnection` job, unique per connection;
    - `Health`.
- **Health:**
    - A success clears the error count.
    - After 3 failures in a row, the connection shows an error, and its owner (or the brokerage's owners) gets an
      email at most once a day.
    - `NeedsReconnect` sets "needs reconnect" and sends an alert right away.
- **`connect:sync`** runs every 15 minutes. It queues due syncs (each app's interval, an hour by default) and
  deletes webhook deliveries older than 30 days.

## Outgoing webhooks

- **Events:** `lead.created`, `contact.created`, `contact.stage_changed`, `booking.created`, `task.completed`,
  and `ping` (the "Send a test event" button).
- **Privacy:** only company contacts are ever sent. An agent's personal contacts never leave through the
  brokerage's webhooks (specs/crm/01).
- **Address rules:**
    - HTTPS only, on port 443 or 8443;
    - no IP addresses or internal names, and no user name or password in the URL;
    - every IP the host resolves to must be public, checked when saving and again at every delivery;
    - the request connects to the checked IP (`CURLOPT_RESOLVE`), with no redirects, a 10-second timeout and a
      5-second connect timeout.
- **Signing:**
    - header `X-RightAlly-Signature: t=<unix>,v1=<HMAC-SHA256 of "t.body">`, using the endpoint's secret
      (`whsec_…`);
    - the secret is shown once, after connecting;
    - receivers should reject requests older than 5 minutes.
    - The headers `X-RightAlly-Event` and `X-RightAlly-Delivery` are also sent.
- **Payload:** `{id, event, created_at, data}`, stored encrypted.
- **Retries:** after 1 min, 5 min, 30 min, 2 h and 6 h, then the delivery is marked failed. An address that is no
  longer allowed isn't retried.
- Webhooks never break the action that caused the event.

## Built-in apps

- **CSV import:** upload, then the existing column matching and import (`/contacts/imports`).
- **Zapier and Make:** instructions. Leads come in through the Lead API; events go out through webhooks.
- **Lead API** and **Lead forwarding email:** show the brokerage's lead sources and forwarding addresses, with a
  link to manage them.

## Tests

`tests/Feature/Connect/ConnectTest.php`, and the page-contract sweep for `/apps`, `/apps/{key}` and `/hq/apps`.
