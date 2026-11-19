# Billing 09: package builder (PB1)

HQ builds every brokerage and solo-agent package by ticking modules. There are 17, in plain words:

- back office (always on), CRM, AI for contacts, Today's 10;
- website with AIlly, texting, calling and transcripts, AI voice calls;
- deals, forms and e-sign;
- commissions and payouts, and books and trust accounting (both brokerage only);
- Ally Studio, email marketing, property look-ups, Learn;
- recruiting (brokerage only);
- AI blog writer (MK4, also sold as an extra; see specs/marketing/01).

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

## PB2: when they need more (extras, credits, upgrades)

**Extras HQ offers** (HQ → Package builder → Extras):

- **More of something each month:** for example "1,000 more texts", "200 more call minutes" or "10,000 more website
  map views";
- **one extra module** on a smaller package, for example AI voice calls.

The price is suggested at cost + HQ's markup (70%) plus card fees, ending in 9. For example, 1,000 texts cost us $13.30,
so the suggested price is $29. A price below cost + 40% needs a written reason. HQ sees each extra's price, today's
cost and how many customers have it.

**Owners buy them** (Settings → Billing → "Need more? Add an extra"):

- An extra is on straight away: the allowance goes up, or the module's features switch on.
- It is billed on the 1st with the subscription (`billing:bill-addons`, once a month).
- Stopping keeps it on until the end of the month.
- Only the owner can add or stop extras, and a card must be on file.

**"Moving up would save you $X a month."** When the package plus extras costs at least as much as the next package
up, the owner is told the upgrade is cheaper.

**Credits:** use beyond the package and extras still draws RightAlly Credits, as before. Changing the markup in the
builder's pricing rules now keeps the credit pages in step: 70% markup is a 41% margin, and a 40% minimum markup is a
28% margin. Credit packs show their price next to the suggested price at cost + 70%.

**This month by customer** (HQ):

- what each brokerage or solo agent pays: package, extras and credits bought;
- what it costs us: texts, calls and map views used, AI credits used, platform per person and hosting;
- what we keep. Anyone costing more than they pay is shown first, in red.

Counts and money only.

## Starter prices (PB3)

`php artisan billing:starter-prices` adds RightAlly's starter prices where missing. It never changes or replaces a
package, seat or share HQ has saved, so it is safe to run on every deploy. Edit everything afterwards in HQ.

| What                                                                                                 | Price                                                                                                  |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| **RightAlly Brokerage** (owner + 2 staff, every brokerage module)                                    | $499 a month, or $4,990 a year                                                                         |
| Agent seat **Basic** (back office, CRM, deals, forms and e-sign)                                     | $38 through a brokerage · $58 on their own                                                             |
| Agent seat **Pro** (Basic + AI for contacts, Today's 10, texting, calling, website, email marketing) | $98 · $138                                                                                             |
| Agent seat **All-in** (every module)                                                                 | $158 · $198                                                                                            |
| **Solo agent** packages Basic, Pro and All-in                                                        | $58, $138, $198 a month                                                                                |
| **Brokerage share** of what its agents pay                                                           | 20% of the through-a-brokerage price, plus anything the brokerage adds on top (up to the public price) |

Agents pay RightAlly directly by default; HQ can make a brokerage pay for its agents instead (Billing settings).
What one All-in seat costs RightAlly with the current cost list: about $12.58 a month at full use, $7.58 at typical
use. The platform cost per person ($2) and hosting per brokerage ($0) are still placeholders.

**For review:** all prices and the 20% share are business defaults set by the owner (Nov 2026), not settled terms.
