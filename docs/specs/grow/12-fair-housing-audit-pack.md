# Grow 12 — Fair Housing checks on every channel, with an audit pack

Sprint FH1. Team D. Addition 6 in `docs/plan/market-2026-additions.md`.

## One check, every channel

The same Fair Housing check (wording that can steer buyers or describe people rather than the home) runs before
wording is used anywhere, and now records itself by channel:

| Channel                 | Where it runs                                                 | What happens to flagged wording |
| ----------------------- | ------------------------------------------------------------- | ------------------------------- |
| Ads                     | Listing and funnel ads; AI ad drafts                          | Stopped; a warning on drafts    |
| Texts and emails        | Every inbox send; the AI first responder; the long follow-up  | Stopped before sending          |
| AIlly website assistant | Each chat reply; greeting tests                               | Sentence removed; test stopped  |
| Websites and funnels    | AI website content; funnel pages                              | Sentence removed; page stopped  |
| AI drafts and summaries | Contact brief drafts, database insights, reply drafts, memory | Removed; a warning on drafts    |
| Templates and plans     | Message templates, follow-up plan steps, sign codes           | Stopped                         |
| Listing descriptions    | Reserved for List-IQ (specs/ai/04) when it ships              | —                               |

Each day's checks are counted per channel; each flagged item is logged with the channel, what happened, the
phrases, a short excerpt, where it was (e.g. "Ad: 12 Oak St") and who was writing (or "Automatic (AI)"). The check
never fails a send because of logging.

## The monthly audit pack

- Settings → Fair Housing (owners): this month so far by channel, the latest flagged items, and the last 12 months
  with a PDF for each.
- On the 1st, last month's pack goes to every owner by email with the PDF attached (`crm:fair-housing-pack`).
- The PDF: totals, a table by channel (checked, flagged, stopped, removed, warned), every flagged item with when,
  channel, what happened, the wording and why, and how the check works with the phrases it looks for. Downloads
  are audited.

## Acceptance (FH1)

- Every outbound AI text and ad passes the recorded check; counts and logs are per channel.
- The pack lists what was changed and why; owners get it monthly; only owners can open it.
