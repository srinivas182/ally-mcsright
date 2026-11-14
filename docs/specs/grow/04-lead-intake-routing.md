# Grow 04 — Lead intake, routing and speed to lead (Sprint 16A)

Status: **Approved November 2026.** Part of Lead-IQ (grow/02). 16A-1 is intake, routing and speed to
lead; 16A-2 is the unified inbox (two-way texts and email); 16B is the AI first responder, scoring,
source return on spend and the broker speed scorecard.

## Leader review

Reviewed: Follow Up Boss, Lofty, kvCORE/BoldTrail, Sierra Interactive, Ylopo, Real Geeks.

| Capability                                              | Leaders                | RightAlly 16A                                                                                       |
| ------------------------------------------------------- | ---------------------- | --------------------------------------------------------------------------------------------------- |
| Portal lead emails (Zillow, Realtor.com, Homes.com)     | ✓ (FUB parsers)        | ✓ parsers **plus an AI fallback for any sender**, every value checked against the email             |
| Lead API / Zapier / webhooks                            | ✓                      | ✓ per-source keys (hashed, shown once), idempotent `external_id` so retries never duplicate         |
| Website forms                                           | ✓ (iframes/JS widgets) | ✓ **plain HTML form that works without JavaScript** (crawlable, accessible, fast) with TCPA wording |
| Existing contact goes to their agent                    | ✓ (FUB)                | ✓ and the inquiry is added to their timeline                                                        |
| Round robin, by zip/price/source, first to claim, ponds | ✓ (FUB, Lofty, Sierra) | ✓ ordered rules, plus "skip agents marked away"                                                     |
| Reassign if not contacted in X minutes                  | ✓ (FUB, Sierra)        | ✓ **business-hours aware** (the clock pauses overnight), then pond or broker                        |
| Instant alert to the agent                              | email/push/text        | email + text + **automated call: "press 1 to be connected to the lead now"**                        |
| Response time per lead                                  | ✓                      | ✓ measured to the first real contact (call, text or email logged), not to opening the lead          |
| Source attribution                                      | UTM + source           | ✓ **plus AI answer engines (ChatGPT, Perplexity, Gemini, Copilot, Claude) as their own channel**    |

## Intake

Every lead creates a `crm_leads` row (one per inquiry) and a company contact (or matches an existing
contact by email or phone). Three ways in, each a **lead source** set up by an owner or staff member
(Contacts → Leads → Sources):

1. **Lead API** — `POST /api/leads` with `Authorization: Bearer <key>`. Keys are random, stored as a
   SHA-256 hash, shown once, revocable. Fields: names, email, phone, message, intent (buy/sell/rent),
   zip, price range, property, utm fields, referrer, landing URL, `external_id` (idempotency), consent
   wording when the lead ticked a consent box. Zapier, Facebook/Google lead ads (through Zapier) and
   custom sites use it. 120 requests a minute per source.
2. **Website form** — a hosted page at `/l/{token}` (server-rendered) and an embed snippet: a plain
   HTML `<form>` posting to the same address, with an optional 1 KB script that remembers the visitor's
   first referrer and UTM tags (so a visitor who arrived from ChatGPT and submitted three pages later is
   still attributed to ChatGPT). Spam: honeypot field, minimum fill time, throttle. The TCPA consent
   box is unticked by default and its exact wording is recorded with time and IP (Consent, CRM 01).
3. **Portal emails** — each brokerage gets a private forwarding address
   (`leads-<token>@<inbound domain>`). Emails arrive by inbound-email webhook (Postmark format,
   basic-auth secret). Zillow, Realtor.com and Homes.com emails are read by parsers; any other sender is
   read by AI, and only names, emails and phone numbers that appear in the email are kept. Emails with no
   email address or phone number are kept as "needs review" for the broker, never lost.

## Attribution (SEO and AEO)

Each lead stores the referrer host, UTM tags and landing page, and a **channel**:
`ai_search` (chatgpt.com, perplexity.ai, gemini.google.com, copilot.microsoft.com, claude.ai, meta.ai,
you.com, or `utm_source` naming one of them), `organic` (Google, Bing, DuckDuckGo, Yahoo, Ecosia),
`paid` (utm_medium cpc/ppc/paid, gclid/fbclid), `social`, `portal`, `email`, `referral_site`, `direct`.
The leads screen shows leads by channel so brokerages see what their SEO and AEO work brings in.

## Routing

Ordered rules (first match wins), each with conditions — sources, zip codes, price range, intent — and a
method:

| Method         | Behaviour                                                               |
| -------------- | ----------------------------------------------------------------------- |
| Agent          | Always this agent                                                       |
| Round robin    | Next agent in the rule's list; agents marked away are skipped           |
| First to claim | Offered to everyone in the list at once; the first to tap Claim gets it |
| Pond           | Straight to the lead pond (CRM 01, addition 22)                         |

No match: the fallback (the broker, or the pond). An existing contact with an active agent always goes to
that agent. **Response window** (default 5 minutes): if nobody has called, texted or emailed the lead in
that time, it moves to the next agent in the rule (up to the set number of passes), then to the fallback.
The window counts only between 8am and 9pm brokerage time.

## Speed to lead

The assigned agent (or everyone offered) gets an email at once, plus — when they opted in and RightAlly
HQ has texts and calls switched on for the brokerage (Sprint 15D) — a text and an automated call:
"New Zillow lead: Jane Doe, buying around 78704. Press 1 to be connected now." Pressing 1 calls the lead
from the brokerage number. No call is connected to a contact marked Do Not Call. Texts and calls to
agents only between 8am and 9pm.

Response time is measured from arrival to the first call, text or email logged on the contact.

## Data (cell)

`crm_lead_sources`, `crm_lead_settings` (per brokerage), `crm_routing_rules`, `crm_leads` (message
encrypted), `crm_lead_alert_prefs`. Control: `crm_inbound_addresses` (forwarding token → brokerage).

## Permissions

Owners and staff manage sources, rules and settings, see every lead and the speed figures; agents see
leads assigned or offered to them. The API and forms never reveal whether a contact already existed.
