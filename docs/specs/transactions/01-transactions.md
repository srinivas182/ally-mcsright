# Transactions 01 — Transactions, compliance and the money in between (Team B, with A, C, D)

Status: **Specification approved; 11B-T (core part 1) delivered** — module `modules/Transactions`: deals (six types, address, MLS number, price, GCI, dates, lifecycle with reasons), start a deal from a contact (client added as a party), ready-made checklists per deal type (state-specific templates win), uploads and phone photos into checklist items with every version kept (PDF, images, Word; 25 MB), broker review (approve, fix request from a library that learns, waive with a reason; agents cannot review), SLA review queue (default 24 hours), "still needed", closing gate (all required items approved or waived) → commission recalculated on the closing date and finalised with revenue share → file locked; assistants work on their agent's deals; everything audited. Part 2 next: earnest money and trust ledger, CDA, team review tools (owner, after leader review of SkySlope, dotloop, BoldTrail BackOffice
/ Brokermint, Lone Wolf, Paperless Pipeline, Open To Close — November 2026). Principle: **everything the
leaders have, plus twelve things they don't**. Version 1.0 stays at Sprint 66 (work spread across teams).

## Parity with the leaders

| Area                | Requirement                                                                                                                                                                                                                                                                                           | Where                         |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- |
| Deals               | Deal types: listing (seller side), buyer, dual, lease, referral, new construction; parties (clients, co-op agent and brokerage, lender, title, escrow, attorney, inspector); key dates and contingencies; lifecycle: pre-contract → under contract → closed / fell through, with cancellation reasons | Core (11B-T)                  |
| Listings            | Listing agreements, list price changes, expiration, withdrawn and expired listings                                                                                                                                                                                                                    | Team C                        |
| Documents           | Upload (drag and drop, phone photo), **per-deal email-in address**, split, merge, rename, version history                                                                                                                                                                                             | Core + DocIQ; email-in Team C |
| Compliance          | Checklists by deal type and state; required documents; broker review with comments and fix requests; audit trail; file locked after closing (changes need a reason)                                                                                                                                   | Core                          |
| Forms and e-sign    | State association forms library, native e-signature, DocuSign integration                                                                                                                                                                                                                             | Forms and e-sign sprints      |
| Collaboration       | Invite co-op agent, lender, TC; client portal; TC assignment and team mode; multiple offers                                                                                                                                                                                                           | Deal-IQ                       |
| Deadlines           | Reminders; calendar sync                                                                                                                                                                                                                                                                              | Deal-IQ timeline              |
| Money               | Commission splits, caps, fees (built); **CDA**; agent billing; next-day ACH payouts; **agent commission 1099-NEC**; earnest money and trust ledger                                                                                                                                                    | Core, Finance                 |
| Accounting          | General ledger (built); AP/AR; **operating bank reconciliation**; **P&L and balance sheet**; **QuickBooks Online and Xero sync**                                                                                                                                                                      | Finance, Team A               |
| Agents              | **Roster**: licence number, state, expiry; E&O; MLS and association memberships; expiry reminders; onboarding and offboarding checklists                                                                                                                                                              | Team A                        |
| Reports             | Production; pending pipeline and GCI forecast; closings by office and team; leaderboards; compliance status; **franchise and royalty reports**                                                                                                                                                        | Team C                        |
| Switching           | **Import** deals, documents and agents from SkySlope, dotloop, Brokermint, Lone Wolf                                                                                                                                                                                                                  | Team A                        |
| Integrations        | MLS listing import and status sync; CRM; DocuSign; open API and webhooks                                                                                                                                                                                                                              | Team C, platform              |
| Mobile              | Responsive app with photo upload and "what's missing"                                                                                                                                                                                                                                                 | Core                          |
| Out of scope (v1.0) | Canadian compliance (FINTRAC, T4); property-management ledgers                                                                                                                                                                                                                                        | —                             |

## Better than the leaders (approved)

1. **AI first-pass review** of every upload (missing signatures and initials, dates, documents still needed) before the broker sees it — brought forward from the Deal-IQ compliance scan.
2. **One record from CRM to close**: a contact moved to Under contract creates the transaction with parties, property and price; MLS data fills in when available.
3. **Three-way trust reconciliation** (bank statement, trust ledger, per-deal balances) with an audit-ready state report.
4. **Wire-fraud-safe CDA**: sent to the title company with tracking; verified title company directory; any payee bank-detail change requires call-back verification (and separation of duties).
5. **Team review queue**: SLA timers, workload balancing, fix-request library, bulk approve; agents see exactly what to fix.
6. **Mobile photo upload** with "what's missing" nudges.
7. **Complete transaction file export** (all documents, review decisions, audit certificate) kept for the state's retention period.
8. **Referrals in and out**: agreements, fees, 1099 data for fees paid to other brokerages.
9. **Plain-English deal status for clients** (Deal-IQ portal).
10. **Switch in a weekend**: import with guided field mapping and a verification report.
11. **Licence and E&O guard**: an agent with an expired licence or E&O cannot submit a new deal; the broker sees the risk.
12. **Pending-deal cash forecast**: company dollar expected in the next 30/60/90 days from deal dates and the compensation engine.

## Sprint 11B-T (Transactions core, part 1) — scope

Deals (types, parties, property, price, key dates, lifecycle), one record from CRM (create from a contact),
checklist templates by deal type and state with required documents, document uploads into checklist items
(versions, photo), broker review (approve, request fix with reasons from a library, SLA timer), "what's
missing", file locked after closing, compensation linked (calculate at contract, finalise at closing,
revenue share automatically), audit trail. Earnest money, CDA and the review-queue team tools follow in
part 2.

## Sprint 11B-T2 (Transactions core, part 2) — delivered

Earnest money (expected, received, released or refunded; due dates with overdue warnings; deposits in the
brokerage trust account post to the ledger — trust cash against escrow deposits held per deal — for the
three-way reconciliation sprint); referrals in and out (outgoing fees feed the commission calculation as the
outside referral; added before contract; yearly 1099-NEC data for fees paid, with W-9 status); the
**wire-fraud-safe CDA** (from the deal's calculation; title-facing payees only — outside referral and the
brokerage remainder, adding up to the GCI exactly — never agents' internal splits or full bank numbers;
versioned, a new version kills the old link; approved by someone other than the deal's agent with the
"Approve CDAs" permission; sent only to a title company verified by call-back, as an emailed secret link the
title officer opens without signing in and acknowledges by name; sent, opened and acknowledged tracked);
the brokerage receiving account (bank and last four only) changes only when a second person confirms by
call-back, needing the bank-details permission and a fresh password; reviewer auto-assignment to the lightest
load (never the deal's agent), mine/everyone and bulk approve; the complete transaction file export (every
document version, checklist decisions, the deal's audit trail, an audit certificate with each document's
SHA-256 and the chain check; the download is audited); the pending-deal cash forecast (company dollar in the
next 30/60/90 days, brokerage only). New permissions: Approve CDAs, Record earnest money, Export complete
files. Settings › Transactions: title companies, receiving account, referral fees paid.
