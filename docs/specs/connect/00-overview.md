# Connect — the RightAlly app marketplace (overview)

## Goal

Every brokerage and every agent has one **Apps** page. They connect the tools they already use in one click,
and their data syncs into RightAlly. That lets them keep a tool while moving to RightAlly, or move off it, without
re-typing anything.

- **Connecting is free.** People pay only when real money moves: ad spend, or leads bought from a vendor.

**Owner:** Team A. The sprint order and the plan items each sprint covers are in `docs/plan/delivery-plan.md`
under "Connect".

## Principles for every app (approved 2026-11-14)

1. **Two-way sync goes directly through each vendor's own API.** CSV import and forwarding emails are only for a
   one-time import, or for apps with no API.
2. **One click, GoHighLevel-style.** The person presses **Connect**, signs in to their app, approves, and is back on
   the Apps page, connected. There are no keys to copy and no settings to fill in. One-click (OAuth) is used
   wherever the vendor offers it.
3. **When a vendor only offers an API key,** the app's page walks through it in plain steps ("In Follow Up Boss, go
   to Admin → API, copy the key, paste it here") with a short video, and the key is checked straight away.
4. **Built for brokers and agents who don't want to learn anything new.** Most are over 50, so:
    - big buttons, plain words, and nothing technical unless they ask;
    - status shown as "Connected ✓, last synced 2 minutes ago", plus a **Connect again** button when something
      breaks.
5. **Their existing app keeps working.** RightAlly works alongside it, and on its own if they disconnect or never
   connect anything (every module stands alone).
6. **One return address for every brokerage domain.** One-click sign-in returns through RightAlly's central
   address. The tokens are swapped for a single-use ticket there and handed back to the brokerage's own system,
   the same way "Sign in with Google" works (specs/connect/02).

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

## HQ app registry: every app is enabled by HQ (approved 2026-11-14)

- **Only apps HQ has enabled can be connected.** HQ sees every app on **HQ → Connect setup**. Brokers and agents
  only see an app as "Ready to connect" when HQ has switched it on and its setup is complete. Every other app shows
  as "Coming soon" with an **I want this app** button.
- **Each app has a setup card in HQ.** It shows:
    - what the app needs: a one-click sign-in app (client ID and secret), a partner or API key, or nothing;
    - **the return address to add**, with a Copy button;
    - **step-by-step tips**: where to register, what to click, which permissions to tick;
    - **whether a partnership is needed**, with a link to the vendor's partner or developer programme;
    - the boxes for keys and secrets. They are encrypted, write-only, never shown again, need a password
      confirmation to save, and are audited;
    - an expiry date for secrets that expire, with a warning 30 days before;
    - **Enable or Disable**. Disabling stops new connections and pauses every existing one (they keep their saved
      data and resume when re-enabled).
- **Adding new apps as we go.** Every app is a small definition in code:
    - name, category, scope;
    - how it signs in: one-click (OAuth), API key, or webhook;
    - what it syncs;
    - its HQ setup tips;
    - a driver.

    Drivers reuse a **connector kit**: one-click sign-in, token refresh, paging, rate limits, retries, sync with
    external IDs, field mapping and health. A new app is usually one driver file plus its mapping.

- **We build an app wherever the vendor publishes API documentation.** Where API access needs a partner
  agreement, the app is built and tested against the vendor's documented API and sandbox, and listed in HQ as
  "Needs partner access". HQ enables it once the partnership is approved.

## Apps by category

**Bold** apps are planned in the sprint shown. _Italic_ apps were added on 2026-11-14 (they go in the same
sprint as their category, or the next one).

| Category                  | Apps                                                                                                                                                                                                                                                                     | Sprint       |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------ |
| Import and automation     | CSV import, Outgoing webhooks, Zapier and Make, Lead API (all live)                                                                                                                                                                                                      | C1a          |
| Calendar                  | Google Calendar, Microsoft 365 Calendar (live once HQ sets them up); _Apple iCloud Calendar_ (later: CalDAV, no one-click)                                                                                                                                               | C1b, later   |
| AI assistants             | **RightAlly MCP server**: connect Claude, ChatGPT, Microsoft Copilot or Gemini to your RightAlly account (specs/connect/03)                                                                                                                                              | M1, M2       |
| CRM                       | **Follow Up Boss**, **BoldTrail (kvCORE)**, **Lofty**, **Sierra Interactive**, **Real Geeks**, **BoomTown**, **Wise Agent**, **Top Producer**, **HubSpot**, **Salesforce**, _GoHighLevel_, _CINC_, _Brivity_, _Propertybase_, _LionDesk_, _Rechat_, _Cloze_, _Pipedrive_ | C2a–C2d      |
| Lead sources              | Lead forwarding email (live), **Facebook Lead Ads**, **Realtor.com**, **Zillow Premier Agent** (partner), **Homes.com**, **Ylopo**, **Google Local Services Ads**, **REDX**, **Vulcan7**, _Redfin Partner Agent_, _Market Leader_, _Landvoice_, _SmartZip_               | C3a–C3b      |
| Ads                       | **Meta (Facebook and Instagram)**, **Google Ads**, _YouTube (through Google Ads)_, _Nextdoor_ (later)                                                                                                                                                                    | C4a, C4b, C7 |
| Transaction management    | **SkySlope**, **dotloop**, **Lone Wolf (TransactionDesk and back office)**, **Brokermint**, **Paperless Pipeline**, _Open to Close_, _DocuSign_, _Authentisign_, _zipForm_, _Form Simplicity_                                                                            | C5a–C5b      |
| Accounting                | **QuickBooks Online**, **Xero**                                                                                                                                                                                                                                          | C6           |
| Showings and open houses  | **ShowingTime**, _BrokerBay_, _Aligned Showings_, _Spacio_                                                                                                                                                                                                               | C8           |
| Marketing                 | **Canva**, **Social posting** (Facebook, Instagram, LinkedIn, Google Business Profile), **Mailchimp**, **Direct mail** (Lob, ProspectsPLUS), _Constant Contact_, _BombBomb_, _Corefact_                                                                                  | C7           |
| Phones                    | **RingCentral**, **Mojo Dialer**, _Dialpad_, _Aircall_                                                                                                                                                                                                                   | C7           |
| Property data             | **ATTOM**, _BatchData_, _PropStream_                                                                                                                                                                                                                                     | C8           |
| Lenders, title and escrow | **Lender partners**, **Title and escrow**, _Homebot_, _Total Expert_, _Qualia_, _Updater_                                                                                                                                                                                | C8           |
| Reviews                   | **Google and Zillow reviews**, _RealSatisfied_, _Testimonial Tree_                                                                                                                                                                                                       | C8           |

About 80 apps in total. Before each app is built, its API documentation is checked for: one-click sign-in or key
only; what can be read and written two-way; webhooks for instant updates; rate limits; sandbox access; and whether
a partner agreement is needed. The result goes into the app's HQ setup card.

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
