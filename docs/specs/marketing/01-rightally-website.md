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

## MK1: a website that sells itself

**Rules that apply to every page and post** (`Marketing\ClaimsCheck`, checked on every save, owner's decision of
November 2026):

- **No other company is named** anywhere on RightAlly's website, including titles, descriptions and blog posts.
- **RightAlly is never called "cheap", "cheapest" or "lowest price".** It sells on value: everything in one place,
  one bill, easy for everyone, data kept safe, and we move you.
- A save that breaks these rules is refused with the reason.

**New blocks**

- **Two doors** at the top of the home page: "I run a brokerage" and "I'm an agent", each leading to its own page
  (`/for-brokers`, `/for-agents`).
- **What is included** is read live from the package builder. A module shows once it is in a live package for that
  audience, along with which packages include it. Its 1-minute video shows when HQ adds one under HQ → Help videos
  for the page `/modules/<module>`. Turning a module on in a package puts it on the website, with no page editing.
- **"What do you pay today?"**: the visitor types what they pay each month for separate tools, by category, never by
  company. The page shows that total next to the cheapest RightAlly package that covers all of it, and the saving on
  one bill.
- **Prices for each audience**: brokerage packages, or agent plans whose "Choose" button goes to the agent sign-up.

**Starter content**

- The home page leads with the two doors, what is included, the calculator, "We move you", "Easy for everyone"
  (Today's 10, the microphone, Help, practice clients), "Your data stays yours", prices and questions.
- New page "For brokerages"; "For agents" now has its modules, calculator and plans.
- `marketing:starter --refresh` saves the new content as **drafts** of pages that already exist. Nothing live
  changes until HQ publishes.

**Still to come:** "Try it now" with made-up clients and no sign-up needs a separate demo brokerage that resets
itself, so it comes with self-serve onboarding (MK2).

## MK3: the AI content desk (HQ → Content desk)

**What it does.** HQ turns writing on per state (Off, Test or Live) and can list the cities that matter most. Otherwise
the cities are ranked by how many listings each has. Each night (`content:desk`) the desk:

1. fetches Search Console numbers;
2. moves each state up or down a level;
3. writes that day's drafts for broker owners and agents. Landing pages and articles alternate, for example "Running a
   real estate brokerage in Houston, Texas" or "Texas rules every real estate broker should know".

**Every draft is checked before a person sees it.**

- The shape search engines and AI answers expect: a direct 40–60 word answer, 4–6 sections, 3–5 questions, a title
  under 65 characters and a 120–155 character description.
- At least 3 facts, each with an https source. The reviewer sees them with their links.
- No other company is named, nothing is called "cheap", and the Fair Housing wording check passes.
- **Not similar to anything already written in the state**, by HQ or any brokerage: at most 20% of five-word runs in
  common.
- A draft that fails is rewritten with the reason, at most twice. If it still fails, the slot is skipped and HQ sees
  why. Skipping is always better than publishing something weak or a lookalike.

**Topic booking.** A topic and angle booked in a city is not given to any other desk, HQ or tenant, for six months.

**Morning review.** HQ reads each draft, can edit the title, description and text, and checks the facts. It then
approves or rejects it. Nothing is published without a person.

When a draft is approved in Live mode:

- landing pages are published under the state (`/tx/...`) with prices; articles go to the blog under the reviewer's
  name;
- the sitemap and llms.txt include it at once;
- **IndexNow** tells Bing and other search engines straight away. The key is at `/indexnow-key.txt`. ChatGPT search
  relies on Bing;
- Google finds it through the sitemap and the state's links. Google offers no way to request a visit for ordinary
  pages;
- it is shared through the **Share new pages on social media** service: a Zapier webhook (recommended), a Make
  webhook, or Buffer, posting to LinkedIn and Facebook.

In Test mode, approving only saves a website draft, and nothing is announced.

**How much it may write** (`Content\Cadence`). The same rules apply to HQ, brokerages and agents, and are enforced on
the server:

| Level       | How often                        | How it is reached                                                            |
| ----------- | -------------------------------- | ---------------------------------------------------------------------------- |
| 1 Starting  | 3 a week; a person approves each | every new desk                                                               |
| 2 Trusted   | up to 1 a day                    | 4 weeks at level 1, at least 10 published, at least 80% indexed, no warnings |
| 3 (HQ only) | up to 4 a day per state          | 8 more healthy weeks                                                         |

- Hard ceilings: 1 a day for a brokerage or agent site, and 4 a day per state for HQ.
- Without Search Console numbers, a desk stays at level 1.
- A warning, or indexing below 80%, drops a desk one level, and the change is audited.

**Search Console numbers** come from the **Search Console numbers** service:

- Google Search Console (recommended): the URL Inspection API on the desk's 50 most recent pages, using a service
  account with read access;
- Bing Webmaster Tools;
- or typed in by hand.

**AI.** Writing uses the `marketing.content` route in HQ → AI gateway, with prompt `marketing.content_desk`. The cost
is charged to HQ, or to the brokerage for MK4 desks.

## MK4: the AI blog writer (an extra for brokerages and agents)

The content desk, sold as an add-on. It writes local posts for home buyers and sellers on the brokerage's own
website, under the brokerage's name or one of its agents' (Settings → AI blog writer, `/settings/blog-writer`).

**How HQ sells it.** It is module `blog_writer` (feature `content.blog`) in the package builder: put it in a package,
or create an extra of kind _module_ with key `blog_writer` under HQ → Package builder → Extras. Its cost line is AI
credits (100 a month at cost + 70%). Solo agents can have it too (it is in the agent audience).

**Never thin, never duplicate — even with ten brokerages in one city.**

| Rule                                     | How it is enforced                                                                                                                                                                                                           |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Something only this brokerage can say    | Every post must use the owner's own words or their MLS figures for the area. With neither, the night is skipped, no AI is spent, and the owner is told what to add.                                                          |
| No two sites in a city get the same post | Topics and angles are booked city by city across every desk (HQ and every brokerage) for 6 months; the owner's own ideas are booked to them alone.                                                                           |
| Never like anything else in the state    | Each draft is compared (5-word shingles) with everything written in the state by anyone; over 20% alike → rewritten, then skipped. Other brokerages' drafts are compared on our side and never shown to the AI or the owner. |
| Never more than search engines trust     | The same levels as HQ: 3 a week to start; up to 1 a day after 4 healthy weeks (≥ 10 approved, ≥ 80% indexed, no warnings). A brokerage site never goes above 1 a day. Without Search Console numbers it stays at 3 a week.   |
| A person approves every post             | Only owners and staff approve (the broker supervises the brokerage's advertising). An agent with their own writer adds ideas and words and sees how posts are doing.                                                         |
| Honest authorship                        | Each post ends "Written for {brokerage} by {agent} (licence …)"; facts are checked for Fair Housing wording, other company names and "cheap" claims; numbers come only from the data given.                                  |

**Publishing.** Approving puts the post on the website's blog (category "Local guides"); the sitemap and llms.txt
include it at once, and IndexNow is told using the website's own key (`/indexnow-key.txt` on every brokerage
site). Practice mode keeps approved posts as website drafts. Social sharing stays with the brokerage's own tools.

**Nightly.** `content:tenant-desks` at 03:45 (after HQ's `content:desk`, which also moves every desk's level). A desk
whose extra was stopped, or whose agent left, writes nothing.

**Defaults to review:** 100 AI credits a month in the module's cost; "Local guides" category; the byline wording.

## SITE1: website polish (sizes, alignment, prices, legal pages)

- **Type sizes** follow common B2B SaaS practice: 17px body, section headings up to 34px, the main headline up to 52px,
  weights 650–700. Still easy to read for older agents, without looking oversized.
- **Alignment:** section headings, card groups, price cards, simple page heroes and the closing band are centred;
  paragraphs, legal text and questions stay left-aligned for easy reading.
- **Language selector:** a globe "EN ▾" menu in the header (and a language row in the mobile menu) replaces the
  "Español" menu item. The footer keeps a language link.
- **What do you pay today?** Every box has its label on top and its input on the same line, whatever the label's
  length; the card is centred with the result under the boxes.
- **Prices:** the pricing page always shows agent prices too (added automatically when the page has no agents block).
  Every price card lists what it includes, live from HQ › Packages: agent seats, each module (the website reads "IDX
  website with AIlly and 1 MLS board" for brokerages) and AIlly conversations; agent cards list their plan features.
- **Legal pages:** `/privacy` and `/terms` (and `/es/...`) show default text (`Modules\Marketing\LegalDefaults`) until
  HQ publishes its own version in HQ › RightAlly website (`marketing:starter` adds them as editable pages). Linked in
  the centred footer line with ©. The default text must be reviewed by counsel.
- **Favicon:** icon links carry `?v=br1` so browsers and Cloudflare fetch RightAlly's icon instead of an old cached one.

- **rightally.app/login asks for an email.** RightAlly looks it up in the identity directory (emails kept as keyed
  hashes; HQ staff never listed). One brokerage: straight to its log in page with the email filled in, so only the
  password or passkey is left. Several: "Continue to …" for each. None: a plain message to check the spelling or ask
  the broker for an invitation. "Use your brokerage's address instead" keeps the old way.
- **Custom domains work.** A brokerage whose own back-office domain is verified and has its certificate is sent
  there; otherwise its RightAlly address ({slug}.rightally.app), which always works.
- **HQ's log in page** (app.rightally.app/login) is for the RightAlly team. After a failed attempt with an email that
  belongs to a brokerage, it shows "Continue to {brokerage}"; it always links "With a brokerage? Find your log in page".
- Showing the brokerage on screen was chosen for speed (HQ decision); it means anyone can see which brokerage an email
  belongs to. Look-ups are limited to 10 a minute per address.
