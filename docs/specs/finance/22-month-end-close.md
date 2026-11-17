# Month-end close and two-person adjustments (FH1)

Status: Done. Tracker: B23 Partly (the audit pack and auditor login follow in FH2).

## What it does, in plain words

- **Month-end close** (Finance → Month-end close).
    - Each finished month shows a checklist titled "Before you close September 2026". Each item that passes is shown with a tick.
    - Problems come in two kinds:
        - **Must fix before closing.** These block the close:
            - an entry that doesn't balance;
            - an account total out of step with its entries;
            - an adjustment still waiting for approval;
            - a payout run that isn't in the books, or doesn't match them.
        - **Worth a look.** The month can still close, but only with a written note saying why. The note is kept with the month. These are:
            - the operating bank account isn't reconciled for the month (once the brokerage has started reconciling it);
            - the trust account isn't reconciled and approved (when the brokerage holds money in trust);
            - an agent's booked commission no longer matches what their plan works out for that deal.
    - Each problem says what to do and has a **Go there** link.
    - **Months close in order.** You can't close October while September is open.
    - **Closing December closes the year.**
    - A closed month can never be changed. A mistake is fixed with a new entry in an open month, so every change stays visible.
- **Statements as closed.**
    - At every close we keep a copy of three things exactly as they were: the month's profit and loss, the balance sheet and the trial balance. When December closes, we also keep a full-year copy.
    - Each copy has a SHA-256 fingerprint. The PDF prints the fingerprint and says whether the copy is still unchanged since the close.
- **Nightly check.**
    - Every night at 04:15 the checklist runs for every finished month that is still open.
    - Owners get one email about each new problem that must be fixed, not a new email every night.
    - **Check again** reruns the checklist straight away.
- **Two-person adjustments** (Finance → Adjustments).
    - A manual adjustment is saved, then **waits for a second person**. The books don't change until it's approved.
    - People who can approve get an email.
    - The approver sees every line and chooses **Approve** or **Decline**. Declining needs a reason.
    - The person who entered it can **Withdraw** it.
    - If a brokerage has only one person who can approve, that person may approve their own adjustment, but only with a written reason. It is marked "the only approver, approving their own entry", and the audit log records it as a warning.

## Market leaders

| Leader                            | What they do                                                         | Where they fall short                                                                                                        | What we do                                                                              |
| --------------------------------- | -------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Lone Wolf BackOffice (brokerWOLF) | Month-end and year-end close guides; reports for reviewing the books | The checklist is a guide for the broker to follow by hand; no built-in check that payouts, splits and 1099s tie to the books | The checks run themselves every night, in plain words, each with a link to fix it       |
| Brokermint / BoldTrail BackOffice | Commission reports, period reports, accounting export                | Closing and the checks happen in the outside accounting app                                                                  | Close, checks and locked statements in the same books that pay agents                   |
| QuickBooks Online                 | Closing date with a password; audit log                              | Anyone with the password can post into a "closed" period; adjustments need no second person                                  | Closed months can't be changed by anyone; every manual adjustment needs a second person |

## Add-ons beyond the leaders

- A fingerprinted copy of the statements at every close, so an auditor can see nothing changed afterwards.
- A check that each agent's booked commission still matches their plan.
- A check that each payout run matches the books, which also ties the 1099s to the books.
- Notes for closing despite open items, kept with the month for the audit.

## For agents and brokers aged 50+

- One checklist on one page.
- Big buttons: Check again, Close September 2026.
- Plain headings: "Must fix before closing" and "Worth a look".
- No accounting jargon in the problems.

## Security

- Closing needs "Close accounting periods". Approving needs "Approve adjustments to the books entered by someone else" (new). Owners and the Accountant role template have both.
- Approving, declining, withdrawing and closing are all audited. Self-approval and closing with open items are audited as warnings.
- Statement PDFs need "Export the books" and are audited.
- Adjustment lists, findings and copies are all kept per brokerage.

## How other modules add checks

- `Modules\Ledger\Close\CloseChecks::register(class)` takes any `CloseCheck`. Payouts registers its trust, splits and payout-run checks this way.
- Each check returns `Finding::stop(...)` or `Finding::check(...)`.

## Settings and review

- **Review:** the "Worth a look" items let a month close with a note. Your accountant may want the trust reconciliation to block the close instead.
- **Review:** a brokerage with one approver may approve its own adjustments with a reason. Some auditors may ask for an outside approver instead.
