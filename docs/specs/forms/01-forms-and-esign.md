# Forms 01 — Native forms and e-signature (Team B, 5 sprints)

Status: **Specification approved** (owner approved all seven additions after the leader review of dotloop,
DocuSign Transaction Rooms with Lone Wolf zipForm, SkySlope Forms with DigiSign, and Lone Wolf Transactions with
Authentisign). Version 1.0 stays at Sprint 63.

## Parity with the leaders

State and association forms library (licensed; libraries plug in as licences arrive — until then brokerages use
their own forms and any uploaded PDF), auto-fill from the deal (parties, property, price, dates, agents,
brokerage), clause library, brokerage templates, multi-signer e-signature with signing order, initials and date
fields, mobile and in-person signing, reminders, ESIGN/UETA consent to electronic records, audit certificate,
counter-offers and addenda, re-signing after changes, and completed documents filed to the deal's checklist.

## Better than the leaders (approved)

1. **Pre-send check**: missing signatures, initials, dates or blank required fields are flagged before sending.
2. **Re-sign only what changed**: signers see exactly what changed and re-sign only those parts.
3. **Tamper-evident sealing**: completed documents are sealed with a SHA-256 fingerprint and a digital signature
   anyone can verify.
4. **Wire-fraud protection**: every signing email carries the warning; forms never carry wiring instructions
   (bank, routing or account details are blocked from form fields).
5. **Signer verification**: an SMS code for everyday documents; ID verification for high-risk ones.
6. **Smart counter-offers**: generated from the original with a summary of changed terms, linked in one history.
7. **Plain-English summaries** of each form for clients, marked as not legal advice.

## Technical notes

PDFs are normalised with `qpdf` (object streams disabled, PDF 1.4-compatible output) so FPDI can import any
uploaded form; FPDI + FPDF stamp values, signatures and the audit certificate. Field positions are stored in PDF
points from the page's top-left. Bindings read the deal, its parties, its agent and the brokerage.

## Delivery

- **Part 1 (Sprint 14B)**: templates (upload, normalise, fields with type, page, position, signer role, binding,
  required), auto-fill from the deal, clause library, packets on a deal (forms, values, signer roles), filled
  PDF preview, pre-send check, wiring-instructions guard.
- **Part 2**: signing — signing order, emailed secure links, ESIGN/UETA consent, signature and initials capture,
  SMS code verification, reminders, in-person signing, audit certificate, tamper-evident sealing, filing to the
  checklist, wire-fraud warning in every email.
- **Part 3**: visual field placement on the page (drag and drop), templates from the brokerage's forms.
- **Part 4**: counter-offers and addenda (smart counters), re-sign only what changed.
- **Part 5**: ID verification for high-risk documents, plain-English summaries, association library connector.
