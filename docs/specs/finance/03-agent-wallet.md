# Finance 03 — Agent Wallet

Sprint AW1. Team B. Addition 7 in `docs/plan/market-2026-additions.md`; answers Real Brokerage's Real Wallet with
figures straight from the brokerage's own books.

## One screen (Overview → My money)

| Figure                 | Where it comes from                                                                                                                             |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Earned this year       | The sum of the agent's monthly statements, January to now (so it always ties to the statements)                                                 |
| Paid to you this year  | The same statements' payments                                                                                                                   |
| Ready now / on its way | The ledger: commission payable (2000) and revenue share (2100) for the agent, and pending deals                                                 |
| Progress to cap        | The agent's plan and cap period: paid to the company, cap, what's left, the pace forecast                                                       |
| Deals on their way     | Pending commission calculations not yet paid in: street, closing date, expected take-home; "paid at closing" when title pays the agent directly |
| Revenue share          | Earned this year (entries by closing date) and pending (on deals not yet paid in)                                                               |
| Get paid now           | What it would pay today, the fee, or why it can't                                                                                               |
| You owe                | Open agent invoices                                                                                                                             |
| Monthly statements     | Each month this year with earned and paid, linking to the statement (page or PDF)                                                               |

Phone-sized first (the agent app is the mobile web app). Every figure refreshes on load as deals move.

## Acceptance (AW1)

- Earned this year equals the sum of the monthly statements' earned.
- A deal paid in moves from "on its way" to "ready now"; a pending deal shows its expected take-home.
- A new agent sees zeros, not errors.
