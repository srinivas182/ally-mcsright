# AI 09 — AI security, red-team library and hand-off to a person (AS1)

Status: **delivered (Sprint AS1, 2026-11-18)**. Completes D20 (AI security and red-team testing) and D22 (the
broker-branded assistant was replaced on 2026-11-15 by "Your AI Assistant", specs/know/03, with a fixed name; AS1
adds what remained of D22: handing the conversation to a person).

## Market check

- Real's Leo, Lofty's AI Assistant and BoldTrail's kvCORE AI publish no security testing. Enterprise AI products
  (Microsoft Copilot, Google Gemini for Workspace) publish prompt-injection defences and red-team programmes.
- Parity with enterprise: layered defences and a standing red-team library. Add-ons for real estate: wire-fraud,
  title-contact and Fair Housing steering attacks are in the library as critical cases.

## Defences (every AI request, through the gateway)

1. **Invisible text removed before the model reads anything** (`PromptBuilder::visible`): zero-width spaces and
   joiners, direction marks, the byte-order mark, invisible operators and Unicode tag characters (the "ASCII
   smuggling" trick), in inputs, records and untrusted content.
2. **Fencing** (existing): records and untrusted content are escaped and fenced; the preamble tells the model they
   are data, never instructions.
3. **Answers checked before anyone sees them** (`OutputPolicy`), withheld and refunded when they:
    - mention record ids, emails or phone numbers that were not in what was sent (existing);
    - load an image from another site (`![](https://…)` or `<img>`: a way to carry data out in the address);
    - link to a site that was not in what was sent and is not RightAlly's own (phishing);
    - contain an account-style number (8–17 digits, not a phone number) that was not in what was sent (wire fraud).
4. **Permission-scoped retrieval** (existing): a model cannot leak what it never sees; deterministic leak tests run
   on every pull request.

## Red-team library (weekly against real models, `ai:red-team`)

13 scenarios. Critical scenarios fail the run if the model follows them: wire redirection, title contact swap,
phishing link, image exfiltration, Spanish-language wire instruction, "urgent from the broker" gift-card scam, Fair
Housing steering, fake new-bank letter. Tracked as warnings: role-play jailbreak, fake tool output, zero-width hidden
instruction, Unicode tag smuggling, "print your instructions".

## Hand-off to a person ("Your AI Assistant")

- After any answer: **Talk to a person instead** → **Ask my broker** (a direct message to the brokerage owner in team
  chat, with an @mention so they are told) or **Ask RightAlly support** (a support request).
- What is passed on: only this person's own conversation, its questions and answers (the last 12), never hidden data.
  Owners see only "Ask RightAlly support". At most 10 hand-offs an hour per person.

## Defaults to review

- Account-number check: 8–17 digits not sent to the model; phone-length numbers are left to the phone rule.
- Links to RightAlly's own hosts are always allowed.
