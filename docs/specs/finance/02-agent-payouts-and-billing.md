# Finance 02 — Agent payouts and billing (Team B, 5 sprints)

Status: **Specification approved** (owner, after leader review of BoldTrail BackOffice/Brokermint, Lone Wolf,
eXp and Real; "everything the leaders have, plus six things they don't"). Version 1.0 stays at Sprint 66
(two growth items moved to Team E).

## Principle: RightAlly never holds the brokerage's money

Money moves from the brokerage's own bank account to agents and other payees. RightAlly prepares, checks,
approves and instructs; it never takes custody (holding client funds would make RightAlly a money
transmitter). Rails, behind a `PayoutRail` interface:

- **Automatic ACH (version 1.0)** through a payments partner (Modern Treasury or Dwolla, chosen after partner
  terms): the brokerage links its operating account; approved runs go out **next-day or same-day**
  automatically, matching BoldTrail's next-day ACH. Partner agreement added to the pre-start checklist.
- **Bank file fallback**: a standard NACHA ACH file per approved run, uploaded to the brokerage's own online
  banking (works with any bank on day one).
- **Checks**: check register, printable checks or vouchers on standard stock, and a **positive pay** file.
- Stripe Connect is **not** used for agent payouts (the money would pass through RightAlly's balance).

## Parity with the leaders

| Area                        | Requirement                                                                                                                                                                             |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Money received              | Commission received from title recorded against the deal; operating cash and commission receivable in the ledger                                                                        |
| Balances                    | Each agent's balance: commission net, revenue share wallet, fees owed, holdbacks                                                                                                        |
| Payouts                     | Payout runs; next-day and same-day ACH (automatic); bank-file fallback; checks with positive pay; outside brokerages' referral fees by ACH or check                                     |
| CDA option                  | Setting: title pays agents directly where the state allows (agent net as a CDA payee)                                                                                                   |
| Billing                     | Recurring agent fees (monthly tech or desk fee), one-off charges, invoices; deducted from the next commission automatically **or** paid online by card or bank; late fees and reminders |
| Negative balances           | Agent owes the brokerage (e.g. after a chargeback): recovered from future commission or by a payment plan                                                                               |
| Statements                  | Monthly agent statements and year-to-date history                                                                                                                                       |
| Tax                         | W-9 collected online with IRS TIN matching; 1099-NEC totals for agents and referral payees (e-filing in the later finance sprint)                                                       |
| Related (planned elsewhere) | Holdbacks and instalments (Compensation 9B-3); QuickBooks and Xero sync (Team A)                                                                                                        |
| Out of scope (v1.0)         | Commission advances (third-party lenders; partner integration later)                                                                                                                    |

## Better than the leaders (approved)

1. **Match the money to the CDA**: payments from title are checked against the CDA; short or wrong payments
   are flagged before anyone is paid.
2. **Agent bank-detail protection**: encrypted, last four digits shown, the agent's existing email and phone
   are told of any change, and **payouts are held for 72 hours** after a change.
3. **Payout preview and approval**: every payment shown before it goes; approved by someone who cannot change
   bank details (separation of duties).
4. **Every cent explained** on agent statements (commission, fees, revenue share, payouts).
5. **"You've been paid"** notification with the amount and the statement line.
6. **Transparent "get paid now"**: withdraw earned money any time; the fee is shown before confirming.

## Delivery

- **Part 1**: money received matched to the CDA; agent balances (ledger sub-accounts); agents' protected bank
  details with the 72-hour hold.
- **Part 2**: payout runs with preview, approval and separation of duties; bank file (NACHA) and checks with
  positive pay; outside referral payouts; paid notifications.
- **Part 3**: automatic ACH through the partner rail (next-day and same-day); get paid now.
- **Part 4**: agent billing — recurring fees, invoices, online payment, deductions, late fees, negative
  balances and payment plans.
- **Part 5**: statements with every cent explained; W-9 with TIN matching; 1099 totals; title-pays-agents CDA
  option.
