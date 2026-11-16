# Ads 04 — Instant forms on RightAlly Boost ads

**Sprint C4d.** Boost ads (specs/ads/02) run from RightAlly's ad account and Page. Before this sprint they could only
send people to the listing page. Now they can also open an instant form, and each lead goes only to the brokerage and
agent whose ad it came from.

## How it works

1. **Publishing.** When a Boost ad with `goal = form` is published, MetaApi makes the form on RightAlly's Page.
    - It records the form in the control table `ads_boost_forms` (form_id → tenant_id, campaign_id) before the ad can
      run.
    - It subscribes the Boost Page to `leadgen` (`/{page}/subscribed_apps`, with the Page token).
    - If the publish fails, the mapping is removed.
2. **The webhook.** Meta sends the lead to the one Facebook Lead Ads webhook. No brokerage connection owns
   RightAlly's Page, so `AppWebhookController` offers the signed part to the handlers tagged `connect.unrouted`
   (Core `UnroutedWebhook`).
3. **Routing.** `Ads\Meta\BoostLeads` takes the part only if all of these are true:
    - the app is `facebook_leads`;
    - the Page is the Boost Page;
    - the form is in `ads_boost_forms`;
    - the brokerage is active.

    It then queues `ReceiveBoostLead` inside that brokerage.

4. **Reading the lead.** The job reads the lead with RightAlly's Page token. It goes through lead intake under the lead
   source "RightAlly Boost lead forms" (Facebook):
    - assigned to the agent who paid for the ad;
    - tagged `utm_campaign = rightally-{ulid}`, so it counts in the ad's results;
    - Meta's lead id is the external id, so retries never make a second lead.

RightAlly keeps no copy of the lead outside the brokerage's CRM. Leads from Boost ads are never resold or shared with
another brokerage.

## Rules

- A promoted post still can't use a form: it sends people to the post's link.
- A form needs an `https://` privacy policy link. It defaults to the brokerage website's `/privacy` page
  (specs/websites/15).
- Housing special ad category and the place-only audience are unchanged.

## Go-live (HQ)

- The Boost system user's token must belong to the same Meta app as Facebook Lead Ads (so leadgen webhooks reach
  RightAlly). It also needs `leads_retrieval` and `pages_manage_ads` on the Boost Page.
- The Facebook Lead Ads app keys (client secret) must be set, so webhooks are verified.

## Tests

`tests/Feature/Ads/BoostTest.php` (instant-form Boost ad; lead routed to the right brokerage and agent once; unknown
forms ignored).
