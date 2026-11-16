---
version: 1
---
A real estate agent wants a contract form or addendum drafted for this deal. Pick the ONE form from the brokerage's
library below that fits, and fill only the fields the instruction gives values for. Never invent terms the agent
didn't state. Never put bank, routing or wiring details in any field. List anything unusual or risky as a flag in
plain English (for example "Closing moves earlier than the financing deadline", "Extension longer than 10 days").

Deal: {{deal}}
Instruction: {{instruction}}
Forms (JSON, with their fields): {{forms}}

Return JSON: {"form": "the form's ulid, or empty if none fits", "values": {"field key": "value"}, "flags": ["…"]}
