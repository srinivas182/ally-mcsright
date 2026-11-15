# Grow 09 — Lead-IQ long nurture: 12 months of AI follow-up

Team E. Part of Lead-IQ (specs/grow/02, "Long nurture"). Sprint LN1. Approved in the growth catch-up (2026-11-15).

## Why

Most portal and ad leads are 6 to 18 months from moving. The first responder (specs/grow/06) handles the first
days; after that, agents stop following up and the brokerage loses the lead it paid for. The leaders (Ylopo,
Structurely, Real Geeks) run long AI nurture; ours runs inside the brokerage's own CRM, with its MLS data, consent
records and inbox, at no extra subscription.

## Who gets it

A brokerage switches it on in Leads → Settings → AI assistant ("Keep in touch with quiet leads for 12 months").
A contact is enrolled when all of these are true:

- They came in as a lead between 7 days and 12 months ago, and are not in the lead pond.
- They are still a lead (not active, under contract, a past client, sphere or archived).
- No active first-responder conversation, and no message either way in the last 7 days.
- Reachable: an email address that may be emailed, or written consent to automated texts.

## What it sends

- Each message has a real reason, chosen in this order: new homes or price drops for their saved search; their
  home's value (from the monthly home-value report, specs/grow/03); a market update for their ZIP (homes for sale,
  median asking price, new and reduced in 30 days, days on market); otherwise a friendly check-in.
- Written by the AI from those facts only, says it is the agent's AI assistant, one easy question, short. Fair
  Housing checked; a message that fails is not sent (retried the next day).
- Email first (lighter than a text); texts only with written consent. Every send goes through the inbox checks:
  opt-outs, quiet hours (8am to 9pm where they are; a text waits for the window), and appears in the inbox.

## When

- First touch when enrolled, then after 7, 14, 21, 30, 30, 45, 45 and every 60 days.
- Slower while they don't answer: after three unanswered touches, each wait grows by a quarter per unanswered touch
  (at most 90 days).
- Faster when they show interest: back on the brokerage website in the last 3 days, the next touch is within 2
  days.

## How it stops

- They reply: marked "replied", their agent is alerted ("Jane Doe replied after 3 follow-ups") and the
  conversation is flagged as needing a reply.
- An agent writes to them from the inbox, or presses "Stop follow-up" in the inbox.
- They stop being a lead, opt out, or can no longer be messaged; or 12 months after the lead came in.
- Every start, stop and send is on the contact's timeline. `crm:nurture` runs hourly.

## Cost

AI messages use RightAlly Credits (`ai.crm_nurture`, 1 credit per message), like the first responder.

## Acceptance (LN1)

- A quiet lead is enrolled after 7 days, gets a check-in email, and is handed to the agent when they reply.
- An agent's message or Stop ends it; clients, active conversations and switched-off brokerages are left alone.
- Waits stretch while unanswered and shrink after a website visit; unsafe wording is never sent; it ends after 12
  months.
