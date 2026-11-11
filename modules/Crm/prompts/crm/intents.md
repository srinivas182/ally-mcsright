---
version: 1
---
Read a real estate agent's notes about one contact and list the plans the contact has mentioned
that matter for buying, selling or moving. Use ONLY what the notes say; never guess. Never record
health, religion, ethnicity, disability or other protected characteristics. Today is {{today}}.

Return JSON: {"intents": [{"kind": "buy | sell | relocate | lease_end | retire | family_change | other",
"summary": "short, in the note's words where possible", "when": "YYYY-MM-DD or YYYY-MM if a time
was mentioned, else empty", "confidence": "high | medium | low", "record_id": "the id of the note"}]}
Return {"intents": []} if there are none.
