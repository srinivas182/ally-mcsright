# 07. Switching tools and go-live (HC1b)

Plan items A19 (signup checkout and automatic launch; the RightAlly website CMS is still to come), A11 (setup guide part) and B16 (pilot go-live support). Builds on the Health Check (spec 06), which now also runs in Spanish.

## Leader review

| Leader                 | What they do                                                    | Where they fall short                                                         |
| ---------------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Lofty                  | Onboarding specialists move contacts and pipeline; success plan | Demo first; no published timeline or guarantee; setup fee for larger rollouts |
| Follow Up Boss         | Free contact import, migration coach, unlimited training        | CRM only; emails, texts and routing re-tested by hand after the move          |
| BoldTrail (kvCORE)     | Implementation team                                             | Sales-led; no public prices; agents find it overwhelming                      |
| Brokermint / Lone Wolf | Back-office setup help                                          | Back office only; weeks of setup calls                                        |

RightAlly adds: public prices and online signup with no demo; the brokerage is created the moment the first payment goes through; one switch centre for every old app with progress per app; automatic checks after the move instead of manual re-testing; a 30-day plan whose steps tick themselves from real use, with a named coach, agent adoption and a "first deal closed in RightAlly" goal.

## Self-serve signup

On the RightAlly host, server-rendered.

| Address                       | What                                                                                               |
| ----------------------------- | -------------------------------------------------------------------------------------------------- |
| `GET /start`                  | Public prices (Product with an Offer per package, and FAQ markup; indexable) and the signup form   |
| `GET /start?check={token}`    | The form filled in from a Health Check report (brokerage, owner, state, agents, suggested address) |
| `GET /start/address?slug=`    | Whether an address is free                                                                         |
| `POST /start`                 | Saves the signup and sends the broker to the payment provider's page                               |
| `GET /start/welcome/{signup}` | Waiting (refreshes itself), live (address and next steps) or "we'll finish your setup"; noindex    |

- Nothing is created before payment. An address is held for an hour while its payment is pending.
- The payment provider's `checkout.session.completed` event with `kind: signup` creates the brokerage (`ProvisionTenant`, idempotency key `signup:{ulid}`), starts its subscription, invites the owner by email, and starts the go-live plan. Repeated events change nothing.
- The subscription's later events (paid, failed, cancelled) are matched to the brokerage through the signup.
- If the brokerage can't be created (for example its address was taken in the meantime), the signup is marked failed, every HQ staff member is emailed, and the welcome page says the team will finish the setup.
- The broker ticks that they can sign up the brokerage and agree to pay the price shown until they cancel.

## Switch centre (`/switch`, owner and staff)

- **Bring your people:** each connected CRM with how many people it brought over, when it last ran and any error; buttons for every available CRM app and for a spreadsheet.
- **Bring your deals:** the same for transaction apps, plus a file export.
- **Checks after the move**, read from the brokerage's own data:
    - every connected app is working;
    - every open company lead has an agent (pond leads excepted);
    - routing rules are on;
    - opt-outs came over (count);
    - nobody is texted without consent (only opted-in people can be texted, 8am–9pm);
    - every deal belongs to someone in RightAlly;
    - agents have accepted their invitations (flagged after 3 days).

## 30-day go-live plan

`GoLive` steps (target day): sign in (1), bring people (2), bring deals (3), lead routing (3), invite agents (5), half the agents signed in (7), website live (10), first deal started in RightAlly (14), 7 in 10 agents active this week (21), first deal closed in RightAlly (30). Each ticks itself from the brokerage's data; a step past its day is "late".

- The brokerage sees the plan, agent adoption and its coach on `/switch`.
- HQ → Go-live (`/hq/go-live`): every plan with day, steps done, late steps and adoption; HQ sets the coach (name, email, phone; audited). Signups waiting for payment or needing a hand are listed at the top.
- `billing:go-live-weekly` (Mondays 14:20 UTC): owners in their first five weeks get day, progress, the next three steps and their coach; at most once a week.

## Health Check in Spanish

`/health-check/es`, with `hreflang` links both ways. Every word is in `HealthCopy` (English and Spanish; a test fails if a key is missing). The report, the scores, the website checks, the tool notes, the email and the preview follow the language the check was taken in.

## Security and privacy

- Payment details never reach RightAlly; the provider's signed events drive everything.
- The welcome page shows only the brokerage's name, address and owner email to whoever has its 26-character link.
- The switch centre shows counts, never contact details. Credentials stay in Connect, encrypted and write-only.
- Honeypot field and rate limits on signup (10 an hour per IP) and the address check.

## Tests

`tests/Feature/Billing/SwitchAndSignupTest.php`: Spanish Health Check and copy completeness; prices, address check and checkout; brokerage created once on payment, subscription events after; failed creation tells HQ; prefill from a Health Check; switch centre checks and plan steps; HQ board, coach and weekly email.

## Still to come

The RightAlly website CMS (rest of A19) and the AI setup assistant (A21).
