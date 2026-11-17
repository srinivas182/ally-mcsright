# Agent referral links, broker approval, and website calculators (AW1)

Status: Done. Tracker: C09.

Agent websites, landing pages and compare homes were already live (specs/websites/07 and 09). AW1 adds what leaders charge extra for.

## What it does, in plain words

- **Referral link and QR code for every agent.**
    - The link is `yourbrokerage.com/r/jane-doe`. Agents print the QR code on cards, signs and flyers, and share the link in emails and posts.
    - For 30 days after someone comes in through it, any form they send anywhere on the brokerage website goes to that agent, not the round-robin.
    - `?to=/homes` (or any page on the same site) sends people to a particular page. Links to other websites are refused.
    - Agents find both in Settings, My website, with Copy link and Download QR code.
- **Broker approval.** In the brand kit there is a new choice: "I approve agent websites before they go live".
    - When an agent presses Publish, the website waits as "Waiting for your approval".
    - The broker presses **Approve and publish**.
    - A second new choice turns referral links off for the whole brokerage.
- **Free calculators** at `/calculators`:
    - **Monthly payment**: principal and interest, tax, insurance, HOA, and mortgage insurance under 20% down.
    - **What you can afford**: the 28/36 guideline, plus a button to "See homes up to $X".
    - **Rent or buy**: totals over the years you stay, equity, and the break-even year.
    - Every answer shows how it was worked out and says it is an estimate, not a loan offer.
    - The forms work without JavaScript, and every answer is a shareable link.
- **The brokerage's own figures.** In Settings, Website, Calculators, the broker sets the interest rate, down payment, property tax and insurance that calculators start from. Listing pages' payment estimates use the same figures.

## Market leaders

| Leader                        | What they do                    | Where they fall short                                                                     | What we do                                                                       |
| ----------------------------- | ------------------------------- | ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| kvCORE / BoldTrail            | Agent sites, landing pages      | Referral attribution is per-page or needs agent sub-domains; QR codes are a separate tool | One link and QR per agent, 30-day attribution across the whole brokerage site    |
| Lofty                         | Agent sites with calculators    | Calculators are JavaScript widgets that search engines can't read                         | Server-rendered calculators with FAQ and HowTo data, in the sitemap and llms.txt |
| Real Geeks                    | Mortgage calculator on listings | Fixed rate assumptions the broker can't change                                            | The broker sets the figures once and every page uses them                        |
| Luxury Presence               | Polished agent sites            | No broker approval flow for agent-built pages                                             | The broker can approve before any agent site goes live                           |
| eXp / Real (agent attraction) | Agent referral links            | Built for recruiting, not consumer leads                                                  | Consumer lead attribution, kept separate from recruiting referrals               |

## SEO and AEO

- `/calculators` and each calculator are indexable, with a canonical URL, FAQPage and HowTo structured data, breadcrumbs, and entries in the sitemap and llms.txt.
- Pages with your own numbers are `noindex` and `no-store`, so search engines see one clean page per calculator.
- `/r/...` redirects are `noindex` and `no-store`.

## Privacy and compliance

- The referral cookie is first-party and HttpOnly. It holds only the agent website's ID; it carries no personal data.
- Calculators state their assumptions, and that lenders decide. There is no steering or language about people (Fair Housing).

## Settings and review

- Default figures (`sites.mortgage`): rate 6.5%, 20% down, 1.2% tax, $150 a month insurance, mortgage insurance at 0.5%, 3% growth for home value and rent. Rent or buy assumes 3% closing costs, 1% upkeep and 6% selling costs. **Review:** these are defaults.
- The 30-day referral window is a default to **review**.
