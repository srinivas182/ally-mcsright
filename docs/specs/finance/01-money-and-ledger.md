# Finance 01 — Money primitives and double-entry ledger (Team B, Sprints 1–2)

## Purpose

Every dollar RightAlly touches — commissions, fees, revenue share, payouts, referral fees,
deposits — is recorded once, as an immutable, balanced double-entry journal. Balances are derived
from the journal, never edited. This is the foundation for Compensation & Revenue Share
(Sprints 3–11) and Finance (Sprints 15–22), and it replaces the legacy mix of `earning`,
`wallet.balance` and ledger tables (`docs/legacy/business-rules.md`, rules 15–17).

Module: `modules/Ledger`, namespace `Modules\Ledger`. Tenant-owned (cell connection).

## Money value object

`Modules\Ledger\Money\Money` — immutable; integer **cents** and an ISO currency (`USD` only in 1.0,
but never assumed).

| Operation          | Rule                                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `add`, `subtract`  | Same currency only; mismatch throws                                                                                                        |
| `multiply(ratio)`  | Ratio as a decimal string; rounds half-even to the cent                                                                                    |
| `allocate(ratios)` | Splits an amount by ratios so the parts **always sum exactly** to the original (largest-remainder method); used for every commission split |
| `percentage(pct)`  | Percentage as decimal string with up to 4 decimals (e.g. `12.5`)                                                                           |
| Comparisons        | `isZero`, `isNegative`, `greaterThan`, …                                                                                                   |
| Formatting         | `$1,234.56`; locale-aware for Spanish                                                                                                      |

Database columns use `bigint` cents plus `char(3)` currency. Floats and text are banned (PHPStan
rule and architecture test reject `float` money properties in `Modules\Ledger`,
`Modules\Compensation`, `Modules\Finance`).

## Chart of accounts

### `ledger_accounts`

| Column              | Notes                                                |
| ------------------- | ---------------------------------------------------- |
| id, ulid, tenant_id |                                                      |
| code                | e.g. `1000`, `2100-AGENT`                            |
| name                |                                                      |
| type                | `asset`, `liability`, `equity`, `revenue`, `expense` |
| normal_side         | `debit` or `credit` (derived from type)              |
| subledger_type      | nullable: `agent`, `office`, `vendor`, `brokerage`   |
| subledger_id        | nullable: e.g. the agent's user ID                   |
| is_system           | System accounts cannot be renamed or deleted         |
| status              | `active`, `archived`                                 |

System accounts seeded per brokerage at provisioning:

| Code | Account                                          | Type      |
| ---- | ------------------------------------------------ | --------- |
| 1000 | Operating cash                                   | asset     |
| 1010 | Trust / escrow cash                              | asset     |
| 1100 | Commissions receivable (from title)              | asset     |
| 1200 | Agent fees receivable                            | asset     |
| 1300 | Payment processor clearing                       | asset     |
| 2000 | Agent commissions payable (sub-ledger per agent) | liability |
| 2100 | Revenue share payable (sub-ledger per agent)     | liability |
| 2200 | Referral fees payable                            | liability |
| 2300 | Escrow deposits held                             | liability |
| 3000 | Brokerage equity                                 | equity    |
| 4000 | Company dollar (brokerage commission revenue)    | revenue   |
| 4100 | Agent fee revenue                                | revenue   |
| 5000 | Revenue share expense                            | expense   |
| 5100 | Referral fee expense                             | expense   |
| 5200 | Processor fees                                   | expense   |

Agent sub-ledger accounts are created on first use.

## Journals

### `ledger_journals`

| Column                 | Notes                                                                                        |
| ---------------------- | -------------------------------------------------------------------------------------------- |
| id, ulid, tenant_id    |                                                                                              |
| journal_type           | e.g. `commission.calculated`, `fee.charged`, `payout.paid`, `manual.adjustment`              |
| source_type, source_id | What caused it (deal, invoice, payout)                                                       |
| posting_key            | **Unique per tenant**; idempotency (posting the same key twice returns the original journal) |
| effective_date         | Accounting date                                                                              |
| posted_at              | datetime(6)                                                                                  |
| description            |                                                                                              |
| reversal_of_id         | nullable; the journal this one reverses                                                      |
| reversed_by_id         | nullable; set by the reversing journal (only column ever written after posting)              |
| period_id              | Accounting period                                                                            |
| created_by             | user or system                                                                               |

### `ledger_lines`

`id, tenant_id, journal_id, account_id, debit_cents, credit_cents, currency, memo,
subledger_type, subledger_id, dimension_office_id, dimension_team_id`.
Exactly one of debit/credit is non-zero per line.

### Invariants (enforced in code, tested, and checked nightly)

1. Every journal balances: sum of debits = sum of credits, per currency.
2. Journals and lines are never updated or deleted (application database user has no
   `UPDATE`/`DELETE` on lines; journals allow only `reversed_by_id` to be set once).
3. Corrections are reversals: a new journal with debit/credit swapped, linked both ways.
4. A journal cannot post into a closed accounting period.
5. Same `posting_key` → same journal, no duplicate money.

## Balances

`ledger_balances (account_id, tenant_id, balance_cents, last_journal_id, lock_version)` updated in
the same transaction as posting, with row locks, so balances are always current. A nightly job
recomputes balances from lines and alerts on any difference (should never happen).

## Accounting periods

`ledger_periods (tenant_id, starts_on, ends_on, status (open, closed), closed_by, closed_at)`.
Monthly periods created automatically. Closing requires `finance.periods.close`; reopening is
not allowed — corrections post in the current open period with a reference.

## Service API

```php
LedgerService::post(JournalDraft $draft): Journal          // atomic, idempotent
LedgerService::reverse(Journal $journal, string $reason): Journal
LedgerService::balance(LedgerAccount $account, ?CarbonImmutable $asOf = null): Money
LedgerService::statement(LedgerAccount $account, $from, $to): Statement   // opening, lines, closing
```

`JournalDraft` builder: `->debit(account, Money, memo)`, `->credit(…)`, `->source(…)`,
`->postingKey(…)`, `->effectiveDate(…)`; `post()` validates balance before touching the database.

Performance: posting a 10-line journal under 20 ms p95; 200 concurrent postings to the same agent
account keep the balance exact (locking test).

## Manual adjustments

Accountants can post manual journals (`finance.ledger.adjust`), which always go through the
approval rule `finance.ledger.adjust` (spec core/03), require a reason, and are flagged in reports.

## Screens (Sprint 2)

- **Chart of accounts:** list with balances, add custom accounts, archive unused.
- **Journal list:** filters (type, date, source, account, agent), journal detail with lines and
  links to source and reversal.
- **Agent statement (basic):** opening balance, entries, closing balance for a date range,
  CSV/PDF export. The full earnings statement comes with Compensation.
- **Manual adjustment:** balanced entry form with live debit/credit totals and approval request.
- **Periods:** list, close period.

## Permissions

`finance.ledger.view`, `finance.ledger.adjust`, `finance.ledger.export`,
`finance.periods.close`, `finance.amounts.view` (sensitive; without it amounts are hidden in
shared screens).

## Events and audit

`JournalPosted`, `JournalReversed`, `PeriodClosed`, `ManualAdjustmentRequested`. Audit actions
`ledger.journal.posted`, `ledger.journal.reversed`, `ledger.period.closed`,
`ledger.adjustment.requested/approved/rejected`.

## Tests required

- Property-based tests for `Money::allocate` (random amounts and ratios always sum exactly).
- Invariant tests: unbalanced draft rejected; posting into closed period rejected; duplicate
  posting key returns the original.
- Concurrency test: parallel postings to one account produce the exact expected balance.
- Privilege test: the application database user cannot update or delete lines.
- Cross-tenant tests for accounts, journals, statements.

## Acceptance criteria

1. `Money` never loses or invents a cent in any allocation.
2. Every posted journal balances; reversals restore balances exactly.
3. Posting is idempotent by posting key.
4. Balances are always equal to the sum of lines (nightly check reports zero differences).
5. Closed periods reject postings.
6. Manual adjustments require approval and a reason.
7. Screens show correct balances and statements; exports match screens.
