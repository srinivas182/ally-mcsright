# End-to-end journeys (E2E1)

**Status:** built in E2E1. Runs every night; more journeys are added in the integration sprints (58–63).

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

The website journey also runs on a phone-sized screen.

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
