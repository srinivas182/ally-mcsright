# Release readiness (Sprints 58 to 63)

**Status:** Sprints 58 to 60 done: integration and regression with pen-test preparation (58), the full regression pass (59), and the load test with tuning on a large made-up brokerage (60). The full-scale run on staging is the last step of Sprint 60. Sprints 61 to 63 follow.

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
