# Audit pack and outside-auditor access (FH2)

Status: Done. Tracker: B23 Done (FH1 and FH2).

## What it does, in plain words

- **Audit pack** (Finance → Audit pack).
    - Pick the dates (last year is filled in for you) and press **Make the pack**.
    - A minute later the pack is ready: one ZIP file protected with a password (AES-256).
    - What's inside:
        - **README.txt**: what each file is, and which months are still open.
        - **statements/**: profit and loss, balance sheet and trial balance exactly as they were when each month (and year) closed, each with its fingerprint.
        - **month_end.csv**: who closed each month, when, and any note they wrote.
        - **general_ledger.csv**: every entry, line by line, with who posted it. Manual adjustments are marked.
        - **adjustments.csv**: who entered each adjustment, who approved or declined it, and whether it was self-approved.
        - **bank_reconciliations.csv** and **trust_reconciliations.csv**: who prepared and who approved each month.
        - **payout_runs.csv** and **1099_tieout.csv**: what each payee was paid, from the same payout lines the books record.
        - **activity_log.csv** and **activity_log_check.txt**: the finance part of the tamper-evident activity log, plus the result of re-checking its hash chain.
        - **manifest.json**: a SHA-256 fingerprint for every file.
    - Tax ID and bank account numbers are never included.
    - **Download** and **Show password** each ask for your password again.
    - Send the password to the auditor separately from the file.
    - Packs are kept for 7 days, then deleted.
- **Give your auditor read-only access** (owners only).
    - Enter the auditor's name, email, firm, and how many days they need (7 to 90, default 30).
    - They get an invitation. Once they accept, they can read the books and download statements and audit packs.
    - They can't post, approve, close months or pay anyone.
    - Their access ends by itself on the date shown. **End access now** stops it at once.

## Market leaders

| Leader                | What they do                              | Where they fall short                                                         | What we do                                                            |
| --------------------- | ----------------------------------------- | ----------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| Lone Wolf BackOffice  | Year-end reports; audit preparation guide | The broker gathers the reports by hand; no proof that figures weren't changed | One click, with a fingerprint for every file and statements as closed |
| QuickBooks Online     | Accountant user; audit log                | The accountant user can change the books; no single pack for an auditor       | Read-only auditor access that ends by itself, plus one pack           |
| SkySlope / Brokermint | Compliance file audits                    | Transaction files only, not the books                                         | The books, payouts, trust and 1099s in one pack                       |

## For agents and brokers aged 50+

- Two fields and one button to make a pack.
- One short form to invite the auditor.
- Clear status words: Being prepared, Ready, Has access, Access ended.

## Security

- Making and downloading packs needs "Export the books". Download and password need a recent password confirmation.
- Inviting and ending auditor access is for owners only.
- Every pack request, download, password view, invitation and end of access is audited. Auditor invitations and ends are audited as warnings.
- The auditor role is a ready-made role, "Outside auditor (read-only)". It is added to brokerages that started before it existed the first time an auditor is invited.
- Other modules add files to the pack with `AuditPack::register(AuditPackPart)`.

## Settings and review

- **Review:** packs are kept for 7 days, and auditor access lasts 7–90 days. Change these if your auditor needs longer.
