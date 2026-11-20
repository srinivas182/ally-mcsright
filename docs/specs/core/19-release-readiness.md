# Release readiness (Sprints 58 to 63)

**Status:** Sprints 58 to 63 done. 58: integration and regression with pen-test preparation. 59: the full regression pass. 60: the load test with tuning on a large made-up brokerage (the full-scale staging run is still to do). 61: the internal security review (the outside firm's test is to book). 62: the SOC 2 readiness review (the company provides the policies and the auditor). 63: launch readiness (`launch:check`, the load-balancer fix in Http\Middleware\TrustLoadBalancer with `TRUSTED_PROXIES`, and the runbook). Version 1.0 is tagged at go-live.

The plan's last phase checks that everything built in Sprints 1 to 57 works together, holds up under load and attack, and is ready for SOC 2 and launch. This page records what each sprint checked, what it found, and what was fixed.

## Sprint 58: integration across modules, and pen-test preparation

### Every route accounted for

RightAlly has about 1,300 routes. `tests/Feature/Safeguards/RouteSecurityTest.php` checks four rules on every one:

1. Every HQ route is behind the HQ console check.
2. Every signed-in route belongs to an address it is allowed on: a brokerage's back office, HQ, or a tenant's own sign-in pages. Passport's OAuth consent and the central MCP address are the reviewed exceptions.
3. A route anyone can open must be on the reviewed public list, grouped by why it is public:
    - website pages and SEO files;
    - signing in;
    - signing up and referral links;
    - links sent to clients, where a token or signature is checked in the controller;
    - webhooks, which check the provider's signature;
    - the API, which has its own keys;
    - signed file links.

    **A new public route fails the test until someone reviews it and adds it to the list.**

4. A public route that changes something is rate-limited. The only exceptions are routes with their own limiter (API keys, MCP session close, sign-out, signed uploads).

The review found and fixed three issues:

- **Password reset** ("forgot password" and "reset password") had no limit. Someone could flood a person with reset emails or guess reset tokens. Each is now limited to 5 a minute per address and site, and 10 an hour per email address. This is on top of the one email a minute per account the password broker already enforces.
- **AI-app registration** (`oauth/register` and OAuth discovery, used when someone connects Claude or ChatGPT) had no limit. It is now limited to 20 a minute.
- **Account pages** from the starter kit (`dashboard`, `settings/profile`, `settings/password` and others) did not check which address they were opened on. Like every other sign-in page, they now exist only on a tenant's own address; anywhere else returns a neutral 404.

### Brokerage isolation at the database (TenantGuard)

**Found:** a brokerage owner could download **another brokerage's roster document** by its id. The query used `DB::table()` and forgot `where tenant_id`. Eloquent models were already safe: the TenantScope adds the condition. A sweep found about 360 raw queries on brokerage tables that rely on the condition being written by hand.

**Fixed for all of them at once.** Every database connection's query builder (`Modules\Core\Tenancy\Guard`) adds "and `<table>.tenant_id` = this brokerage" to every select, update and delete when:

- a brokerage is the current tenant;
- the query runs on its cell;
- the table has a `tenant_id` column.

How it holds up in awkward cases:

- The condition is added when the table is chosen, so it stays correct when the query is used inside another query.
- A nested "(a or b)" group never gets its own copy.
- A top-level "or" is bracketed after the condition, so it reads "brokerage and (a or b)".

Not limited:

- HQ, and work with no brokerage selected (console commands, cross-brokerage jobs);
- control-plane tables (`ControlTables::ALL` and `TenantGuard::PLATFORM`; an architecture test keeps both lists complete);
- the audit log, which writes each HQ action to both the brokerage's chain and HQ's, and has its own insert-and-select-only connection;
- `CrossTenant::query`, the one sanctioned and audited cross-brokerage path.

Tests: `tests/Feature/Safeguards/TenantGuardTest.php` and `tests/Architecture/TenantGuardTablesTest.php`.

### One journey through six modules

`tests/Feature/Integration/LeadToPayoutJourneyTest.php` follows one enquiry the way a brokerage works:

1. A visitor enquires on the brokerage website.
2. The lead and contact reach the agent.
3. The agent opens a deal for that contact, and it goes under contract.
4. The 80/20 commission plan splits it.
5. The commission arrives, and the agent is paid $12,000 by direct deposit in an approved payout run.
6. Every ledger journal balances.

A second brokerage then sees none of it, whether through its pages or through queries.

It runs alongside:

- the full regression suite, about 1,700 tests on SQLite and MySQL 8.4 on every pull request;
- the nightly browser journeys (E2E1, E2E2), which include accessibility checks.

### For the penetration testers (Sprint 61)

- The reviewed public route list above is the attack surface.
- Brokerage isolation rests on three layers: the host guards, TenantScope on models, and TenantGuard on every query.
- Rate limits sit on every public write.
- Secrets are encrypted and write-only, and changing them needs password confirmation.

## Sprint 59: full regression

- **Query budgets on 43 pages.** The query-budget test now covers 31 back-office pages as deals and agents grow, and 12 contact pages as contacts, tasks and notes grow. All stay flat: no page runs one query per row.
- **Every pull request** runs about 1,700 tests on SQLite and on MySQL 8.4, plus the ledger suite on MySQL.
- **Every night**, the browser journeys run on MySQL with accessibility checks.

## Sprint 60: load test and tuning

### How it was run

The load test ran on a made-up brokerage the size of a big real one:

- 1,000 agents, 100,000 contacts and 10,000 deals;
- a second brokerage with 500 agents, 50,000 contacts and 5,000 deals, so every brokerage filter faces mixed data;
- MySQL 8 and Redis, with `perf:seed` making the data (every email ends in `.invalid`);
- each busy page timed for an agent and for the owner, then `tests/load/app.js` run with many people at once.

### Found and fixed

| Page (owner, 100,000 contacts) | Before   | After  | Cause and fix                                                                                                                                                                                                                                             |
| ------------------------------ | -------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Insights                       | 2,800 ms | 350 ms | Fifteen full loads of the closed deals, a look-up per agent, and a commission-calculation look-up per deal. Now one load with only the needed columns, plus one look-up each for agents and calculations; the period totals are added up from plain rows. |
| Insights → Database            | 1,870 ms | 460 ms | About twelve counts, each reading every contact. In a big brokerage the counts are now kept for a few minutes (below).                                                                                                                                    |
| Contacts                       | 1,410 ms | 280 ms | Smart-list counts, kept the same way. A new index (`crm_contacts_recent_idx`) serves "most recently changed first" directly: about 1 ms for the list itself.                                                                                              |

**Kept counts** (`Crm\Support\ContactCounts`):

- They apply only to brokerages with 2,000 contacts or more. Smaller ones are always counted live.
- A number is kept for at most 5 minutes, per brokerage, per person and per question.
- Any contact added, changed or removed in the brokerage starts fresh numbers at once.
- Changes that bypass contacts (for example a raw import) show within the 5 minutes.

**The load script itself had a bug.** It sent no app version, so every agent page came back with Inertia's instant "reload" answer (409) and was never actually built or measured. The script now reads the version and counts only a 200 as success. `SCALE`, `MINUTES` and `LOCAL` let the same script run small on a laptop or at full size across a fleet of load generators.

### Results on a 2-CPU test box

Everything ran on one 2-CPU box: the app, MySQL, Redis and k6 itself.

| Load                              | Requests a second | p95, pages | Errors |
| --------------------------------- | ----------------- | ---------- | ------ |
| 10 agents, 20 visitors, enquiries | 7                 | 96 ms      | 0%     |
| 40 agents, 80 visitors, enquiries | 22                | 1.9 s      | 0%     |

At the second load the box was simply full, but nothing failed. With one person at a time, agent pages take 70 to 130 ms; the money page (`/my-money`) takes 480 ms.

**Still over 0.5 s, but under the 1.5 s pass mark for report pages:**

- the owner's agent-activity page (about 0.8 s);
- the commission-plans page (about 0.8 s).

Both build one row per agent for all 1,000 agents on one page, and are worth paginating later.

### Sizing for 1,000,000 agents (the staging run)

|                    |                                                                                                                                                       |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Peak               | About 100,000 agents (10%) active at once, each opening a page about every 30 s: **about 3,300 requests a second** from agents, plus website visitors |
| App servers        | At about 50 ms of CPU per agent page, **about 170 CPU cores**, run with OPcache and Octane, behind the load balancer                                  |
| Databases          | One cell holds about 20,000 agents, so **about 50 cells**                                                                                             |
| Sessions and cache | Redis                                                                                                                                                 |
| Website pages      | Mostly answered by the CDN                                                                                                                            |

**How to run it:**

1. Run `perf:seed` into several `loadtest-*` brokerages, spread over the cells.
2. Run `tests/load/app.js` from enough load generators. Each one at `SCALE=1` brings 500 agents, 1,000 visitors and 5 enquiries a second.
3. Watch HQ → Cells.
4. Pass marks: p95 under 800 ms for pages and under 1.5 s for reports, with errors under 1%.

This run needs the staging environment and k6 machines. It cannot be run from this workspace.

## Sprint 61: penetration test

The test itself is done by an outside security firm (to book). This sprint ran RightAlly's own review first, so their time goes on deeper work, and prepared the brief they need.

### Found and fixed in the internal review

- **Audit-log streams could call internal addresses.** An owner's own webhook or Splunk address was checked only for `https://`. It now passes the same checks as webhooks (`Core\Support\SafeUrl`, moved from Connect):
    - public addresses only, with no IP addresses, internal names or private networks;
    - checked again at every send, connecting to the checked IP and never following redirects;
    - standard ports only (8088 is also allowed for Splunk).

    The Datadog "site" is now one of Datadog's own sites, not free text placed into the address.

- **No browser security headers.** Every response now carries:
    - no content-type guessing (`nosniff`);
    - framing by the same site only, since RightAlly is never meant to be framed;
    - a strict referrer policy;
    - a permissions policy allowing only the microphone, location and payments, on this site;
    - HSTS on https, for one year, without sub-domains, because brokerages' own domains host other sites.

    Pages that set their own (Movewise's full content security policy, "no-referrer" downloads) keep them.

- **Session cookies** are now https-only by default in production.
- **PDF uploads** (brand guide, form templates, "verify a signed document") are now checked as PDFs when they arrive, not only by their file name.
- **Email signature:** the website link is used only when it is an http(s) address.

### Checked and found sound

| Area                            | What was checked                                                                                                                                                             |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dependencies                    | `composer audit` and `npm audit`: no known advisories.                                                                                                                       |
| Webhooks (API, Zapier, Connect) | Public https only, DNS pinned, no redirects.                                                                                                                                 |
| Listing photos                  | Private addresses are refused.                                                                                                                                               |
| Redirects                       | Website forms go back by path only. AI-app redirects must match the app's registered address. Single sign-on and the central MCP address return only to RightAlly addresses. |
| HTML on pages                   | Blog and help text strip raw HTML and unsafe links. JSON-LD is encoded. Font names come from an approved list.                                                               |
| Mass assignment                 | No request is passed whole into a model. The one open model is HQ-only Movewise settings.                                                                                    |
| Passwords                       | Breached passwords are refused. Sign-in, two-step, password reset (Sprint 58) and AI-app registration are rate-limited.                                                      |
| Brokerage isolation             | Host guards, TenantScope and the database-level TenantGuard (Sprint 58).                                                                                                     |

### Brief for the testing firm

- **Scope:**
    - a brokerage back office and HQ console on staging;
    - a brokerage website and Movewise;
    - the public API (`/api/v1`) and the MCP address;
    - webhooks in and out;
    - the client portal (`/my-deal`), signing (`/sign`) and offer links.
- **Accounts:** two brokerages (to test isolation between them). In each: an owner with two-step, a staff member, two agents and an assistant. Also an HQ staff account, and API keys for each brokerage.
- **Priorities:**
    1. one brokerage reaching another's data;
    2. an agent reaching another agent's personal contacts or money;
    3. money movements and bank details;
    4. links sent to clients;
    5. file uploads;
    6. the AI assistants (prompt injection: AS1 has 13 attack cases to start from).
- **Out of scope:** denial of service against shared infrastructure; real outside services (all set to Test); social engineering of staff.
- **Before production:** fix every high and critical finding; record the rest in the risk register (SOC 2, Sprint 62).
- **Follow-up:** a content security policy with nonces for the back office (website pages have inline scripts), measured in report-only mode first.

## Sprint 62: SOC 2 readiness, data protection and AI leak-test sign-off

### What RightAlly proves by itself (HQ → Security controls)

There are now **14 controls**, each with live evidence (counts only) and a CSV download for the auditor. Sprint 62 added four:

| Control                          | What it covers                                                                                                                                                                                                  | Status rule                                                                                                    |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| **CC6.1-2 Isolation**            | Every page checks its address; every record and every query is limited to the brokerage (TenantGuard); cross-brokerage reads are audited. Evidence includes the number of web addresses checked on each change. | Met                                                                                                            |
| **CC6.8 Vulnerable packages**    | `composer audit` and `npm audit` (high and above) now run on every pull request, and Dependabot proposes updates.                                                                                               | Process: the auditor checks CI                                                                                 |
| **CC7.1 Security configuration** | Security headers, rate limits on public forms, and public-address checks for webhooks and streams.                                                                                                              | Met                                                                                                            |
| **C1.1 AI**                      | AI is given only the brokerage's own records and treats documents as data.                                                                                                                                      | Met only after a red-team run in the last 8 days with no critical attack followed; otherwise "needs attention" |

### Data protection, checked

- **Sealed with the brokerage's own key:** bank account and routing numbers, and tax IDs (SSN/EIN).
- **Encrypted:** bank-feed tokens, outside-service keys and two-step secrets.
- **Stored only as hashes:** API keys and password-reset tokens.
- **Privacy:** requests, retention choices and export/delete were built in TR1.

### AI leak tests, signed off

- **Done here:**
    - the 19 offline evaluation sets pass;
    - the AI data-protection and retrieval tests pass, including that a record from another brokerage is refused before it reaches any AI;
    - the AS1 attack tests pass.
- **Still to do before launch:** the live red team (`ai:red-team`, 13 attacks against the real models) needs the production AI keys. It runs every Monday, and HQ → Security controls shows the result under C1.1.

### What the company must provide (the auditor checks these outside the app)

| Area (criteria)         | Needed                                                                                          | Status               |
| ----------------------- | ----------------------------------------------------------------------------------------------- | -------------------- |
| Governance (CC1, CC2)   | Information security policy, code of conduct, an owner for security, board or leadership review | To write             |
| Risk (CC3, CC9.1)       | Yearly risk assessment and risk register (penetration-test findings go here)                    | To write             |
| People (CC1.4, CC2.2)   | Background checks, security training at joining and yearly, offboarding checklist               | To set up            |
| Access (CC6.2)          | Quarterly access reviews: reminders are built in; the review record is kept                     | Process              |
| Change (CC8.1)          | Pull requests with automated checks; production changes reviewed                                | In place: CI history |
| Incidents (CC7.3–7.5)   | Incident response plan: who is called, customer notice times, a yearly drill                    | To write             |
| Continuity (A1.2, A1.3) | Backup and restore with two-person approval is built in; a yearly restore drill is recorded     | Drill to run         |
| Vendors (CC9.2)         | Outside services list (built in), plus each vendor's SOC 2 report collected yearly              | Collect reports      |
| Privacy (P1–P8)         | Privacy notice and subprocessor list on rightally.io                                            | To publish           |

### Recommended route: Type I, then Type II

1. Choose a compliance tool, which collects evidence and policy templates:
    - **Vanta** (recommended: widest integrations and auditor network);
    - **Drata**;
    - **Secureframe**.
2. Write the policies from the tool's templates.
3. Run the penetration test (Sprint 61) and record its findings.
4. **SOC 2 Type I** (the design of the controls, at one point in time).
5. A 3- to 6-month observation window.
6. **SOC 2 Type II**.

The auditor and the tool both cost money and need your choice (defaults to review).

## Sprint 63: launch readiness and version 1.0

### Found and fixed

**The app did not trust the load balancer.** Behind a balancer every request arrives from the balancer's address, which caused two problems:

- **Shared rate limits.** Every per-address limit (sign-in, password reset, website enquiries) would have been shared by all visitors. One busy minute could have blocked everyone's sign-in.
- **No https.** The app could not see that requests were https, so secure cookies, HSTS and https links would not have worked.

The app now trusts proxies on the private network by default (`TRUSTED_PROXIES` to change it, or `*`). Forwarded addresses from anywhere else are not believed.

### `php artisan launch:check`

Run it on every production server before launch and after each change to its settings. It checks, in plain words with the fix for each:

- production mode and debug off;
- the encryption key and the https address;
- secure, shared sessions; a shared cache; a real queue and real email;
- files on S3;
- a separate brokerage database, and every database answering and up to date;
- HQ two-step for everyone;
- the scheduler running (it now writes a heartbeat every minute).

It fails (exit code 1) when anything is not ready, so it can gate a deployment.

### Launch runbook

**Before (company):**

1. Sign off the defaults to review in each sprint's notes. Money and legal items need the attorney: consent wording, state deadline windows, recording announcements, Google Places' terms, placement fees.
2. Make the repository private, revoke the tokens pasted earlier, and delete the `tooling/vendor-bundle` branch.
3. Book the penetration test (Sprint 61 brief) and fix every high and critical finding.
4. Choose the SOC 2 tool and auditor (Sprint 62).
5. Publish the privacy notice, the subprocessor list and the terms on rightally.io.

**Before (platform):**

1. Set up production:
    - load balancer → app servers (OPcache, Octane), separate queue workers and one scheduler;
    - MySQL control and cell databases with backups, Redis, S3 and the CDN;
    - email with SPF, DKIM and DMARC.
2. Size it from the Sprint 60 sizing, and run the full-size load test on staging.
3. Switch outside services from Test to Live one at a time, checking each on its HQ page.
   Run `php artisan billing:starter-prices` once, so there are packages to sell (it adds only what is missing).
   Set up Watchtower (specs/core/20): set `APP_RELEASE`, name who gets problem emails in HQ › Watchtower, make the
   uptime address and point Better Stack at `/up` and `/up/scheduler`; switch Sentry on in Outside services if wanted.
4. `launch:check` passes on every server, and the nightly browser journeys pass against staging.
5. Run a restore drill (two-person approval), and record it for SOC 2.

**Go-live:**

1. Tag `v1.0.0` and deploy (database changes run first; pages keep working during deploys).
2. `launch:check` on each server.
3. Smoke test: sign in as an owner and as an agent; send an enquiry from a website; run a test payment in Stripe's test mode on staging.
4. Watch HQ → Cells, the AI cost, and the error rate for the first day.

**Rollback:** redeploy the previous tag. Database changes are additive (new columns, tables and indexes), so the previous version keeps working. Restoring a brokerage from backup needs two people.

**After:**

- weekly: red team (C1.1), access reviews every quarter, dependency updates;
- the SOC 2 observation window starts on launch day.

## SX7: plain words and the pre-test check (2026-11-18)

**Checks that run on every change** (`tests/Architecture/PlainWordsAndSizesTest.php`):

- **Plain words.** Text people read on any screen outside HQ may not use jargon such as "authentication", "TOTP",
  "two-factor", "credentials", "invalid", "configure" or "utilize". The failure names the plain word to use. The first
  run found 18 places, all on the sign-in screens and one tax message, and they were reworded ("two-step sign-in",
  "the code from your app", "does not look right").
- **Big buttons.** Simple view and its help may not use small buttons or tiny text.
- **Contrast.** Every text-on-background pair in the design tokens meets WCAG AA (4.5:1) in light and dark. The first
  run found grey text on grey panels at 4.05:1 (light) and 3.65:1 (dark). The theme builder now picks the muted text
  colour against the grey panel, for RightAlly and for every brokerage's own brand theme.

**For people:**

- the 50+ usability-test kit, `docs/usability/50-plus-test-kit.md`;
- drafts for the attorney, in `docs/legal/drafts/`: the privacy notice, the subprocessor list and the data processing
  agreement. They are not for publication before review.

**Final security re-check before the outside pen test:**

| Check                                                                                                  | Result            |
| ------------------------------------------------------------------------------------------------------ | ----------------- |
| `composer audit`                                                                                       | No advisories     |
| `npm audit --audit-level=high`                                                                         | 0 vulnerabilities |
| Route security (all 1,316 addresses: HQ guard, brokerage tie, reviewed public list, public limits)     | Pass              |
| Agent privacy sweep (every route with a contact, deal, document, item or task, tried as another agent) | Pass              |
| Brokerage guard (raw SQL, joins, jobs), log redaction, content security policy (report-only)           | Pass              |

Points to give the testers:

- the no-sign-in morning list link (SX6);
- the "lost my phone" flow (SX3);
- `/csp-report`.

Switch the content security policy to `enforce` after two clean weeks of reports.
