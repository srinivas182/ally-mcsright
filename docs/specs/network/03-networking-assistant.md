# AI networking assistant (AR3)

**Status:** built in AR3.

## What it does

On **Referral network › Ask the assistant**, the agent describes a client who is moving, in their own words
("My clients are moving to Denver this spring and want to buy around $550k; they'd like a Spanish speaker"). The
page asks them to leave out the client's name and contact details.

1. **AI reads the needs** (feature `network.match`): buying or selling, where, price range, timing, language, and
   anything else. It shows "What I understood" and anything it couldn't use. It never returns names, phones or
   emails, even if they were typed in.
2. **RightAlly agents who cover that area**, ranked by Referral Trust Score, with a **call button** when the agent
   shares their phone, and **Send a referral**.
3. **Top local agents on that side** from the brokerage's own MLS data: closings in that city or ZIP in the last
   12 months, as the listing agent (sellers) or the buyer's agent (buyers), with their office. Agents in the
   brokerage's own offices are marked. Old sales don't count.
4. **Draft a first message** (feature `network.intro`) to a local agent: 50–100 words, warm and plain, asking if
   they'd like a quick call. It never includes the client's details, never names a fee percentage ("a usual referral
   fee, agreed in writing"), never mentions the MLS or rankings, and is checked for Fair Housing wording; a draft
   that fails the check isn't shown. The agent copies it and sends it themselves.

## What it never does

- Contact anyone, send anything, or send in bulk.
- Pass the client's identity to the AI.
- Show other agents' email addresses (MLS data here has none; network profiles never show them).

## Costs

One credit each for reading the needs and for a message draft (`ai.network_match`, `ai.network_intro`). Both have
test sets in `modules/Network/evals/network/`.
