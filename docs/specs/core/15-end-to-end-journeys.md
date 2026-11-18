# End-to-end journeys (E2E1, E2E2)

**Status:** built in E2E1; E2E2 adds seven journeys for the batch-2 features and an accessibility check on every page they open. Runs every night; more journeys are added in the integration sprints (58–63).

## What it is

The feature tests check each piece on its own. The end-to-end journeys check that the pieces work **together, in
a real browser**, against a real server, with the production build and **MySQL**: the way a person uses
RightAlly.

| Journey                                                                                  | Checks                                                                   |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| An agent signs in and opens a new deal, which shows on the deals board                   | sign-in, the deals board, creating a deal                                |
| The owner signs in with two-step verification and makes text larger, and it stays larger | two-step sign-in, Easier Reading saved and applied before the page draws |
| A visitor reads the brokerage website and sends an enquiry, which reaches the brokerage  | the public website, the enquiry form and its robot checks, lead routing  |
| The website has an accessibility statement and a sitemap                                 | SEO and accessibility pages on the brokerage website                     |
| An agent sets up a referral network profile once the brokerage switches it on            | owner settings, the network, the agent's profile                         |

E2E2 (`e2e/batch2.spec.ts`) adds:

| Journey                                                                          | Checks                                                                     |
| -------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| RightAlly installs like an app, and the search bar finds a contact               | the app manifest and offline page, adding a contact, search                |
| The owner sees outside services, features and approvals in plain words           | HQ/tenant outside services with Off/Test/Live, feature switches, approvals |
| A visitor asks the brokerage what data it holds and is asked to confirm by email | the public privacy request form and its email check                        |
| An agent forwards contracts to their own Deal-IQ address and adds an assistant   | Deal-IQ email intake address, My assistants with presets                   |
| An agent opens the call queue and sees what to say when someone is busy          | the call queue and call cards                                              |
| An agent starts an accountability pod with the owner and checks in               | Learn pods, check-ins                                                      |
| The owner opens bank reconciliation and an appointment's recording panel         | bank feeds (read-only), appointment recording and coaching                 |

Every page these journeys open is checked with axe; a serious or critical accessibility problem fails the run.
Fixed along the way: the credit meter's grey text and the default button's hover colour now meet the 4.5:1
contrast rule.

The website and app journeys also run on a phone-sized screen.

## How it runs

- `.github/workflows/e2e-nightly.yml` every night at 09:17 UTC, or by hand ("Run workflow"). A failed night keeps
  the report, screenshots, traces and server log for 14 days.
- `php artisan e2e:seed --password=…` makes the "e2e" brokerage: an owner with two-step verification (a test
  secret), an agent and a published website. It refuses production and can run again safely.
- The journeys type the owner's six-digit code the way an authenticator app would; if a code was just used or
  just ran out, they wait for the next one, like a person.
- Locally: build (`NO_REMOTE_FONTS=1 npm run build` when the font service can't be reached), run the server on
  port 80, then `E2E_NO_SERVER=1 npx playwright test`.

Why nightly, not on every pull request: a full browser run takes several minutes, and every feature already has
its own tests on each pull request.
