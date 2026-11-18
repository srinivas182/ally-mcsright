# 16 — Feature switches, seats, outside services, approvals and support access (FL1)

Status: **delivered (Sprint FL1, 2026-11-18)**. Closes A03 (module registry, seats, approval rules) and A11
(feature flags, support access).

## Why

RightAlly is sold to many brokerages with different packages. HQ must be able to switch parts of the product on or
off for everyone, for a share of brokerages (gradual roll-out) or for one brokerage; sell some parts by the seat; and
connect outside providers (voice AI, bank feed, search, staging, email and phone checks, payments, bot protection,
inbound email, video, licence look-up) in a way that can be tested safely before anything real happens. Brokerages
must be able to tidy their own menus, require approval for sensitive changes, and control when RightAlly support may
view their account.

## Market check

- Real Brokerage (reZEN, Leo Pro), BoldTrail, Lofty and Follow Up Boss all sell add-ons per seat and switch modules
  per account. None offers brokerages a self-service list of support visits or "support must ask first"; enterprise
  SaaS (Salesforce, HubSpot) does, so we match that.
- Add-ons beyond parity: gradual roll-out by share, Test and Live keys kept apart with a "Check connection" button and
  Live locked until a test passes, test contacts so a test can never reach a real client, and approval rules that
  never let the person who asked approve their own change.

## Areas (the module registry)

`Modules\Core\Switches\Areas` holds every area that can be switched: key, plain-English label and description, the page
paths it owns, whether it can be sold by the seat, and its default. Core registers 19 areas (Ads, Ally Studio,
Appointments, Movewise, Referral network, Recruiting, Learn, Prospector, Postcards, Social posts, Reviews, Funnels,
List-IQ, Outreach, Game plan, Team chat, Revenue share, Buyer demand, CMA and estimates). Modules can add their own
areas the same way; a beta area can start off and be rolled out.

Seat-sold areas: Ads, Ally Studio, Learn, Prospector, List-IQ.

### Whether an area is on (`FeatureSwitches::on`)

1. HQ's setting for the brokerage (on or off) wins.
2. Otherwise HQ's setting for everyone: off; on; or on for a share of brokerages. The share uses a stable number from
   0 to 99 per brokerage and area, so growing the share never drops brokerages that already have it.
3. Otherwise the area's default.
4. The brokerage may then switch an area off for itself. It can switch it back on, but never on when HQ has it off.

A switched-off area disappears from the menu (sidebar, phone bar, command bar) and its pages answer "not found".

### Seats

HQ sets a seat limit for a seat-sold area in a brokerage. Without a limit everyone can use it. With a limit, only
owners and people the owner gives a seat to can use it; other people see "You need a seat … Ask your broker". The
owner cannot give more seats than the limit.

Tables: `feature_switches` (control; tenant 0 = everyone), `tenant_seats` (control), `seat_holders` (cell).

## Outside services

`Modules\Core\Services\Catalog` lists 13 services with three providers each (the recommended provider first) and
where the keys live:

| Service                                | Providers (recommended first)                     | Keys                          |
| -------------------------------------- | ------------------------------------------------- | ----------------------------- |
| AI voice calls                         | Retell AI, Vapi, Bland AI                         | HQ, brokerage may use its own |
| Call and appointment transcripts       | Deepgram, AssemblyAI, ElevenLabs Scribe           | HQ                            |
| Live bank feed (read-only)             | Plaid, MX, Teller                                 | HQ                            |
| Fast listing search                    | Typesense, Meilisearch, Amazon OpenSearch         | HQ                            |
| Virtual staging and room visualization | Collov AI, REimagineHome, Virtual Staging AI      | HQ                            |
| Floor plans and 360-degree tours       | CubiCasa, Matterport, iGUIDE                      | HQ, brokerage may use its own |
| Email checks                           | ZeroBounce, NeverBounce, Kickbox                  | HQ                            |
| Phone checks                           | Twilio Lookup, Telnyx, Numverify                  | HQ                            |
| Sign-up payments                       | Stripe, Paddle, Chargebee                         | HQ                            |
| Bot protection                         | Cloudflare Turnstile, hCaptcha, Google reCAPTCHA  | HQ, brokerage may use its own |
| Forward-paperwork email address        | Postmark, SendGrid, Mailgun                       | HQ                            |
| Video intros, outros and reels         | own renderer, Shotstack, Creatomate               | HQ                            |
| Agent licence look-up                  | state public data, ARELLO, typed by hand          | HQ                            |
| Photo tagging                          | Restb.ai, Azure AI Vision, Google Cloud Vision    | HQ                            |
| Nearby schools and transit             | OpenStreetMap (Overpass), Geoapify, Google Places | HQ                            |

Rules:

- Each service is **Off**, **Test** or **Live**. Test and Live keys are saved separately, encrypted, and never shown
  again (secrets show as "Saved ••••1234"). Saving and changing the mode need the password re-entered.
- **Check connection** makes one harmless read-only call (an account, balance or list call) with the keys for Test or
  Live. Nothing is sent to people, nothing is charged, and nothing about the person checking is sent. Providers
  without a safe read-only call get a format check; the full check happens on first use.
- **Live unlocks only after a check passes in Test** with the same provider, and only when Live keys are saved.
  Changing provider clears the keys and switches the service off; new test keys need a new passing check.
- In Test, features may reach only the listed **test contacts** (`OutsideServices::mayReach`).
- Features call `OutsideServices::active($service, $tenantId)`: the brokerage's own account when the service allows it
  and the brokerage switched it on, otherwise HQ's; null when off.
- A brokerage's switch to Live goes through the approval rule "Switch an outside service to Live" when the owner set
  one.

Table: `outside_services` (control; tenant 0 = HQ).

## Approval rules

`Modules\Core\Approvals\Approvals`: modules register settings changes that can need approval. The owner chooses 0 to 3
approvers per action. Approvers are owners and people with "Approve sensitive changes other people ask for". The person
who asked can never approve their own change. Enough approvals and the change runs (as the person who asked); one
"Decline" stops it; no answer in 7 days closes it (`core:expire-approvals`, daily 02:35). Approvers are emailed
(urgent). First action: "Switch an outside service to Live" (default: no approval).

Automations keep their own approval step (spec 06).

Tables: `approval_rules`, `approval_requests`, `approval_votes` (cell).

## Support access

- The owner chooses **Support may help without asking** (default) or **Support must ask me first**.
- When support must ask, HQ's "Log in as" sends a request with the reason and emails the owners, with a link to their
  own address. The owner allows it for 24 hours, or declines it, and can end an allowed window early.
- Every support visit is listed for the owner: who, when, whose account and why. The visit keeps every safeguard of
  "Log in as" (60 minutes, banner, sensitive actions blocked, the person emailed).

Tables: `support_access`, `support_access_requests`, `support_visits` (control).

## Screens

- Settings → Features and seats (`/settings/features`), Outside services (`/settings/services`), Approvals
  (`/settings/approvals`), Support access (`/settings/support-access`, owners).
- HQ → Feature switches (`/hq/switches`), Outside services (`/hq/services`).

## Permissions

`core.platform.manage` (switch areas, give seats, connect outside services) and `core.approvals.give` (approve
sensitive changes). Owners have both.

## Defaults to review

- Support access starts as "Support may help without asking"; an allowed window lasts 24 hours.
- Approval requests close after 7 days; at most 3 approvers per rule; "Switch to Live" needs no approval until the
  owner sets one.
- Roll-out buckets are fixed per brokerage and area.
- C03 photo tagging:
    - 50 photos a day in Test and 2,000 in Live, with the first 25 photos of each listing tagged.
    - Answers below 60% confidence are ignored.
    - Restb.ai is priced per photo; confirm the price before Live.
- C03 nearby places:
    - 20 listings a day in Test and 1,000 in Live, each looked up again every 30 days.
    - Search distances: schools 2 miles, bus 1 mile, rail 3 miles, groceries 2 miles, coffee 1 mile.
    - Schools are never rated.
    - Google Places' limits on keeping results need a legal check before Live.
    - The public Overpass server is for light use; use a paid or own server for many listings.
