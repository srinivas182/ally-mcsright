# Marketing 01 — RightAlly's own website (SG1)

Status: **delivered (Sprint SG1, 2026-11-18)**. Completes A19 with the online signup and automatic launch from
billing spec 07.

## Market check

| Leader                                    | Their public site               | Where they fall short                                       |
| ----------------------------------------- | ------------------------------- | ----------------------------------------------------------- |
| Lofty, BoldTrail (kvCORE), Follow Up Boss | Polished marketing sites, blogs | Prices after a demo (except FUB); signup goes through sales |
| Real (reZEN), eXp                         | Recruiting-led sites            | Not sold as software to other brokerages                    |
| Brokermint, Lone Wolf                     | Product pages                   | Back office only; no Spanish                                |

RightAlly: public prices pulled live from billing on every page that shows them, online signup with the chosen
package preselected, English and Spanish twins of every page, and every page built for AI answer engines
(answer-first blocks, FAQ markup, `llms.txt`, AI crawlers welcome).

## What HQ can do (HQ → RightAlly website, `/hq/website`)

- Pages in English and Spanish (same address under `/es`), built from blocks: top of the page, short answer (25–80
  words, answer first), features, steps, questions and answers, comparison table (2–5 columns, with "checked on" date
  and sources note), live prices, text, what customers say (only with each customer's permission ticked), call to
  action.
- Every change is a private draft; a signed one-hour preview link (noindex); publish copies the draft live and keeps
  the last 20 versions; any version can be put back into the draft; pages can be taken off the site (not the home).
- The search and AI-answer check lists each fix: title 25–60 characters, description 70–160, the page starts with
  its main heading, has a short answer or FAQ, has a call to action, has its other-language twin, comparison facts
  have sources.
- Blog posts in Markdown (raw HTML stripped, safe links, table of contents), drafts, publishing now or at a set time.
- Text only: no HTML in blocks; links are site paths or https.

## Public site (hosts in `MARKETING_HOSTS`, default `rightally.io,www.rightally.io`)

- Server-rendered Blade, no session or cookies, cached 5 minutes at the edge; other hosts redirect (301) to the
  first.
- Each page: canonical, `hreflang` en/es/x-default, Open Graph, JSON-LD graph (Organization with `sameAs` from
  `MARKETING_SAME_AS`, BreadcrumbList, FAQPage from FAQ and short-answer blocks, SoftwareApplication with an Offer
  per live package, BlogPosting for posts).
- `sitemap.xml` with `lastmod`, `robots.txt` allowing GPTBot, OAI-SearchBot, ChatGPT-User, PerplexityBot, ClaudeBot,
  Claude-SearchBot, Google-Extended, Applebot-Extended and Bingbot, `llms.txt` with every live page and the prices.
- Big text (19px), 56px buttons, skip link, works without JavaScript.
- "Start now" goes to the online signup on the RightAlly host; a package chosen on the price page arrives selected.
  Payment creates the brokerage automatically (billing spec 07).

## Starter content

`php artisan marketing:starter` adds home and pricing (English and Spanish), for agents and security (published),
and a comparison page **as a draft**: facts about other companies must be checked with sources and a date by a
person before it is published. Existing pages are never touched.

## Defaults to review

- Starter wording and the comparison draft need a marketing and legal read before launch.
- The site's canonical host (`rightally.io`) and `sameAs` profiles are set by environment.
