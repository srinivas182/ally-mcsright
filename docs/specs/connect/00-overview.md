# Connect — the RightAlly app marketplace (overview)

## Goal

Every brokerage and every agent has one **Apps** page. They connect the tools they already use in one click,
and their data syncs into RightAlly. That lets them keep a tool while moving to RightAlly, or move off it, without
re-typing anything.

- **Connecting is free.** People pay only when real money moves: ad spend, or leads bought from a vendor.

## Decisions (approved 2026-11-14)

1. **We build our own connectors.** There is no embedded-integration vendor and no per-connection fee, and we keep
   full control of data, security and upgrades. Each connector is a small driver on one shared engine.
2. **Ads come in two billing models.**
    - **(A) The agent's own Meta or Google ad account.** Free in RightAlly; the agent pays the platform directly.
    - **(B) RightAlly Boost.** Ads run on RightAlly's account and are paid with credits at HQ's margin. Metering
      works like Maps: HQ sees cost and margin, and spend is capped by the balance.
3. **Order:** C1 (engine, Apps page, CSV, webhooks/Zapier, calendars), then C2 (CRMs), C3 (lead sources),
   C4 (ads), C5 (transaction management), then C6 and later (accounting, showings, marketing, data).

## Shared engine (Connect module)

- **App catalog.** Modules declare apps in code. Each app has:
    - key, name and category;
    - the scope it applies to (brokerage, agent, or both);
    - how it signs in: account sign-in (OAuth), API key, webhook, forwarding email, or a link to a built-in screen;
    - what it syncs, plus a cost note;
    - its status: available or coming soon.
- **Coming-soon apps are listed too.** Each has a **Request** button, and HQ sees demand across all brokerages,
  which decides what we build next.
- **Connections.**
    - Each connection belongs to the brokerage or to one agent.
    - Credentials are encrypted at rest, never sent to the browser, and never shown again.
    - Connecting needs a password confirmation, and is audited.
- **Sync.**
    - Runs as queued jobs, one at a time per connection.
    - Every run is logged with counts.
    - The status shows whether the connection is healthy: "last synced …", an error, or "needs reconnect".
    - After 3 failures in a row, the connection's owner gets an alert.
    - Upserts are idempotent: they use external IDs and are safe to retry.
- **Consent carries over only when it is recorded.** Imported contacts never get automated texts unless written
  consent is on record (TCPA). A contact's opt-out in either system is honoured.
- **Fair Housing.** Every ad and every piece of generated copy goes through the Fair Housing check.

## Categories and planned apps

| Category                 | Apps (first ones in **bold**)                                                                                                                                                                                                                 | Sprint |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Import and automation    | **CSV import** (presets for Follow Up Boss, BoldTrail and Lofty exports), **Outgoing webhooks**, **Zapier / Make**, **Lead API**                                                                                                              | C1a    |
| Calendar and email       | **Google Calendar**, **Microsoft 365 Calendar** (two-way busy times for booking), later Gmail and Outlook email                                                                                                                               | C1b    |
| CRM                      | **Follow Up Boss**, **BoldTrail (kvCORE)**, **Lofty**, **Sierra Interactive**, Real Geeks, BoomTown, Wise Agent, Top Producer, LionDesk, Salesforce, HubSpot                                                                                  | C2     |
| Lead sources             | **Lead forwarding email** (works today), **Realtor.com**, **Facebook Lead Ads**, Zillow (two-way sync only for partner CRMs, so RightAlly applies), Homes.com, Redfin Partner, Ylopo, Market Leader, Google Local Services Ads, REDX, Vulcan7 | C3     |
| Ads                      | **Meta (Facebook and Instagram)**, Google Ads; listing boosts (new listing, open house, price drop, sold) with AI copy and images; leads tagged by listing                                                                                    | C4     |
| Transaction management   | **SkySlope**, **dotloop**, **Lone Wolf** (TransactionDesk and back office), Brokermint, Paperless Pipeline, Open to Close                                                                                                                     | C5     |
| Accounting               | QuickBooks Online, Xero                                                                                                                                                                                                                       | C6     |
| Showings and open houses | ShowingTime, open-house sign-in apps                                                                                                                                                                                                          | C6     |
| Marketing                | Canva, social posting (Facebook, Instagram, LinkedIn, Google Business Profile), Mailchimp, direct mail (Lob, ProspectsPLUS)                                                                                                                   | C7     |
| Phones                   | RingCentral, Mojo                                                                                                                                                                                                                             | C7     |
| Property data            | ATTOM or BatchData (valuations, likely sellers)                                                                                                                                                                                               | C7     |
| Partners                 | Lenders (Homebot, Total Expert), title and escrow (Qualia)                                                                                                                                                                                    | C8     |
| Reviews                  | Google and Zillow reviews on agent websites                                                                                                                                                                                                   | C8     |

## Ads rules (C4)

- **Meta Housing special ad category is required.**
    - Ad copy and images must not describe or target people.
    - Targeting is place-only, with Meta's minimum radius. The builder can't set age, gender or ZIP targeting.
- **Every ad carries the brokerage name and compliance text**, as the MLS and state rules require.
- **RightAlly Boost.** Spend is reserved in credits before an ad starts. It is stopped if the balance runs out.
  Unspent credits are released.

## Business steps (not code)

- **Zillow:** apply as a two-way CRM partner. Today the partners are Follow Up Boss, Top Producer and Lofty.
- **Meta:** app review and Business Verification, for `ads_management` and `leads_retrieval`.
- **Google:** an Ads API developer token, and OAuth verification for the Calendar scopes.
- **Microsoft:** Entra app registration, plus publisher verification.
- **Vendors:** API or partner access for each CRM and transaction vendor (Follow Up Boss, Lofty, Sierra, SkySlope,
  dotloop and Lone Wolf all run partner programs).
