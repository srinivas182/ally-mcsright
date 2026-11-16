# 06. Brokerage Health Check (HC1a)

Plan item E04 (Brokerage Health Check, website scan, instant branded preview). Status: built in HC1a. The switching tools (import centre, signup checkout and automatic launch, pilot go-live support) follow in HC1b.

## Why

Brokers switch when they can see, in their own numbers, what they would gain. Today the big platforms make them sit through a demo first. The Health Check gives a broker a useful report in three minutes, with no card and no sales call, and gives RightAlly a qualified conversation only when the broker asks for one.

## Leader review

| Leader                            | What they do                                                  | Where they fall short                                                         |
| --------------------------------- | ------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Lofty                             | Tech-stack savings page; onboarding specialists; success plan | Demo before anything; no timeline or guarantee; setup fee for larger rollouts |
| Follow Up Boss                    | Free contact import, migration coach, 14-day trial            | CRM only; automations and routing re-tested by hand                           |
| BoldTrail (kvCORE)                | Consolidation articles quoting about 35% savings              | No personal calculation; sales-led                                            |
| TotalBrokerage / Brokerage Engine | Back-office consolidation; per-agent cost survey              | No CRM, leads or websites in the comparison                                   |
| Website graders                   | Generic website score                                         | No real estate checks, no AI-answer readiness, no fix plan                    |

RightAlly adds: no sales call, six plain-English scores each with one fix, a website check that includes AI-answer readiness, an honest savings comparison that keeps what RightAlly does not replace, and a preview of RightAlly in the broker's colours in English or Spanish.

## Pages

All on the RightAlly host (`core.hq_host`), server-rendered, readable without JavaScript, 18px text and 52px+ targets.

| Address                               | What                                                             | Indexed |
| ------------------------------------- | ---------------------------------------------------------------- | ------- |
| `GET /health-check`                   | The questionnaire, with Organization, WebPage and FAQPage markup | Yes     |
| `POST /health-check`                  | Saves the check, emails the private link, opens the report       | —       |
| `GET /health-check/r/{token}`         | The report (refreshes itself while the website check runs)       | No      |
| `GET /health-check/r/{token}/preview` | RightAlly in the broker's colours, `?lang=es` for Spanish        | No      |
| `GET /health-check/r/{token}/logo`    | The uploaded logo (`nosniff`, no scripts)                        | No      |
| `POST /health-check/r/{token}/delete` | The broker deletes the report and logo                           | —       |
| `GET /hq/health-checks`               | HQ list: score, best saving, "Wants a call" or "Report only"     | No      |
| `GET /hq/health-checks/{ulid}`        | HQ view of a report (audited)                                    | No      |

## Scores

Fixed rules, no AI, so the same answers always give the same report (`HealthScore`). Each area is Good (80+), Worth fixing (50–79) or Fix first (under 50).

| Area                             | Score                                                                                                    |
| -------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Tools and cost                   | Per agent a month: ≤$50 → 100, ≤$100 → 80, ≤$150 → 60, ≤$200 → 40, more → 20; −5 per tool over five      |
| Speed to new leads               | 5 minutes 100, an hour 70, same day 40, later 15, don't know 10                                          |
| Deal file compliance             | As uploaded 100, before closing 70, after closing 35, rarely 10                                          |
| Paying agents                    | Same day 100, 2–3 days 75, a week 45, longer 20                                                          |
| Past clients and repeat business | All agents 100, some 55, none 20                                                                         |
| Website                          | Weighted share of the website checks passed (not scored if no site was given or it could not be reached) |

The overall score is the average of the scored areas.

## Website check

`WebsiteScan` reads only the home page and `robots.txt`, `sitemap.xml` and `llms.txt`, and only after the broker ticks "I manage this website". It never downloads images or other pages and keeps no copy of the page, only which checks passed.

- HTTPS only; `http://` and bare domains are tried as `https://`.
- Every hop (up to three redirects) goes through `Connect\Webhooks\SafeUrl`: no IP addresses, internal names, private or reserved ranges; the connection is pinned to the checked IP.
- 8-second timeout, 1.5 MB cap.

Checks (weight): secure address (2), under 2.5 seconds (1), phones (2), title (1), description (1), main heading (1), page language (1), real estate business markup (2), FAQ markup for AI answers (1), `llms.txt` (1), sitemap (1), search engines allowed (1), home search (2), Spanish pages (1), Equal Housing Opportunity notice (1), privacy policy (1), image descriptions (1), contact details (1).

The site's `theme-color` becomes the preview colour when the broker leaves the colour picker on RightAlly's colour.

## Savings comparison

`Savings` works only from the prices the broker enters (monthly, or per agent × agents).

- `Tools` lists what RightAlly does with each tool: **replaces**, **partly** or **keeps**. Only "replaces" comes off; "partly" and "keeps" stay in the after-switch total. Update `Tools` when a sprint lands.
- Each active RightAlly package is compared: package + kept tools (+ agent plans at list price when the brokerage pays for agents). The best is marked; if RightAlly would cost more, the report says so.
- When agents pay for their own plans, the brokerage's earnings per agent (commission on the floor plus markup at list price) are shown separately and never counted as savings.

## Privacy and safety

- The report link is a 40-character random token; only its SHA-256 hash is stored. It works for 180 days; `billing:prune-health-checks` (daily 03:40) deletes older reports and logos.
- The broker can delete their report at any time.
- Logos: PNG, JPG or WebP up to 1 MB, served with `nosniff` and a no-script content policy. No other images are fetched.
- No phone number is asked for, so nothing is texted. HQ contacts only brokers who ticked "Wants a call"; the others get the emailed link only.
- Hidden honeypot field and 5 submissions an hour per IP; report pages 60 a minute.
- Reports and previews send `noindex, nofollow`, `no-store` and `no-referrer`.

## Tests

`tests/Feature/Billing/HealthCheckTest.php`: indexable questionnaire with FAQ markup; scores, savings, website checks and the emailed private link; RightAlly costing more and agent earnings kept apart; consent, private addresses and bots; redirects only to checked addresses; preview in English and Spanish, logo headers, deleting; 180-day expiry; HQ list and access.

## Left for HC1b

Switch centre (one screen running the CRM and deal importers with progress and automatic post-move checks), self-serve checkout with public prices and automatic launch, 30-day pilot go-live plan with adoption tracking. Spanish questionnaire and report.
