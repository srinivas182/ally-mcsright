# 11 — More lead sources: Google Local Services Ads and other lead vendors (Sprint C3b, Team A)

## Google Local Services Ads ("Google Guaranteed")

**For the broker or agent:** Apps → Google Local Services Ads → **Connect**. They sign in with the Google account
that runs the ads and press **Allow**. Every call, message and booking then becomes a lead within 15 minutes.

- **Sign-in:** the same Google sign-in app as the calendars (HQ → Connect setup), with the `adwords` scope.
- **API:** the Google Ads API (`https://googleads.googleapis.com/{connect.google_ads_version, default v22}/`), with
  RightAlly's developer token sent as the `developer-token` header.
- **Accounts:** `customers:listAccessibleCustomers`, refreshed daily, up to 10 accounts. Accounts without Local
  Services return nothing and are skipped.
- **Leads:** each account is searched with
  `SELECT local_services_lead.id, lead_type, category_id, contact_details, lead_status, creation_date_time FROM
  local_services_lead WHERE creation_date_time > '{last check}'`.
    - The first run reaches back 30 days.
    - The consumer's name, phone and email fill in the lead, and the message says what kind it was (phone call,
      message or booking).
    - UTM data is `google / local_services`.
- **Lead intake** goes through the shared `Crm\Connect\ConnectedLeads`, which Facebook Lead Ads now uses too:
    - one "Google Local Services Ads" source per brokerage;
    - the external id `lsa-{id}`, so a lead is never taken twice;
    - an agent's connection's leads go to that agent, and a brokerage connection's leads follow its routing rules;
    - texting consent is never assumed.
- **HQ setup:** the Google sign-in app, plus a Google Ads API developer token (free; Basic access review). The
  app is ready only when both are in place: setup `needs: provider` now also checks the app's own keys. The
  `adwords` scope has to be added to the Google app's OAuth verification.

## Other lead vendors

Ylopo, Redfin Partner Agent, Market Leader, Landvoice, SmartZip, REDX and Vulcan7 can all email new leads, so
their tiles are ready and on by default, the same way as the portals (specs/connect/10).

- **What the tile shows:**
    - plain steps for adding the RightAlly lead address in the vendor;
    - the brokerage's forwarding addresses;
    - a pointer to the Lead API app for anyone using Zapier.
- **Reading the email:** PortalEmail reads each one, with labelled fields first, then AI. A name, email or phone
  is kept only if it appears in the email.
- **Direct feeds** (instead of email) need a partner agreement with each vendor. HQ's cards say so; Redfin has no
  direct feed.

## Tests

`tests/Feature/Connect/GoogleLsaTest.php`:

- one click with the `adwords` scope;
- leads come from every reachable account, and non-Local-Services accounts are skipped;
- the developer token is sent with each request;
- a lead is never taken twice;
- the app is not ready until HQ has added the developer token;
- the vendor tiles show their steps and partner notes.
