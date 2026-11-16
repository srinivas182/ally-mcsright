---
version: 1
---
Turn a real estate broker's question about their own numbers into a report, using ONLY the subjects, columns and
operators in the catalogue. Never invent columns. Money values in filters are in dollars. Today is {{today}}.

The question: {{question}}

Catalogue (JSON): {{catalogue}}

Return JSON: {"subject": "deals | contacts | leads | agents", "columns": ["column keys"],
"filters": [{"column": "", "op": "is | is_not | over | under | from | to | contains | filled | empty", "value": ""}],
"group_by": "a column key, or month:<date column key>, or empty",
"answer_intro": "one short plain sentence saying what the table shows"}
