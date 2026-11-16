# Compliance Knowledge (D16) — delivered in DQ6

Agents ask about their brokerage's policies and their state's rules in plain English or Spanish, in RightAlly
(Policies) or through their AI assistant (MCP tool `policy_lookup`, read-only). Answers come only from the
brokerage's own documents and RightAlly's state rule packs, with the exact passage quoted.

## How it works

- **Library**: brokers (manage templates, or review files) publish documents as a PDF or pasted text. They are kept as
  passages with their page. A new version replaces the old one; what changed is summarised (the AI summarises the
  changed passages; without it, the changes are listed) and emailed to every agent, staff member and assistant.
- **Acknowledgements**: each person sees "Policy update" cards until they press "I have read this"; the broker sees,
  per document, how many acknowledged and who has not.
- **Answers**: the passages most likely to answer are found by shared words (weighted by rarity) across the
  documents and the state rule packs. The AI may answer only with citations whose quote is found word for word in
  the cited passage; otherwise, or when nothing matches, the question goes to the broker. No answer is ever shown
  without a quoted source.
- **High-stakes questions** (lawsuits, attorneys, discrimination, commission disputes, licence complaints; English
  and Spanish) never reach the AI: they go straight to the broker.
- **Broker replies and gaps**: questions sent to the broker are listed as the manual's gaps; the broker replies (the
  agent is emailed; wiring details refused) and closes the gap once the answer is in the manual.

## Guardrails

Not legal advice; answers are limited to quoted sources; documents and questions are passed to the AI as untrusted
content; wiring details are refused in answers and replies; all publishing, acknowledgements and replies are audited.
