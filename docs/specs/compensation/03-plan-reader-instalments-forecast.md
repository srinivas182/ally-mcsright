# AI plan setup from a document, instalment fees, earnings forecast (CP1)

Status: Done. Tracker: B03 and B25 Done.

Already live before this sprint: before- and after-split deductions, franchise fees payable to the franchisor, credits, exclusions, deal classes, automatic recalculation, the cap forecast, the simulator, plan profitability and what-if.

## What it does, in plain words

- **Set a plan up from your plan document** (Compensation → Plans → "Upload the plan (AI)", 3 credits, shown first).
    - Upload the PDF your agents sign, or paste its words. RightAlly reads it and makes a **draft** plan, never a published one, covering:
        - the split, or a flat transaction fee;
        - the cap (anniversary or fixed year) and post-cap fee;
        - fees before the split (franchise royalty, with any yearly cap; payable to the franchisor);
        - fees after the split;
        - different splits for leases, commercial, new construction and referral-only deals.
    - Beside it you see **the exact sentence behind each number**, and a list of **what to check**: anything the document mentioned that couldn't be made into a rule.
    - The broker checks it in the plan editor and the simulator, then publishes. The rules pass the same validation as a hand-typed plan.
    - A scanned PDF without text gets a plain message asking for the words.
- **Fees in instalments** (Finance → Agent billing).
    - A fee can now be monthly, **quarterly** or yearly, with an optional **number of payments**. For example, a $1,200 yearly franchise fee becomes 4 quarterly payments of $300.
    - Invoices say "payment 2 of 4" and stop after the last one.
- **This year's earnings forecast** (My plan, for agents):
    - closed so far;
    - under contract;
    - the rest of the year at the agent's pace over the last 12 months (deals a month × average take-home);
    - the total, labelled as an estimate.

## Market leaders

| Leader                 | What they do                   | Where they fall short                                        | What we do                                                                  |
| ---------------------- | ------------------------------ | ------------------------------------------------------------ | --------------------------------------------------------------------------- |
| Brokermint / Lone Wolf | Plan setup by onboarding staff | Weeks of setup; brokers can't see why a number is what it is | A draft in minutes from the document, with the sentence behind every number |
| Rezen (Real) / eXp     | Clear cap trackers for agents  | Built for one plan type; little forecasting                  | Cap forecast plus a whole-year take-home forecast on any plan               |
| BoldTrail BackOffice   | Recurring agent fees           | Instalment fees are manual invoices                          | Fees with a set number of payments that stop on their own                   |

## Safety

- AI never publishes anything. The document is untrusted data to the AI; numbers must come from the text, and anything unclear is listed.
- Instalment fees use the same billing, ledger and late-fee rules as other agent fees.

## Settings and review

- 3 credits per plan read, the 60-payment maximum, and the 12-month pace window are defaults to **review**.
