# Grow 01 — AI Networking Assistant (Agent Referral Network; Team A, Sprints 54–55)

Status: **Module definition approved.** Detailed specification after its best-in-class review
(definition of ready), once the Recruiting agent database exists.

## Purpose

Agents find the right local agents to help with a property, from any brokerage, and build a
working relationship: a listing partner, **an agent with buyers for their seller's home**, a
buyer's agent where their client is moving, or local help with a showing or open house.

## Who uses it

Inside the back office: brokerage owners and staff, agents in a brokerage, and solo agents.

## How it works

1. **Ask by typing or speaking** (mic on every device): the address, the home, and the help needed.
2. **Results from the market feed, not just the user's brokerage:** agents near the property
   (1–2 miles, widening if needed), ranked for the side needed (listing-side or buyer-side sales
   nearby in the last 12–24 months), price range and home type, with recent nearby sales, typical
   days on market and Referral Trust Score where known. Agents from the user's own brokerage are
   tagged "Your brokerage" and can be shown first (brokerage setting).
3. **Buyer and listing requests:** RightAlly network agents can post "I have buyers looking here
   for $350–450k" or "I need a buyer for this home"; matching requests are shown first.
4. **Connect:** tap to call (the agent's own phone; logged in the CRM), or an AI-drafted email the
   agent edits and approves. Replies, notes and follow-up reminders are tracked in the CRM.
5. **Referral to closing:** referral agreement, fee and time limit tracked through Deal-IQ and paid
   through the compensation engine.

## Data coverage

MLS data is licensed **per MLS region** (about 500 US MLSs; some metro, some statewide, some
multi-state), not per state.

| Situation                            | Source                                                                                                                                                     |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Inside the brokerage's own MLS areas | The brokerage's MLS access, licensed for agent roster and production use                                                                                   |
| Other areas                          | RightAlly vendor data licences, signed MLS by MLS (optionally a licensed national agent-production dataset)                                                |
| No licensed data yet                 | State licensing board data (name, licence, brokerage; no sales) plus RightAlly network members; the screen says sales rankings are not yet available there |

## Guardrails

- No scraping of portals (Zillow, Realtor.com); licensed data only.
- First contact by agent-approved email (CAN-SPAM) or the agent's own call; no robocalls or cold
  text blasts (TCPA); texting only after the other agent replies or opts in.
- Sharing a seller's property details before MLS listing follows that MLS's marketing rules,
  checked by the compliance module.
