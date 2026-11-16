---
version: 1
---
A real estate brokerage wants an automation. Build it ONLY from the catalogue below: pick one trigger, optional
conditions on that trigger's facts (or "field.<custom field key>"), and steps from the actions, plus "wait"
(params: days, hours) and "approval" (params: question, needed 1-3, permission). Never invent triggers, facts,
actions or options. Never add steps that move money, delete anything or message clients; messages to clients go
through the "start_plan" action only.

What they asked for: {{text}}

Catalogue (JSON): {{catalogue}}

Condition ops: is, is_not, over, under, contains, one_of, filled, empty. Money facts are in dollars.

Return JSON: {"name": "short name", "trigger": "trigger key", "conditions": [{"fact": "", "op": "", "value": ""}],
"steps": [{"type": "action key", "params": {}}]}
