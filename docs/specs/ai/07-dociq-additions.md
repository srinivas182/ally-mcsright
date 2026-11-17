# AI 07: DocIQ additions (FB1)

Status: built in FB1. Tracker row C20. This builds on [03-dociq](03-dociq.md), which already covers:

- reading text and scans;
- splitting a whole-file upload and sorting each document into the checklist;
- facts with page and quote;
- "Ask this document";
- the safe copy.

## What the market leaders do

- **SkySlope** (AI file review), **Lone Wolf** and **Dotloop** sort uploads and find key dates. A broker comparing
  a counter offer with the original still reads both side by side.
- **Explanations** for clients, where offered, are English-only summaries. They do not check that the amounts
  they state are really in the document.
- **Redaction** is a download. Sharing then means email attachments that live forever.

## What RightAlly adds

### 1. What changed from the last version

Each document from version 2 onwards has a link: **What changed from the last version?** The earlier version is
the newest earlier upload in the same checklist slot (or with the same name).

It shows:

- the key facts that changed (for example "Price: $485,000 → $475,000"), including facts no longer stated;
- the lines added and the lines taken out, up to 40 of each, with a count of the rest.

This is done in code, not AI. It is free and instant, and it never imagines a change. If either version was
scanned, a line says some words may be misread.

### 2. Explain in plain English, or _Explicar en español_

AI writes up to 10 short points from the document's pages only. Each point is checked in code:

- **Quote check.** Each point must quote words that are really on the page it names, or it is dropped.
- **Amount check.** Every amount in a point, and in the headline, must be written in the document, or it is
  dropped.

Two lines are always added in code, never by the AI:

- the **not-legal-advice line**, in the chosen language;
- when pages were scanned or handwritten, a line saying handwriting is not always read correctly, so check
  those pages against the original.

Cost: 2 credits per explanation. A repeat for the same document and language is cached.

### 3. Safe link

Pick 1, 3 or 7 days and press **Make link**. The link opens only the safe copy, with Social Security, bank
account and birth-date numbers blacked out. It is meant for a lender, a title company or a client. The link:

- is signed and stops working after the chosen days;
- is private, not cached and not indexed (no-referrer);
- is logged in the audit trail when it is made (with whom it was for) and every time it is opened.

### Handwriting

Handwriting is read by the same OCR as scans. It is flagged for a person to check, rather than trusted.

## Security

- **Who can use these.** Compare, explain and share follow the same deal access rule as the deal page. Others get
  a 403.
- **Untrusted text.** Document text goes to AI as untrusted content.
- **Safe copy only.** The shared link never serves the original.
