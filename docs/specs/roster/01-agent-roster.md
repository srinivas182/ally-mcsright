# Roster 01 — Agent roster, licence and compliance tracking (Team B, 3 sprints)

Status: **Specification approved** (owner approved all seven additions after the leader review of Lone Wolf
Back Office, BoldTrail BackOffice, Brokermint and Paperless Pipeline). Merges the former Team A "Agent roster"
item; version 1.0 stays at Sprint 63.

## Parity with the leaders

Agent profiles (licences with number, state and expiry; association/NRDS and MLS memberships; E&O policy and
expiry; start date, plan, team and sponsor from the platform); agent documents (contractor agreement, W-9,
licence copy, E&O certificate); expiry reminders; onboarding checklists; roster export and directory.

## Better than the leaders (approved)

1. **Licence guard**: a deal cannot go under contract unless the agent holds an active licence in the deal's
   state and (when required) active E&O; an owner can override for one deal with a reason (audited).
2. **Automatic licence verification** through ARELLO's licence verification service (paid data agreement, on
   the pre-start checklist), behind an interface; **manual verification** (who checked, how, optional
   screenshot) until then.
3. **Onboarding flow**: contractor agreement and policy manual e-signed, tasks created automatically (MLS,
   association transfer, website profile, business cards), progress visible to broker and agent.
4. **Offboarding flow**: licence release reminder, MLS and system access removed, final statement, revenue
   share vesting recorded.
5. **Continuing education tracker**: hours per renewal cycle by state, reminders before the deadline.
6. **Compliance dashboard**: everything expiring in 30/60/90 days, expired, missing documents, unverified
   licences.
7. **Agent self-service updates** (new licence, E&O renewal, address) that take effect after broker approval.

## Delivery

- **Part 1 (Sprint 13B)**: roster profiles, credentials (licences, E&O, associations, MLS), documents, expiry
  reminders (60, 30 and 7 days, and on expiry — to the agent and owners), manual verification, licence guard
  with per-deal override, compliance dashboard, agents' read-only view of their own records.
- **Part 2 (delivered, Sprint 13B-2)**: owners publish the contractor agreement and policy manual (versioned);
  agents e-sign by ticking and typing their exact name — the SHA-256 of the signed text, time, IP and device are
  recorded and a signed PDF is saved to their documents; a new version asks them to sign again. **Onboarding**
  checklist: agent tasks that tick themselves (agreement, policy, W-9, bank account, active licence, active E&O)
  and brokerage tasks staff mark (MLS, association, website profile, cards); progress for both; completes at 100%.
  **Offboarding**: leaving date and reason; brokerage tasks (licence release, MLS and lockbox removed, listings
  and deals reassigned, final statement) and "balances settled" (ticks itself when commission, revenue share and
  invoices are all zero); an owner completes it with a fresh password, which deactivates the account (recording
  the revenue share leaving date). **Self-service**: agents send a renewed licence, E&O or membership with the
  document; it takes effect only when approved; rejections tell the agent why.
- **Part 3 (delivered, Sprint 13B-3)**: **continuing education** — CE requirements differ by state and change, so each
  brokerage sets its states' rules (hours per cycle, cycle months, required topics) from the official rules; agents
  (or staff) log courses with certificates; progress counts courses in the cycle ending at the licence renewal
  date, by topic; reminders at 90, 60 and 30 days while incomplete (owners copied). **Licence verification** —
  a `LicenceVerifier` interface (ARELLO adapter bound once the data agreement and its API specification arrive;
  off until then, manual verification continues): check on demand and a monthly re-check of every licence;
  active updates the expiry and marks it verified; suspended, expired, not found or a different name is flagged,
  owners are emailed, and the licence guard stops that agent's deals going under contract. **Roster export**
  (CSV, staff, audited) and a searchable **directory** of everyone (contact, offices, teams, licences).
  **The agent roster is complete.**
