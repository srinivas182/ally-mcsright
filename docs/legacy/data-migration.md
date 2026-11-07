# Migrating existing RightAlly customers

Existing paying brokerages move to the new platform from around Sprint 26, suite by suite, with
nothing lost. Tooling is built in Sprints 20–22 (Team A) and the commission parallel run in
Sprints 23–25 (Team B).

## What moves

| Data                                                                                | Notes                                                                                    |
| ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Brokerage settings, offices, branding                                               | Mapped to Platform Core settings and offices                                             |
| Staff and agents                                                                    | Profiles, roles, licence data; SSNs and bank details re-encrypted with the brokerage key |
| Sponsor tree and co-sponsors                                                        | Rebuilt as the genealogy; validated node by node                                         |
| Compensation plans, versions, deductions catalogue                                  | Recreated as versioned plans with effective dates                                        |
| Deals (orders) with deductions and approval history                                 | Money converted from text/float to decimal; currency explicit                            |
| Commission calculations, revenue share, cap periods, entitlements, journals, wallet | Ledger is the source of truth after reconciliation                                       |
| Earnings and payout history, tax reports                                            | Read-only history, linked to agents                                                      |
| Agreements and acceptances, uploaded documents                                      | Files copied to tenant storage with checksums                                            |
| Subscriptions, invoices, saved cards                                                | Stripe/Square customer and subscription IDs only; card data never leaves the processor   |
| Tasks, notes, open tickets                                                          | Carried over; old chat and notifications only recent history                             |

Legacy MLM tables (binary, e-pin, PV, ranks, auto-pool) are not migrated.

## How

1. **Extract** each brokerage's legacy database read-only.
2. **Transform** with documented rules per table (see `schema-mapping.md`): fix encodings to utf8mb4,
   convert money to decimal, map string member IDs to new IDs with a lookup table, drop orphans
   into an exceptions report.
3. **Load** into the brokerage's new tenant in a staging cell.
4. **Reconcile**: row counts per entity, money totals per agent and per period to the cent,
   genealogy node counts and depth, cap balances, wallet balances.
5. **Parallel run**: for a set period, every new closing is calculated in both systems; any
   difference blocks go-live until explained.
6. **Cut over**: brokerage switches; legacy becomes read-only for that brokerage; rollback plan kept
   for 30 days.

## Passwords

Legacy hashes are not carried over (some accounts used unsalted SHA-1). Every user receives a
secure set-password link on first login to the new platform.

## Acceptance

A brokerage moves only when reconciliation shows zero unexplained differences and the broker signs
off the parallel-run report.
