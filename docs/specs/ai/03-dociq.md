# AI 03 — DocIQ: the document brain (Team D, Sprints 30–31; Team C, Sprint 54)

Status: **Module definition approved.** The detailed sprint specification is written before
Sprint 30, after its best-in-class review (definition of ready).

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
