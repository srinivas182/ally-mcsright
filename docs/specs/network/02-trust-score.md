# Referral Trust Score (AR2)

**Status:** built in AR2.

## Why

Agents worry most about two things when sending a client away: will the other agent look after them, and will the
referral fee ever arrive. Directories show reviews written by clients; none show how an agent handles **referrals**.

## The score

A number from 0 to 100, worked out only from what the agent did with referrals sent to them in the last two years.
Shown once they have answered **3** referrals (accepted or declined). Brokerage size, plan, or anything paid to
RightAlly never affects it.

| Part                      | Weight | What counts                                                                           |
| ------------------------- | ------ | ------------------------------------------------------------------------------------- |
| Pays the fee              | 35     | Closed referrals where the referring side confirmed the fee within 45 days of closing |
| Answers quickly           | 20     | Referrals answered within 24 hours; referrals left unanswered count against           |
| Keeps you updated         | 20     | Accepted referrals with an update in the last 30 days, or finished                    |
| Closes                    | 15     | Accepted referrals that closed, of those that have finished                           |
| Rated by referring agents | 10     | Average of 1–5 star ratings from referring agents                                     |

A part with nothing to measure yet is left out and the other weights are scaled up. Every part is shown in plain
words ("Paid 2 of 3 referral fees within 45 days of closing").

## Ratings and disputes

- After a referral closes or doesn't work out, the referring agent can give 1–5 stars and a short note.
- 45 days after closing, if the fee hasn't been confirmed, the referring agent can report it as not received. The
  other agent is told and can reply; HQ sees every dispute (**HQ › Referral network**) and closes it once both sides
  agree it is settled. An open dispute counts as unpaid; a fee settled after 45 days still isn't "on time".

## Where it shows

- Next to every agent in **Find an agent** (with "What makes up this score"); higher scores come first.
- On the agent's own profile, with tips to improve it.
- On public agent pages.

It is recalculated after every referral event and every night (`network:trust`, 05:40).

## For review

The weights, the 45-day payment window, and the minimum of 3 referrals.
