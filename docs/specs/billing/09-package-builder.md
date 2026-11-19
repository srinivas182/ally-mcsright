# Billing 09: package builder (PB1)

HQ builds every brokerage and solo-agent package by ticking modules. There are 16, in plain words:

- back office (always on), CRM, AI for contacts, Today's 10;
- website with AIlly, texting, calling and transcripts, AI voice calls;
- deals, forms and e-sign;
- commissions and payouts, and books and trust accounting (both brokerage only);
- Ally Studio, email marketing, property look-ups, Learn;
- recruiting (brokerage only).

**What HQ sets for each package**

- the number of people included;
- for each module, what is included, per person or per package. Examples: 300 texts per person, 2,000 website map
  views, 150 AI credits per person.

**What it costs RightAlly**

- Every included item is priced from the cost list as if **all of it is used**. Hovering ⓘ shows the sum, for example
  "10,000 texts × $0.0133 = $133.00 (Twilio $0.0083 + carrier fee up to $0.005)".
- AI credits use the typical real cost per credit from the last 30 days.
- Fixed costs: the platform cost per person and hosting per brokerage.
- Card fees: Stripe's 2.9% + $0.30, worked out on the price.
- A typical month (50% used) and the worst case (every AI credit on the costliest action) are shown for information.

**The price**

- Price = cost × (1 + markup), rounded up to whole dollars ending in 9. The default markup is **70%** (cost $100 →
  price $170). That means RightAlly keeps about 41%, and both figures are always shown.
- A price below cost + **40%** can only be saved with a written reason, which is kept with the package and in the
  audit log.
- The yearly price is 10 months.

**Seeing the whole picture**

- "Everything on" shows the price of all modules: for a 10-person brokerage, and for a solo agent.
- Each package's cost is recalculated at today's prices, so HQ sees when a provider's price change eats the margin.

**What saving does**

- It sets the package's features (what the back office switches on), and the included texts, call minutes, map views
  and AI credits that the usage meter already counts.

The defaults below are placeholders, flagged for review; HQ changes them under Pricing rules and Costs:

- 70% markup, 40% minimum, 50% typical use, 10-month year;
- $2 per person per month platform cost (HQ must enter the real figure);
- the included amounts per module.
