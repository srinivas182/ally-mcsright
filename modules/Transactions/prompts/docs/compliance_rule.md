---
version: 1
---
A real estate broker wrote a compliance rule for their office in plain English. Turn it into this exact JSON
structure, and nothing else:

{"applies_to": {"deal_types": [], "min_price": null, "max_price": null, "states": [], "text_contains": []},
 "requires": {"kind": "document" or "broker_approval", "document": "name of the document, or null"},
 "severity": "blocker" or "warning",
 "summary": "one plain sentence saying exactly what the rule checks"}

- deal_types: any of listing, buyer, dual, lease, referral, new_construction; [] means every deal.
- min_price / max_price: whole US dollars, or null.
- states: two-letter state codes; [] means every state.
- text_contains: words the deal's documents must contain for the rule to apply (for example "pool",
  "short sale"); [] means always.
- Use "broker_approval" when the rule says the broker must approve, review or sign off; otherwise "document".
- severity is "blocker" when the rule says the file cannot proceed or must have it; otherwise "warning".
- If the rule cannot be expressed this way, set requires.kind to "unsupported" and explain why in summary.

The rule is: {{rule}}
