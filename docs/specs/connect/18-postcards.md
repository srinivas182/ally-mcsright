# Connect 18 — Postcards through Lob (C7c)

Sprint **C7c** (Team A with D). Part of the C7 block (specs/connect/15). ProspectsPLUS and Corefact are
partner-only; RightAlly Postcards works today.

## How it works

- **Grow → Postcards** (owners, staff, agents): choose the card (just listed, just sold, open house, neighbourhood
  update), the size (4×6 or 6×9) and who gets it (one of your smart lists), write the headline and message, add the
  page the QR code opens (a listing or funnel) and the return address.
- **See it first:** both sides of the card (front with the listing photo and the brokerage, back with the message,
  a QR code and Equal Housing Opportunity), how many cards and how many credits.
- **Approve:** every address is checked with Lob's US verification; **only deliverable cards are paid for**
  (RightAlly Credits); Lob prints and mails them the **next business day**.
- **Cancel** until the send date: each card is cancelled with Lob and its credits come back. Cards Lob refuses are
  refunded too.
- The QR code link carries `utm_source=postcard`, `utm_medium=direct_mail`, `utm_campaign=rightally-{mailing}`, so
  visits and leads count in reports.

## Rules

- People tagged **"Do not mail"**, archived contacts and contacts without a full address never get cards.
- Headline and message pass the **Fair Housing** check (channel `ads`); the back always names the brokerage and
  carries Equal Housing Opportunity and "if your home is listed with another broker, please disregard".
- Same visibility as the screens: a smart list only reaches contacts the sender can see.
- Lob `use_type` is `marketing`; each card has an idempotency key, so a retry never mails twice.

## For HQ

- One Lob account for RightAlly (live secret key); HQ sets the price per card for each size (Lob's price plus
  margin) under Connect setup → Postcards. Start with a test key: test mail is never printed.

## Acceptance (C7c)

- The price and the card show before anything is bought; only deliverable cards are charged; cancelling before
  the send date returns the credits; "Do not mail" is respected; Fair Housing wording is refused.
