---
version: 1
---
A real estate agent dictated an update about the contact {{contact_name}}. Turn it into a proposed
CRM update. Do not invent anything that was not said.

What the agent said: {{text}}

Return JSON:
{"note": "the update as a clear note, in the agent's words where possible",
 "call_outcome": "connected | left_voicemail | no_answer | none",
 "lifecycle": "lead | active | under_contract | past_client | sphere | none",
 "tags": ["short labels only if clearly stated, e.g. pre-approved"],
 "task_title": "a follow-up the agent said they will do, or empty",
 "task_due": "today | tomorrow | next_week | none"}
