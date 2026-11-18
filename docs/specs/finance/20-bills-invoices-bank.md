# Bills, invoices and operating bank reconciliation (AC1)

Status: Done. Tracker: B13 and B15 Partly (P&L, balance sheet and 1099-NEC e-file come in AC2).

## What it does, in plain words

All three are under the Finance menu.

- **Bills: what the brokerage owes** (rent, MLS dues, software, marketing).
    - Enter who it's from, the amount, what it's for, and the bill and due dates, and attach the PDF or a photo. The books record the expense and what is owed.
    - **Mark paid** (date and check number) records the money leaving the operating account.
    - **Void** reverses the entries; nothing is ever deleted.
    - Totals show what is unpaid, due in the next 7 days, and overdue.
- **Invoices: money owed to the brokerage by others** (a builder's co-op, a relocation company's referral fee, a sponsor, a sublease).
    - Numbered 1, 2, 3 for each brokerage, with several lines.
    - The **PDF** is ready to email or print.
    - **Mark paid** moves the money into operating cash.
    - Agents' own fees stay in Agent billing.
- **Bank reconciliation: the operating account, month by month.**
    - Download the statement from the bank as a **CSV**, or **OFX or QFX** (the "Quicken" download every US bank offers), and enter the ending balance.
    - Each line is matched to the books automatically (same amount, within 5 days).
    - Lines the bank shows but the books don't (a bank fee, a card charge, interest) are **added to the books** with one click and a category. A line can also be matched by hand.
    - It balances when the bank's ending balance, plus deposits not on the statement yet, minus payments not cleared yet, equals operating cash in the books.
    - **A second person approves it**, and the month is then locked.
- **New standard accounts** are added to every brokerage's chart:
    - 1150 Accounts receivable and 2400 Accounts payable;
    - 4200 Other income;
    - everyday expenses 6000–6900: rent and office, marketing, software, MLS dues, E&O insurance, staff payroll, professional fees, bank fees, and other.

## Market leaders

| Leader                            | What they do          | Where they fall short                                       | What we do                                                                                                       |
| --------------------------------- | --------------------- | ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| QuickBooks / Xero                 | Full accounting       | A second system; commission and trust data must be re-keyed | Bills, invoices and the bank inside the same books as commissions and trust, still syncing to QuickBooks or Xero |
| Brokermint / Lone Wolf BackOffice | Commission accounting | Operating bills and the bank are left to QuickBooks         | Operating side included                                                                                          |
| Paperless Pipeline / SkySlope     | Transaction files     | No accounting                                               | n/a                                                                                                              |

## Controls

- Everything posts as balanced double-entry journals with a posting key (no double posting). Voids reverse; nothing is deleted.
- Two people are needed for bank approval, and approved months are locked.
- Bill files are private. Pages need "See the books"; changes need "Post adjustments to the books". Every action is audited.

## Not in this sprint (honest)

- A live bank feed (Plaid or MX) needs an HQ data-provider key; file import works with every bank today.
- Paying bills by ACH from RightAlly needs the payout rails, which are planned with payouts.

## Settings and review

- The 5-day matching window and the default expense categories are defaults to **review** with the brokerage's accountant.

## Live bank feed (BK1, B15)

Finance → Bank reconciliation → **Live bank feed**. HQ chooses the provider in Outside services → Live bank feed:

- **Plaid (recommended):** Test mode uses the Plaid Sandbox, Live uses Production.
- **MX:** Test mode uses MX's integration environment.
- **Teller:** every call is signed with HQ's certificate.

**Read-only by design.** Only accounts, balances and transactions are requested. RightAlly asks for no payment, transfer or account-number product, and nothing can move money through the feed.

1. **Connect.** The owner (permission `finance.ledger.adjust`, after confirming their password) signs in to the bank in the provider's own window: Plaid Link, the MX Connect widget or Teller Connect.
    - RightAlly keeps only the provider's access token, encrypted.
    - The brokerage is known to the provider by a non-identifying reference.
2. **Choose the operating account.** Only checking and savings accounts are listed.
3. **Transactions come in** every morning at 05:40 (`ledger:bank-feeds`), and whenever someone presses **Refresh now**.
    - Plaid uses its sync cursor, so removed transactions are removed here too.
    - MX re-reads the last week to catch transactions that post late.
    - Amounts are stored as + money in and − money out. Pending transactions are kept but never used in a statement.
4. **Fill a month from the feed** (once the month has ended). This replaces the downloaded statement:
    - the month's posted transactions become the statement lines;
    - the ending balance is today's balance less everything posted since the month ended, or the balance typed from the paper statement if one is given;
    - matching, adding to the books and second-person approval work exactly as before.
5. **Disconnect** (after password confirmation) removes the connection and its transactions.

If the bank doesn't answer, the feed shows a plain message and tries again the next morning.

Tests: `tests/Feature/Ledger/BankFeedTest.php`.
