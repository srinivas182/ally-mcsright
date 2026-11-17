# Profit and loss, balance sheet, and 1099-NEC e-filing (AC2)

Status: Done. Tracker: B13 Done; B15 Partly (a live bank feed is left).

## What it does, in plain words

- **Profit and loss** (Finance → Profit and loss), straight from the books.
    - Pick a period: this year so far, last month, last quarter, last year, or your own dates.
    - Income and expenses are shown by account, with the **same period last year beside every line**.
    - Three big numbers (income, expenses, net profit) each say "up" or "down" against last year.
    - Per-agent and per-deal sub-accounts are rolled into their parent, so the report reads like a brokerage's books, not a list of agents.
- **Balance sheet** as of the period's end date: assets, liabilities and equity.
    - Profit not yet closed into equity is shown as its own line, so it always balances.
    - If the books ever didn't balance, it says so in red.
- **PDF and CSV** for the accountant or lender. CSV cells are protected against spreadsheet formulas, and each export is audited.
- **1099-NEC e-filing** (Finance → Tax (1099)).
    1. The brokerage enters its **payer details** once: legal name, EIN (encrypted, shown again only as its last 4 digits) and address.
    2. RightAlly checks the year is ready, and lists in plain words whatever isn't: no W-9, a name/TIN mismatch from IRS TIN matching, an incomplete address, missing payer details, or e-filing not switched on.
    3. **File 2026 1099-NECs** sends every reportable payee through the IRS-authorised partner (TaxBandits, using the IRS IRIS system), optionally **mailing each person their copy**.
    4. The filing shows "With the IRS". **Check the IRS answer** updates it to Accepted, or to Rejected with what to do.
    5. A year can't be filed twice; corrections go through the partner.
    - The IRS-ready CSV download still works without e-filing.

## Market leaders

| Leader                            | What they do                              | Where they fall short                                                                          | What we do                                                                            |
| --------------------------------- | ----------------------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| QuickBooks                        | P&L and balance sheet; 1099 e-file add-on | Commission data must be re-keyed; 1099 totals come from vendor payments, not commission splits | Statements and 1099s from the same books that pay agents, revenue share and referrals |
| Brokermint / Lone Wolf BackOffice | Commission reports, 1099 export           | Export only; filing done elsewhere                                                             | Readiness check, e-file and IRS status in one place                                   |
| Track1099 / TaxBandits direct     | E-file                                    | Upload spreadsheets each year; no check that W-9s match                                        | W-9s, TIN matching and totals feed the filing automatically                           |

## Security

- TINs and the EIN are decrypted only while filing and are never logged.
- Filing needs a recent password confirmation and the "finance billing" permission. Every filing is audited as critical.
- The partner keys are HQ's (Apps → 1099 e-filing), encrypted and write-only. Sandbox is used until HQ switches to live.

## Settings and review

- Partner: TaxBandits, configured in HQ Apps. **Review:** test in sandbox, then go live before January. The per-return price is set by HQ.
- The year-end threshold comes from Tax (1099): $600 for 2025 and $2,000 for 2026, updated by HQ each year.

Sources: TaxBandits developer documentation (Form1099NEC Create), https://developer.taxbandits.com/docs/Form1099NEC/Create
