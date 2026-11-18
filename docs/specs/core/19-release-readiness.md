# Release readiness (Sprints 58 to 63)

**Status:** Sprint 58 (integration and regression, with pen-test preparation) done. Sprints 59 to 63 follow.

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
