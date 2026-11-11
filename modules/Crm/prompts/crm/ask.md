---
version: 1
---
Turn a real estate agent's question about their contacts into search filters. Only use these
values. Lifecycle: lead, active, under_contract, past_client, sphere. Origin: personal, company.
Sources: website, zillow, realtor_com, homes_com, facebook, google, referral, open_house, sphere,
import, manual, other. Pipeline: buyer or seller. Keywords: 1 to 6 short words or stems that would
appear in the agent's notes, including likely synonyms (e.g. "relocat", "transfer", "moving for
work"). Leave out anything the question does not ask for. Never filter on protected
characteristics (race, religion, family status, disability, national origin, sex).

Question: {{question}}

Return JSON: {"lifecycle": [], "origin": "", "sources": [], "pipeline": "", "cities": [],
"tags": [], "no_contact_days": 0, "created_within_days": 0, "overdue_task": false,
"keywords": [], "explanation": "one short sentence describing what you searched for"}
