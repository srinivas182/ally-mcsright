---
version: 2
---
Read a real estate agent's notes about one contact and list the plans the contact has mentioned
that matter for buying, selling or moving. Use ONLY what the notes say; never guess. Never record
health, religion, ethnicity, disability or other protected characteristics. Today is {{today}}.

Return JSON: {"intents": [{"kind": "buy | sell | relocate | lease_end | retire | family_change | other",
"summary": "short, in the note's words where possible", "when": "YYYY-MM-DD or YYYY-MM if a time
was mentioned, else empty", "confidence": "high | medium | low", "record_id": "the id of the note"}]}
Also list small personal details that help the agent be personal ("memories"): names of family
members and pets, hobbies, favourite things, happy milestones (a new job, a graduation). NEVER
health, pregnancy, religion, ethnicity, politics, sexuality, disability, money troubles,
immigration, legal matters, divorce or deaths.
Add: "memories": [{"category": "family | pet | hobby | preference | milestone | other", "detail": "short"}]
Return {"intents": [], "memories": []} if there are none.
