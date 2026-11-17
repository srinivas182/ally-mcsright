# Guided setup (SU2)

Status: Done. Tracker: A21 Partly (SU3 adds the ready-made library).

## What it does, in plain words

**Settings → Guided setup** (owners).

- **Ready to go live: 67%.** A bar and six steps, each ticked from the brokerage's own data. Every unticked step has a **Do this** link.
    1. A commission plan is published.
    2. Your people are brought in.
    3. Your agents are invited.
    4. New leads have somewhere to go.
    5. Your website is live.
    6. Your first deal is in RightAlly.
- **Tell us in your own words.** For example: "Agents get 80/20 with an $18,000 cap and a $395 fee per deal. New leads go round robin. We'd like the assistant to answer new leads. Light, clean website."
    - AI reads this into the answers below, for the owner to check. It never guesses numbers.
    - It lists what it couldn't use, and ignores instructions hidden in the text.
    - **Nothing is set up until the owner presses "Set it up".**
- **Four questions**, each with big buttons:
    1. **How do you pay your agents?** A split (agent's share, yearly cap, fee per deal), or a flat fee per deal. This drafts a commission plan, "80/20 plan (from setup)". **It is a draft: nothing is paid on it until the owner checks and publishes it** in Compensation › Plans.
    2. **Who should new company leads go to?** In turn to each agent, to whoever claims it first, or to me. This sets one lead-routing rule, "New leads (from setup)", which is updated if the question is answered again. Until agents are on RightAlly, "in turn" and "first claim" wait, and the page says so.
    3. **Should the assistant answer new leads within a minute?** This switches the AI responder on or off. Its rules don't change: it says it is your AI assistant, texts only people who agreed, between 8am and 9pm, and hands over when they're ready.
    4. **What should your website look like?** Light and clean, dark and elegant, or warm and friendly. This drafts the brokerage website in that style (Modern, Aurum or Hearth), not yet published.
- Under each question, the page says what was set up, in one sentence.

## Market leaders

| Leader                 | What they do                                           | Where they fall short                                           | What we do                                                                             |
| ---------------------- | ------------------------------------------------------ | --------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Lofty                  | Onboarding specialist calls; agentic AI setup promised | Setup happens on calls; the broker can't see how ready they are | Self-serve questions, or plain words read by AI, with a readiness score from real data |
| kvCORE / BoldTrail     | Implementation team and checklists                     | Long, sales-led setup; agents find it overwhelming              | Four questions with big buttons; everything changeable later                           |
| Brokermint / Lone Wolf | Plan setup by their team                               | Commission plans set up by hand over calls                      | A draft plan from two numbers, checked and published by the owner                      |

## For agents and brokers aged 50+

- Four questions, with big buttons for each answer.
- One "Set it up" button.
- "Tell us in your own words" for anyone who'd rather talk than click.

## Security and money

- Owners only.
- Commission plans are always drafts until published. Lead routing and the assistant use their existing rules and audit.
- The description goes through the AI gateway: redacted, never used for training, and tested by the `setup.describe` test set.

## Review

- **Review:** the website styles map to the Modern, Aurum and Hearth templates. Other templates are in Settings › Website.
