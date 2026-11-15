# Connect 15 — Marketing and phone apps (C7)

Sprint block **C7** (Team A, with D for consent and E for ads). Plan item: C39. Four sprints, one at a time.

## Rules for every app in this block

1. **Consent first (specs/grow/11).** Nothing goes to an email, mail or phone app for a contact who opted out of
   that channel. Opt-outs made in the other app (unsubscribes, bounces marked "cleaned", "do not call") come back
   and stop that channel here, recorded with the app as their source. Opt-ins never come back without proof.
2. **Fair Housing on every word we send (specs/grow/12).** Audiences are never built from protected
   characteristics; ad and postcard copy passes the check under its channel.
3. **The brokerage's data stays the brokerage's.** Leads from the brokerage's own site or ads are never sold or
   shared; apps get only what the feature needs (name, email, tags; for mail, name and postal address).
4. **Paid apps follow C6b.** HQ sets each app free or a monthly add-on; usage costs (postage, ad spend) go through
   RightAlly Credits or the person's own account, shown before anything is bought.

## C7a — Email marketing: Mailchimp and Constant Contact

- **Connect** with one click (OAuth 2). Mailchimp gives the account's data centre with the token (metadata
  endpoint); Constant Contact uses OAuth 2 with PKCE and rotating refresh tokens.
- **Choose an audience/list** once, and which RightAlly tags or smart lists go to it.
- **Who goes:** by default only contacts who agreed to email here (email consent "yes"), because Mailchimp and
  Constant Contact both require permission for marketing lists. The broker can widen it to every contact with an
  email address who hasn't opted out, after confirming they have permission; that choice is audited. Opted-out
  contacts never go.
- **Push, hourly and on change:** contacts are added or updated by email (Mailchimp: PUT member by the hash of the
  lower-cased email, status `subscribed`, with their RightAlly tags as Mailchimp tags; Constant Contact:
  create-or-update into the chosen list). Someone sent who later opts out here is unsubscribed in Mailchimp or taken
  off the Constant Contact list.
- **Whose contacts:** a brokerage connection sends the brokerage's contacts, never agents' personal ones; an
  agent's own connection sends only their contacts.
- **Unsubscribes come back:** Mailchimp list webhook (`unsubscribe`, `cleaned`) to the connection's own address
  (which also answers Mailchimp's GET check); Constant Contact hourly poll of unsubscribed contacts since the last
  run. Each becomes an email opt-out here (source `connected_app`, the app named).
- **Settings → Email marketing** (linked from the app): the audience, who goes, contacts sent, last push and
  unsubscribes this month. Changing the audience sends everyone again to the new one.
- Status (C7a built): Mailchimp tokens don't expire (kept as good for ten years); its webhook address answers
  Mailchimp's GET check and takes form posts; Constant Contact tags follow in a later pass.

## C7b — Phones: RingCentral, Dialpad, Aircall (Mojo partner-only)

- **Connect** (OAuth 2; Aircall API ID and token or OAuth). Brokerage or agent level.
- **Calls and texts logged** on the matching contact (by phone number) from the apps' webhooks: direction, length,
  who handled it, outcome; unknown numbers create nothing (no scraping).
- **Recordings:** a link only, and only where the brokerage confirms its recording notice; two-party-consent
  states noted on the setup page. No transcription without the brokerage switching it on.
- **Do not call** set in the phone app (where its API exposes it) comes back as a call opt-out.
- **Speed to lead counts** a logged outbound call as a response to an open lead.
- Mojo: partner-only (no public API); its "Partner" note explains how to ask.

## C7c — Direct mail: Lob (ProspectsPLUS and Corefact partner-only)

- **Just listed, just sold, open house and farming postcards** from a listing or a smart list, with the brand kit,
  a QR code to the listing or a funnel (tracked visits), and the brokerage's required disclosures.
- **Addresses verified** (US verification) before anything prints; undeliverable ones are dropped and shown.
- **Price shown first** (printing and postage per card from Lob, plus HQ's margin) and paid with RightAlly Credits;
  a proof (PDF) to approve; cancel until Lob's cancellation window closes, with the credits returned.
- Copy passes the Fair Housing check (channel `ads`). Farming lists never include people who asked not to be
  contacted.

## C7d — Google Ads and YouTube, Canva, social posting

- **Google Ads:** search and YouTube campaigns for listings, funnels and the brokerage, from the person's own
  account or RightAlly Boost, with Google's housing ads policy built in: no targeting by age, gender, parental or
  marital status or ZIP code; location by radius only. Results in leads, appointments and commission dollars, with
  the automatic stop (as Meta ads).
- **Canva:** open a design with the brand kit and listing photos (Canva Connect), and bring the finished design back
  for posts, ads and postcards.
- **Social posting:** Facebook Pages, Instagram (business accounts), LinkedIn and Google Business Profile; post
  listings and market updates now or scheduled, Fair Housing checked, with the MLS attribution the listing needs.

## Order and approvals

C7a, then C7b, C7c and C7d, each with its own tests and plan row. Each app's developer registration, review and
partner steps appear on HQ's Connect setup page; apps needing a partner agreement show as partner-only until it is
signed.
