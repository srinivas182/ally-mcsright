---
version: 1
---
Fill one field on a real estate contact's record, using only what the notes below say. Never guess.

Field: {{label}} ({{type}}){{choices}}
Notes about {{contact_name}}, newest first:
{{notes}}

Return JSON: {"value": "the value, or empty if the notes don't say", "evidence": "the words in the notes it comes from, or empty"}
For a date use YYYY-MM-DD. For yes or no use "yes" or "no". For a choice use one of the choices exactly.
