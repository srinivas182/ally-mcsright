# API 01: Public API v1, partner apps and webhooks (API1)

Status: built in API1. Tracker row A17. Module: `modules/Api`.

## What the market leaders do

- **Follow Up Boss** has a strong REST API with API keys and webhooks, and a big partner list. Partner apps get the
  whole account; there is no per-scope consent and no broker allow-list.
- **kvCORE / BoldTrail**, **Lofty** and **Sierra** have partner-only APIs behind sales agreements. Brokerages cannot
  build on them themselves.
- **Lone Wolf**, **SkySlope** and **Dotloop** offer transaction APIs to vetted partners. Commission data is often
  included.
- **Where they fall short:**
    - All-or-nothing access.
    - No owner control over which apps connect.
    - No idempotency, so retries make duplicates.
    - Sensitive money data is exposed.

## What RightAlly does

### Address and authentication

The API lives at `https://{brokerage domain}/api/v1`. It returns JSON, versioned with the header
`RightAlly-Version: 2026-11-01`. Its description is at `GET /api/v1/openapi.json` (OpenAPI 3.1).

There are two ways to call it, and both act as one person and see only what that person sees on screen:

- **Personal API keys** (`ra_key_…`), made at Settings › API and partner apps:
    - the person picks the scopes;
    - an expiry date is optional;
    - the key is shown once and stored as a hash;
    - "last used" is shown;
    - the key can be turned off at any time;
    - owners see and can turn off every key.
- **Partner apps (OAuth 2, authorization code)**:
    - RightAlly HQ registers each partner (HQ › Partner apps): name, company, website, allowed scopes, return
      addresses, and whether it is verified. The client secret is shown once.
    - **The brokerage's owner allows an app** before anyone there can connect it. Turning it off revokes everyone's
      tokens at once.
    - The consent screen names the app, the company and the verified badge, and lists each scope in plain words. If
      the brokerage has not allowed the app, there is no Allow button.

### Scopes

OAuth scopes carry an `api:` prefix (for example `api:contacts:read`).

| Scope             | What it allows                                                                                     |
| ----------------- | -------------------------------------------------------------------------------------------------- |
| `contacts:read`   | Contacts you can see                                                                               |
| `contacts:write`  | Add and change contacts, add notes and tasks                                                       |
| `deals:read`      | Your deals: address, status, dates and price only. Never commission, splits, fees or bank details. |
| `tasks:read`      | Your tasks                                                                                         |
| `webhooks:manage` | Owners and staff only                                                                              |

**Never offered:** deletes, messaging many people, money movements, bank details, commission splits. An AI
assistant's MCP token (`mcp:use`) is not an API token.

### Endpoints

- `GET /me`
- `GET /contacts` (`updated_since`, `q`, `cursor`, `limit`), `POST /contacts`, `GET` and `PATCH /contacts/{id}`
- `POST /contacts/{id}/notes`, `POST /contacts/{id}/tasks`
- `GET /tasks` (`status=open|done|all`)
- `GET /deals`, `GET /deals/{id}`
- `GET`, `POST` and `DELETE /webhooks`

**Lists** are newest-changed first, with an opaque `next_cursor`.

**Webhooks** made through the API are ordinary signed outgoing webhooks:

- The secret is shown once.
- Addresses are checked against private networks.
- Only the key or app that made a webhook can remove it.
- They are listed under Settings › Apps.

### Errors, limits and retries

- **Errors** look like `{"error": {"code", "message", "fields"}}`. Codes: `unauthenticated`, `insufficient_scope`,
  `forbidden`, `not_found`, `invalid`, `rate_limited`.
- **Rate limit.** 120 calls a minute per key or token, with `X-RateLimit-*` headers and `Retry-After` on 429.
- **Idempotency.** Writes accept `Idempotency-Key`. A retry within 24 hours returns the first answer, with the
  header `Idempotent-Replay: true`.
- **Audit.** Every write is audited with the key or app that made it.

## Security

- Keys are stored hashed and belong to one brokerage; a key used on another brokerage's address is refused.
- Partner tokens need both the brokerage's allow-list and API scopes.
- Making a key and allowing an app both need the password to be confirmed.
