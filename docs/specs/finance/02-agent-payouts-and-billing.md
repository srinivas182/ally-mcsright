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

- **Part 1 (delivered, Sprint 12B)**: new module `modules/Payouts`; money received from title (wire, ACH or check)
  checked against the CDA's brokerage line (or GCI minus the outside referral) — **matched** recognises the
  commission once (receivable against each agent's payable, the team lead's share and company dollar) and
  brings the cash in; **short or over** is recorded but held until a broker accepts the difference with a
  reason (company dollar absorbs it); partial payments add up; agent balances (commission payable from settled
  deals, revenue share wallet, money on its way); agents' bank details (ABA checksum, encrypted, last four
  shown, emailed on every change, usable after **72 hours**, history kept; set by the agent with a fresh
  password, or by someone with the bank-details permission); Finance › Money received and agents' My money.
- **Part 2 (delivered, Sprint 12B-2)**: payout runs built from what is ready (commission payable from settled
  deals, revenue share wallets, referral fees the brokerage pays), one open run at a time; agents in the 72-hour
  bank-change hold or not active are skipped with the reason; direct deposit when a usable bank account exists,
  otherwise check; preview line by line; approval by an owner or "Approve payouts" holder who is **not paid in
  the run and has not changed a payee's bank details in the last 30 days** (a one-owner brokerage can still
  approve); sending (fresh password) numbers checks, posts one balanced journal (payables against operating cash),
  marks referrals paid and emails each agent **"You've been paid"** with the breakdown and check number; a
  standard **NACHA** PPD credit file (94-character records, entry hash, padded blocks; same-day by effective
  date), printable **checks** (amount in words, stub) and a **positive pay** CSV, stored encrypted and downloaded
  with an audit record; payout settings (ACH company name and ID, bank routing, operating account, next check
  number; bank-details permission and fresh password); referral fees can be **paid by title or by the brokerage**
  — brokerage-paid fees stay in the brokerage's CDA amount and are booked as referral payable when the money
  arrives.
- **Part 3 (12B-3 delivered)**: `PayoutRail` interface with **Dwolla** (brokerage = verified business customer under
  RightAlly's Dwolla platform, its own bank as funding source after micro-deposit verification; agents receive-only;
  transfers with same-day clearing), **Modern Treasury** (the brokerage's own organisation and internal account,
  credentials encrypted in its settings; counterparties; ACH payment orders, high priority for same-day) and
  **Stripe Treasury** (outbound payments from the brokerage's own financial account on its connected account; the
  money never passes through RightAlly's balance); HQ › Payout rails (enable per rail, platform keys encrypted,
  test/live, webhook addresses); the brokerage chooses its rail and links its own bank in Finance › Payouts
  (business details pass straight to the partner and are not stored); sending commits the books first, then
  submits each payment outside the transaction with an idempotency key (a retry never pays twice; "Retry"
  resubmits ones a partner did not accept); signed webhooks (Dwolla and Stripe platform-wide, Modern Treasury per
  brokerage) mark payments completed, or failed/returned — the money goes back to the payee's balance with one
  reversing journal and the agent is emailed. **12B-3b delivered — get paid now**: off until the brokerage turns it on; the brokerage sets a flat and/or percentage fee
  (or none) and a minimum, within HQ's limits (HQ › Payout rails); the agent sees what's ready, the fee and what they
  receive before confirming (fresh password; a changed amount is refused); the money is committed at once (payables
  down, net to cash, the fee booked as agent fee revenue — the brokerage's income); automatic rails pay same-day
  immediately; bank-file brokerages get an approved instant run and an email, and send it in their next same-day
  file (never posted twice, never blocking regular runs); one request at a time; the 72-hour bank-change hold
  applies; a bounce returns the full amount and refunds the fee.
- **Part 4 (delivered, 12B-4)**: Finance › Agent billing ("Bill agents" permission): fee items (monthly,
  yearly or once) assigned to agents and invoiced on schedule by the daily job; one-off charges; invoices book the
  agent's receivable against agent fee revenue; unpaid invoices are **deducted from commission** in payout runs
  and get-paid-now (oldest first; on by default; nothing sent when invoices use it all; invoices marked paid when
  the run is sent; a bounced payment returns only what was sent) **or paid online** by card or bank through
  Stripe Checkout on the brokerage's own connected account (paid once, from the webhook); reminders three days
  before and after the due date; a late fee once after the grace period (never on payment-plan instalments);
  agents who owe the brokerage are recovered from future commission automatically, or the debt moves into a
  **payment plan** of 1–24 monthly instalment invoices (not counted as new revenue); agents see What I owe on My
  money.
- **Part 5 (delivered, 12B-5)**: monthly agent **statements** (page and PDF): opening and closing balances from the
  ledger and every line between — commission per deal (or "paid directly by title"), revenue share, invoices,
  deductions, get-paid-now fees and payments; **W-9** collected online (typed signature must match the legal name;
  TIN encrypted, last four shown; replacing keeps one current; finance enters referral payees' W-9s); **IRS bulk
  TIN matching** — the IRS has no API: RightAlly writes the e-Services request file (`TIN type;TIN;Name;account`)
  and imports the result codes (0/6/7/8 matched, 2/3 mismatch, others invalid); **1099-NEC totals** per calendar
  year for agents (commission + revenue share paid, before invoices and fees taken) and referral payees, plus
  commission title paid directly; thresholds **$600 for 2025 payments, $2,000 for 2026** (One Big Beautiful Bill
  Act), indexed from 2027 via `billing.nec_threshold_cents`; export for filing with full TINs (finance, fresh
  password, audited); **title pays agents directly** (Settings › Transactions, where the state allows): agents'
  net becomes CDA lines, the brokerage line shrinks, no payable is booked and the amounts are recorded for 1099s.
  **Agent payouts and billing is complete.**

## Automatic payouts on closing (Sprint 13B-4) — delivered

Off by default; an owner sets the rule (fresh password): pay automatically up to a limit per payee, same-day or
not. When a deal's money is matched to the CDA (or a broker accepts a difference), a run is prepared for that
deal's agents and brokerage-paid referral payees (what is ready for them, invoices deducted). Within the limit it
is approved under the owner's standing rule (audited with the rule; no person recorded as approver) and, on an
automatic rail, sent at once with "You've been paid" emails; on the bank file, owners are emailed to send it; over
the limit it waits for approval and owners are emailed. Anyone already in an unsent run is left out of new runs,
so nothing is paid twice. The 72-hour bank-change hold and every other safeguard apply.

## Deal-IQ payout holds (Sprint 15C)

Payouts for a deal are held while Deal-IQ has an open money or wire-fraud blocker on it (specs/ai/02): automatic
payouts wait and resume by themselves when it clears; manual runs paying an affected agent need a written
override, recorded in the audit log.
