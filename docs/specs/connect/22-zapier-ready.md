# Connect 22: Zapier and Make, ready to use (CH1)

Status: built in CH1. Tracker row B28. This builds on the following, which already existed:

- the Lead API (`POST api/leads`, specs/connect/10);
- forwarded portal emails (specs/grow/04);
- signed outgoing webhooks (specs/connect/01).

## What the market leaders do

- **Follow Up Boss** and **kvCORE / BoldTrail** have a published Zapier app with instant triggers.
- **Lofty**, **Sierra** and **Brokermint** offer polling triggers or copy-and-paste webhook addresses.
- **Where they fall short:**
    - Sample data in the Zap editor is often real client data.
    - Zaps that are switched off leave webhooks behind.
    - The broker cannot see what Zapier is subscribed to.

## What RightAlly adds

The broker makes a Lead API key in Grow › Leads › Sources. Zapier and Make sign in with that same key and can use
these endpoints (all need `Authorization: Bearer <key>`):

| Endpoint                                      | What it does                                                                                           |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `GET api/zapier/me`                           | Checks the key. Shows the brokerage, the key's name, the events, and whether outgoing webhooks are on. |
| `POST api/zapier/hooks` `{target_url, event}` | Subscribes an instant trigger for one event.                                                           |
| `DELETE api/zapier/hooks/{id}`                | Unsubscribes it, for example when a Zap is switched off. Only the key that made it can remove it.      |
| `GET api/zapier/samples/{event}`              | Made-up example data, so no real person's details leave RightAlly while a Zap is built.                |
| `POST api/leads`                              | Sends a lead in (unchanged).                                                                           |

### How subscriptions work

Each subscription is an ordinary outgoing webhook:

- It is signed, retried and checked against private addresses.
- It is shown in Settings › Apps as "Zapier: <key> (<event>)", so the broker can see it and switch it off.
- Events go out only when the broker has turned on Outgoing webhooks.
- A brokerage can have at most 50 webhooks.

### More lead emails recognised

Forwarded lead emails from these senders are now read too:

- **Opcity**, which is Realtor.com ReadyConnect;
- **Homesnap**, which is Homes.com;
- **Facebook lead ads**, from `facebookmail.com`.

## Next

The full public API, with contacts, deals and partner OAuth apps, comes in API1 (A17).
