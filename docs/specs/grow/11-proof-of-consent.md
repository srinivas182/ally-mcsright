# Grow 11 — Proof of consent that travels with every contact

Sprint PC1. Teams D with A. Addition 5 in `docs/plan/market-2026-additions.md`. TCPA claims turn on proof: who
agreed, to what words, where and when. RightAlly already kept append-only consent history (Sprint 6); this
sprint adds where each consent came from, the evidence, and the rules that depend on it.

## What each consent record holds

| Field           | Example                                                                                     |
| --------------- | ------------------------------------------------------------------------------------------- |
| Channel, action | Texts, agreed / opted out; Email; Calls                                                     |
| Method          | Web form, signed, verbal, text keyword                                                      |
| Source          | `web_form`, `chat` (AIlly), `import`, `connected_app`, `agent`, `sms_keyword`               |
| Reference       | The page the form was on, the connected app ("Follow Up Boss", "Zapier"), the import file   |
| Wording         | The exact consent sentence they saw                                                         |
| Evidence        | What they saw and sent (wording, form, page, box ticked, phone), IP, device, time — as JSON |
| Fingerprint     | SHA-256 of the evidence, so it can be shown to be unchanged                                 |

History stays append-only: records can't be changed or deleted.

## Rules

- **Automated and AI texts need proof**: written consent (web form or signed), after any opt-out, **and** the
  opt-in record with its wording on file. A contact flagged as opted in without a record is "verbal" (manual texts
  only). Every automated text (first responder, long nurture, alerts) passes through this check.
- **Imports arrive "unknown"**: no consent comes across from a file or another CRM; they are never texted
  automatically until they agree here.
- **Opt-outs do come across**: a do-not-text, do-not-email or do-not-call flag from a connected app, or a tag such
  as "DNC", "Do not text", "Opted out", "Unsubscribed" or "Stop", stops those messages here at once, recorded with
  the app as its source. This is honoured even for a contact the connecting person can't see.
- Leads from the brokerage's own forms are `web_form`; from AIlly chat, `chat`; from the lead API or a connected
  lead source, `connected_app`.

## Consent status and report

- The contact page shows texting consent in one word: **yes** (written, with proof), **verbal** (manual texts
  only), **no** (opted out) or **unknown** (never given here), and the history with each source.
- Owners and staff can open the **consent report** for any contact: every record with source, reference,
  wording, IP, device and fingerprint, printable (save as PDF) or as CSV for a regulator or carrier. Opening it is
  audited.

## Acceptance (PC1)

- A web-form lead's consent holds the page, wording, IP, device and a fingerprint that matches the evidence.
- No proof, no automated texts; imports are "unknown"; a DNC tag in Follow Up Boss stops texts and calls here.
- The report shows the full history and downloads as CSV; agents can't open it.
