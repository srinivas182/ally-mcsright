# 10 — Lead sources: Facebook Lead Ads, Realtor.com, Zillow and Homes.com (Sprint C3a, Team A)

Leads must arrive within seconds or a minute, wherever they come from, and then go through the same lead intake as
every other lead: routing, speed-to-lead, the AI responder and source reports.

## Facebook Lead Ads (Facebook and Instagram)

**For the broker or agent:** Apps → Facebook Lead Ads → **Connect**. They sign in to Facebook, choose the Pages,
and press **Allow**. That's all.

- **Every Page they manage is included.** After connecting, RightAlly swaps the sign-in for a long-lived one,
  reads the Pages (`/me/accounts`) and subscribes each one to new leads (`POST /{page}/subscribed_apps`,
  `subscribed_fields=leadgen`).
    - Page tokens, which don't expire when they come from a long-lived sign-in, are kept in the connection's
      encrypted credentials.
    - Page names are shown on the app page.
    - The check repeats daily, so new Pages are picked up.
- **One webhook address for the whole app:** `https://{core.sso_host}/connect/apps/facebook_leads/webhook`. This
  works through the new app-level webhook routing:
    - `GET` answers Meta's setup check (`hub.challenge`), but only with HQ's verify token.
    - `POST` must carry `X-Hub-Signature-256`, an HMAC-SHA256 of the raw body made with the app secret. Anything
      unsigned or forged gets 401.
    - Each lead goes to the connection that owns its Page (`connect_routes`, in the control database), inside that
      brokerage only, as a queued job. Pages nobody has connected are ignored.
    - Once the signature is checked, the answer is always 200, so Meta never retries or switches the address off.
- **Reading the lead:** `GET /{leadgen_id}?fields=field_data,campaign_name,ad_name,form_id` with the Page's token.
    - `full_name`, or `first_name` and `last_name`, plus `email`, `phone_number` and `zip_code` fill in the lead.
    - Every other answer goes into the message, for example "When Are You Buying?: 3 months".
    - The campaign and ad names are saved as UTM data, so ROI reports see them.
- **Lead intake:**
    - The lead is filed under a "Facebook Lead Ads" source, made once per brokerage.
    - Meta's lead id is the external id, so a retry never makes a second lead.
    - A brokerage connection's leads follow its routing rules. An agent's connection's leads go to that agent.
    - Texting consent is never assumed from a Facebook form (TCPA).
- **Disconnecting** unsubscribes every Page and removes the routes.

**HQ setup** (on the card, with the return and webhook addresses):

1. Create a Business app at developers.facebook.com and complete Business Verification.
2. Add Facebook Login for Business, using the return address as the redirect URI.
3. Add Webhooks: choose Page, enter the callback URL and verify token, and subscribe to `leadgen`.
4. Request Advanced Access (App Review) for `leads_retrieval`, `pages_manage_metadata`, `pages_manage_ads`,
   `pages_read_engagement` and `pages_show_list`.
5. Paste the App ID, App secret and verify token (encrypted, write-only), then switch the app on.

Partner note: App Review and Business Verification are free and usually take a few days. Until they are done, only
the app's test users can connect.

## App-level webhooks (a reusable engine piece)

- **Contract:** `Core\Connect\AppWebhooks extends TwoWay`, with `handshake(query)` and `route(payload)` returning
  `list<{key, payload}>`.
- **Kit:** the kit adds `routeTo(connection, key)`, `unroute(connection)` and `saveCredentials(connection, …)`.
- **Ownership:** when two connections claim the same account, the most recent one wins.

## Realtor.com, Zillow Premier Agent and Homes.com

These work today through the brokerage's forwarding address. PortalEmail recognises each portal's emails and
reads them field by field.

- **Their tiles are ready and on by default** (built in). Each shows three plain steps for adding the RightAlly
  address in that portal, the brokerage's forwarding addresses, and a link to Leads → Settings.
- **Partner notes on HQ's cards:**
    - Zillow two-way sync (notes and status back to Zillow) is only for Zillow CRM partners, so RightAlly must apply.
    - A direct Realtor.com feed (instead of email) is optional and needs Move, Inc. partner access.

## Tests

`tests/Feature/Connect/FacebookLeadsTest.php`:

- the HQ handshake only accepts the right verify token;
- one click subscribes every Page, and Page tokens stay encrypted;
- a signed lead becomes one lead with the form answers, even on retry;
- unsigned or forged webhooks are refused, and unknown Pages are ignored;
- an agent's Page leads go to that agent, and disconnecting stops everything;
- the portal tiles show their steps and forwarding address.
