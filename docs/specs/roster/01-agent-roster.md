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
- **Part 2**: onboarding and offboarding flows; agent self-service updates with approval.
- **Part 3**: CE tracker; ARELLO verification adapter; roster export and directory.
