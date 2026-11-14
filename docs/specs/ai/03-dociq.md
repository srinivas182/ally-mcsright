# AI 03 — DocIQ: the document brain (Team D, Sprints 30–31; Team C, Sprint 54)

Status: **Sprint 15A delivered (reading).** Leader review November 2026 (Lone Wolf BoldTrail BackOffice
ComplianceAI, SkySlope, ListedKit Ava, MaxHome.ai); ten additions approved across Sprints 15A–15D (see
specs/ai/02, "Delivery plan").

## Delivered in Sprint 15A

- **Every uploaded PDF or photo is read once**, in the background (`ReadDocument` job): text straight from
  the PDF, and **OCR with Tesseract on our own servers** for scanned pages and photos (no document images
  are sent to outside AI services). Page text is stored encrypted.
- **Recognised and split**: titles identify the document type (purchase agreement, counter-offer,
  amendment, HOA addendum, lead-based paint disclosure, seller's disclosure, inspection report, repair
  request, earnest money receipt, closing disclosure, settlement statement, pre-approval, buyer and listing
  agreements, wiring instructions). One upload holding several documents is **split into separate PDFs** and
  each is **filed into the matching checklist slot** (marked submitted and sent for review); unrecognised
  leading pages join the first document; what cannot be matched stays "not yet in the checklist".
- **Facts** (parties, address, price, earnest money, contract, closing and contingency dates, year built,
  title company, lender) extracted by AI from the **redacted** text through the AI gateway; each fact is kept
  only if its quote is really on the cited page and states the value. The agent ticks facts to **apply to the
  deal** (price, contract and closing dates) and its **key dates** (contingency deadlines).
- **Documents the file now needs** are added to the checklist by deterministic rules, each saying which page
  triggered it: HOA documents, condominium documents, well and septic inspection, short-sale lender approval,
  FHA/VA amendatory clause, repair invoices, and the lead-based paint disclosure for homes built before 1978.
- **Sensitive numbers** (Social Security, bank account and routing numbers, dates of birth) are counted on each
  document with a warning, and a **safe copy** blacks them out for sharing: affected pages are flattened to
  images first so nothing can be copied from under the boxes. Documents with **wiring or bank instructions**
  carry a wire-fraud warning.
- **Ask this document**: answers only from its pages; every citation's quote is checked against the page,
  and with no checked citation the answer is "I could not find that in this document."

## Purpose

DocIQ reads any document in RightAlly, answers questions only from it, finds mistakes, and turns
documents into actions. It is the **shared document engine**: Deal-IQ, Transactions, AI document
review, Compliance Knowledge and the client concierge all use DocIQ's reading instead of reading
documents again.

## Capabilities

1. **Answers only from the documents**, citing the page; says so when the answer is not there.
2. **Suggested corrections**: one-click fix on RightAlly smart forms; on signed documents DocIQ
   never edits the original and drafts an addendum instead.
3. **Document-driven actions**: key dates become reminders, parties identified, e-signature sent,
   email reminders until done.
4. **Auto-sort into the checklist**: a multi-page upload is split and each document recognised
   (contract, disclosure, lead-based paint form, HOA addendum, inspection report…) and filed into
   the right checklist slot.
5. **Compare versions**: redline between drafts, counteroffers or re-uploaded documents
   ("What changed between counter 2 and counter 3?").
6. **Scans, photos and handwriting**: reliable reading of phone photos, faxes and handwritten
   changes.
7. **Plain-English and Spanish explanations** for clients ("Explain this addendum simply"),
   shared through the client concierge with a clear "not legal advice" note.
8. **Safe sharing**: Social Security numbers, bank details and dates of birth are blanked out
   automatically before a document is shared with another party.

## How DocIQ and Deal-IQ fit together

DocIQ is the eyes; Deal-IQ is the coordinator. A document is read once by DocIQ; Deal-IQ uses the
result to create and update the deal, timeline, checklist, reminders, compliance scan, repair
requests and concierge answers.

| Customer has      | What works                                                                                                                                                              |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DocIQ and Deal-IQ | Everything: documents drive the whole deal automatically                                                                                                                |
| DocIQ only        | Ask documents, corrections, auto-sort, compare, explain, safe sharing, date reminders; no deal timeline automation, concierge, drafting or party portal                 |
| Deal-IQ only      | Deal-IQ includes the document reading it needs (the DocIQ engine runs underneath); the "ask any document" panel outside deals, explanations and safe sharing need DocIQ |

## Guardrails

Answers only from the documents (no outside knowledge presented as fact); never edits signed
documents; not legal advice; redaction before sharing; same data protection as all AI
(specs/ai/01).

## Packaging

Part of the **RightAlly AI Transaction Suite** with Deal-IQ; DocIQ alone is the entry option.
