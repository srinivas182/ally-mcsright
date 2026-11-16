# Movewise MW2: consumer accounts, saves and alerts, AIlly, calculators, Spanish

Status: **Built** (sprint MW2). Follows [00-overview](00-overview.md) and [01-site-and-states](01-site-and-states.md).

## 1. What a home buyer or seller gets

| Feature                  | How it works                                                                                                                                                                      |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign in                  | Email only. Movewise emails a 6-digit code (10 minutes, 5 tries, 5 codes an hour). No password, no phone number.                                                                  |
| Save homes               | "Save" on every card and home page. Needs sign-in; the dialog signs you in and then saves.                                                                                        |
| Save searches and alerts | "Save this search and email me new homes". Daily by default; weekly or off in My Movewise. Up to 20 searches.                                                                     |
| Stop alerts              | Every alert has a stop link and `List-Unsubscribe` + `List-Unsubscribe-Post` headers (RFC 8058 one-click). No sign-in needed; the link is signed.                                 |
| My Movewise (`/account`) | Saved homes, saved searches with frequency, the move plan with ticks, download my information (JSON), delete my account (tick "yes" first), sign out.                             |
| AIlly                    | "Ask AIlly" on every page; on a home page she knows that home's facts (address, price, beds, baths, size, monthly cost). She says she is an AI.                                   |
| Calculators              | `/afford` (28/36 rule, then the highest price whose true monthly cost fits), `/net-sheet` (payoff, agents, closing, repairs), `/move-planner` (tasks dated back from moving day). |
| Spanish                  | Every page also lives under `/es`.                                                                                                                                                |

Nobody is contacted. Agents and connections come in MW3 and MW4.

## 2. Design decisions

- **Separate from the back office.** Movewise people aren't tenant users. Their tables are on the control plane: `movewise_people`, `movewise_sign_ins`, `movewise_sessions`, `movewise_saved_homes`, `movewise_saved_searches`, `movewise_chats`. The router still has no Laravel session and no back-office cookies.
- **Sign-in cookie.**
    - `mw_sid` is 64 random hex characters. It is HttpOnly, Secure and SameSite=Lax, and lasts 90 days.
    - The database stores only its SHA-256 hash.
    - Sign-out and delete remove the session row.
- **No CSRF token; checks instead.** Pages stay cacheable at the edge because they are the same for everyone, so they can't carry a CSRF token. Instead, every request that changes something must:
    - carry an `Origin` (or `Referer`) header whose host is Movewise's own host;
    - stay under a per-IP rate limit (start 10/min, verify 20/min, AIlly 20/min, other changes 120/min).
    - The SameSite=Lax cookie is a second line of defence.
- **Pages stay public and cached.** The signed-in state comes from `GET /_me` (`no-store`), which JavaScript calls on load. `/account` and `/_me` are `no-store`.
- **Content-Security-Policy on every Movewise response:**
    - `script-src 'self'`; the one script is `/assets/movewise.js`.
    - `connect-src 'self'`, `frame-ancestors 'none'`, `object-src 'none'`, `form-action 'self'`.
    - Images may come from `https:`, for MLS photos.
    - The JavaScript puts server text on the page only as text, never as HTML.
- **Works without JavaScript.** The calculators are GET forms worked out on the server. The account pages are plain forms. Save and AIlly need JavaScript, and their buttons stay hidden until it loads.
- **AIlly:**
    - Runs on the AI gateway as the HQ tenant. The feature is `movewise.ailly` and the action `ai.movewise` costs 0 credits, so HQ pays and no brokerage is charged.
    - The prompt is `prompts/movewise/ailly.md`.
    - Everything the visitor sends is passed as untrusted content.
    - Each reply is screened by Fair Housing as HQ. A reply that fails is replaced with a neutral answer.
    - Chats keep the last 40 messages. A chat is tied to the person when they are signed in, and a chat ID can't be used to read someone else's conversation.
- **Alerts:** `movewise:alerts` runs daily at 13:17 UTC. It sends homes new or reduced since the last email, only in states that are on and only to verified emails. The postal address in the footer comes from `MOVEWISE_POSTAL_ADDRESS` (CAN-SPAM).
- **Spanish and SEO:**
    - English is at `/`, Spanish at `/es/...`.
    - Each page has its own canonical, plus `hreflang` en, es and x-default links.
    - `<html lang>` is set per page and the breadcrumbs JSON-LD follows the language.
    - The pages sitemap lists both languages for the home, search, state and calculator pages.
    - The language is set on every request, so Octane workers never carry it over.
    - Strings are in `resources/lang/{en,es}.php`, and the test checks both files have the same keys.
- **Calculators for AEO.** Each calculator page carries a FAQ block (`FAQPage` JSON-LD) with a plain-English answer, and is indexable when no inputs are given. Pages with inputs are `noindex`.

## 3. Not in MW2 (honest gaps)

- **Home value estimates:** closed in [MW9](09-gaps.md) §3 (HQ-paid ATTOM estimates with a cost cap, `/home-value`, and AIlly).
- **Spanish coverage:** closed in [MW9](09-gaps.md) §4 (planner, cost table, calculators and courtesy translations of the legal pages).
- **Delete scope.** Deleting an account removes the person, sessions, saved homes and searches, sign-in codes and their chats. Once MW4 adds connections, deletion must also respect records the brokerage is required to keep. That is noted for MW4.

## 4. Tests

`tests/Feature/Movewise/MovewiseConsumersTest.php` covers:

- **Sign-in:** the email-code flow (wrong code, origin check, hashed session, cookie flags).
- **Saves:** saving homes and searches (filter allow-list) and the account page.
- **Move plan:** saving and ticking it.
- **Alerts:** sent once a day, with a signed stop link and a one-click POST.
- **Data rights:** export and delete.
- **Calculators:** the maths, indexable pages, sitemap entries.
- **AIlly:** gets the home's facts, keeps the conversation, Fair Housing replacement, origin check.
- **Spanish:** pages, hreflang and canonical, the language not sticking between requests, CSP.
