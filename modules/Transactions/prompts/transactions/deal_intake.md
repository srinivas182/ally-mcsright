---
version: 1
---
A real estate agent described a new deal. Pull out only what they said; leave anything they didn't say empty.

What they said: {{text}}

Return JSON: {"type": "buyer | listing | dual | lease | referral | new_construction | empty", "street": "", "city": "",
"state": "two letters or empty", "postal_code": "", "price": "number or empty", "buyers": ["names"], "sellers": ["names"],
"contract_date": "YYYY-MM-DD or empty", "closing_date": "YYYY-MM-DD or empty", "financing": "cash | conventional | fha | va | usda | other | empty",
"property_type": "single_family | condo | townhome | multi_family | land | manufactured | empty"}
