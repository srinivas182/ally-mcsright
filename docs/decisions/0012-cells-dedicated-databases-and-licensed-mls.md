# 0012 — Cells as standard, dedicated databases on request, licence-gated shared MLS store

- **Status:** Accepted (owner approval, Sprint 4)
- **Supersedes:** nothing; confirms and extends 0002 (tenancy model)

## Context

Before building further, the owner asked whether each brokerage should have its own database
(as in the Dr Business Flow project) instead of cells. Target size: 10,000–20,000 brokerages.
Websites are IDX-heavy and agents publish their own blog/CMS content. Compliance is paramount:
one brokerage's data must never reach another.

## Decision

1. **Cells remain the standard** for brokerage data (contacts, deals, commissions): shared databases
   with every row stamped by brokerage, enforced in code (queries without a brokerage fail,
   cross-brokerage writes refused, audited HQ-only cross-tenant access) and proven by leak tests on
   every pull request.
2. **Dedicated databases on request**: a dedicated database is a _cell with one brokerage_ — same
   code, same releases, physically separate data, optionally in a chosen region. Sold as a premium
   option and used whenever a contract requires physical separation.
3. **Shared MLS listing store, licence-gated display**: each MLS feed is received once (RightAlly as
   the MLS's approved vendor) and stored once with its own search index; a brokerage sees an MLS's
   listings only after that MLS approves its IDX agreement, checked on every query. Websites read
   through the search index and Cloudflare, never the brokerage's own database. Where an MLS vendor
   contract requires per-customer feeds, the store is keyed per feed.
4. **Safeguards before the pilot (Sprint 26)**: per-request cell routing; per-brokerage export and
   restore; per-brokerage encryption keys for the most sensitive fields; and moves between shared and
   dedicated databases from the HQ console.

## Why not a database per brokerage for everyone

- **IDX listings are not brokerage data.** 500 brokerages in one MLS show the same listings; copying
  them into each database multiplies storage and update work hundreds of times and still needs a
  shared search index.
- At 20,000 brokerages: migrations on 20,000 databases every release (1–2 hours, partial states on
  failure), millions of tables across servers, higher backup/monitoring/connection costs, and harder
  HQ reporting, AI learning and the referral network.
- Its main benefit — physical isolation — is available to any brokerage through option 2.

## Moving a brokerage (HQ console)

Tenant page → **Database** panel (where the data lives, size) → **Move to a dedicated database** (or
back): choose "create a new dedicated database" (automated in AWS, recommended) or enter connection
details (stored encrypted, then in AWS Secrets Manager; never shown again) → test connection (empty,
right MySQL version) → plan (estimated time, a few-minute read-only window, now or scheduled) →
**approval by a second HQ person** → live progress (structure, copy, final sync, verify row counts and
money totals, switch) → old copy kept **14 days with one-click switch-back** → owner notified,
every step audited. Moving into a new, empty database keeps record IDs; moving into another shared
cell renumbers records by their permanent public IDs.

## Consequences

- Team A: three safeguard sprints (20–22), before the pilot; plan rebalanced so version 1.0 stays
  at Sprint 59.
- Team C: MLS licence approvals and enforcement right after MLS ingestion.
- Each MLS vendor contract must be checked for storage and display terms (pre-start checklist).
