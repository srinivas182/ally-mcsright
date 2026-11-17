# Deal risk scores, state rule packs, and the audit pack (RD1)

Status: Done. Tracker: B21 and B26 Done.

Already live before this sprint: the risk register with its trend and CSV, the review queue with its SLA and bulk approve, the compliance scan, and the per-deal file export with its audit certificate.

## What it does, in plain words

- **A risk score on every open deal, 0 to 100.** Every point can be explained:

    | Cause                                          | Points |
    | ---------------------------------------------- | ------ |
    | Each open blocker                              | 25     |
    | Each warning                                   | 8      |
    | Each missed key date                           | 15     |
    | Each date due in the next 3 days               | 5      |
    | Closing within a week with blockers still open | 15     |
    - 50 or more is **high**; 20 or more is **medium**.
    - The deal page shows "Risk 45 of 100 (medium): 1 blocker, 1 missed date…". Fix those and it drops.

- **Review queue**: each document shows its deal's risk badge (with the reasons on hover), and a **Riskiest deals first** switch.
- **Risk dashboard**:
    - **Agents to coach**: open deals, average risk and high-risk count per agent, riskiest first.
    - **Audit pack**: pick dates and download one ZIP (password confirmation required, audited) for a state audit or an E&O insurer. It contains:
        - every deal closed in the period with its file status;
        - every dismissed finding with the reason and who dismissed it;
        - today's risk register;
        - the compliance checks and state rules in force;
        - a plain README.
- **State rule packs**: each state's expected disclosures become compliance checks the brokerage turns on (Settings → Transactions → checks; off until turned on):
    - Texas: IABS; Seller's Disclosure Notice.
    - California: agency disclosure (AD); Transfer Disclosure Statement.
    - Florida: brokerage relationship disclosure.
    - New York: agency disclosure; Housing and Anti-Discrimination Disclosure.
    - Arizona: SPDS.
    - Colorado: brokerage disclosure.
    - Mississippi: Working Relationships Disclosure.

    A document counts when a checklist item, a file name or the document type mentions it. Each finding says why the state expects it and offers "Upload".

## Market leaders

| Leader                    | What they do                   | Where they fall short                                             | What we do                                                                  |
| ------------------------- | ------------------------------ | ----------------------------------------------------------------- | --------------------------------------------------------------------------- |
| SkySlope (Audit Trail)    | Review queue and audit history | No risk ranking; audits are one deal at a time                    | A risk score on every file, riskiest first, and a brokerage-wide audit pack |
| Lone Wolf TransactionDesk | State forms libraries          | Forms aren't checked against what each state requires in the file | State rule packs as compliance checks with plain reasons                    |
| Brokermint                | Compliance checklists          | Checklists only; no view of which agents need coaching            | "Agents to coach" from real file risk                                       |

## Settings and review

- The scoring weights and thresholds, the state rule wording and keywords, and which states have packs are defaults to **review** with the brokerage's attorney. State packs ship off; brokers turn on their state.
