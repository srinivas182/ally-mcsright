# Websites 04 — SEO and AEO standard (applies to every public page)

Status: **Approved November 2026 — a standing rule for every sprint.** Every page or feature that
the public, search engines or AI answer engines can reach is built to this standard, and each sprint's
spec says how it applies.

AEO (answer engine optimisation) means being the source that ChatGPT, Perplexity, Gemini, Copilot,
Claude and Google AI Overviews quote and link to. Leader review: Luxury Presence, Sierra Interactive,
Real Geeks and Placester cover classic SEO; none of them report leads from AI answer engines or
publish machine-readable answers for them. RightAlly does both.

## Rules

1. **Server-rendered HTML.** Public pages are complete HTML on the first response (Blade or Inertia SSR);
   most AI crawlers do not run JavaScript. Interactive parts are added on top.
2. **Structured data (JSON-LD)** on every public page: `RealEstateAgent`/`Organization` for the
   brokerage and agents, `Residence`/`Offer` for listings (within MLS display rules), `FAQPage` for
   question-and-answer blocks, `BreadcrumbList`, `Event` for open houses, `Article` for posts.
3. **Answer-first content.** Pages that answer a question open with a direct 40–60 word answer, then
   detail; facts (prices, dates, numbers) are in text, not only in images.
4. **Entity consistency.** Brokerage name, address, phone and licence number identical everywhere
   (site, schema, Google Business Profile), with `sameAs` links to official profiles.
5. **Crawl controls.** `sitemap.xml` (with `lastmod`), `robots.txt` that allows the answer-engine
   crawlers (GPTBot, OAI-SearchBot, PerplexityBot, ClaudeBot, Google-Extended) unless the brokerage
   opts out, `llms.txt` summarising the brokerage and its key pages, canonical URLs, `noindex` on
   utility pages (forms, thank-you pages, private links).
6. **Speed and accessibility.** Core Web Vitals in the green on mobile (LCP < 2.5s, INP < 200ms,
   CLS < 0.1); WCAG 2.2 AA; works without JavaScript where possible.
7. **Freshness.** Visible "updated" dates and `dateModified` in schema; market numbers refreshed
   automatically.
8. **Measure it.** Every lead records its channel, with AI answer engines as their own channel
   (grow/04), so brokerages see what SEO and AEO bring in.

## Applied so far

- Sprint 16A: hosted lead forms are server-rendered with `RealEstateAgent` JSON-LD, canonical and
  `noindex` (utility pages); the embed is a plain HTML form; leads are attributed to `ai_search`,
  `organic`, `paid` and other channels.
