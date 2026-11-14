# Websites 05 — Site engine (Sprint 17C-1)

Status: delivered in 17C-1. Builds on 02 (MLS ingestion), follows 04 (SEO and AEO standard).

## What it is

Every brokerage gets a public website that is part of RightAlly: listings from its MLS, enquiry forms that become CRM
leads, and pages built to rank on Google and be quoted by AI assistants (ChatGPT, Perplexity, Claude, Gemini).

## Leader review

|                  | Leaders (Luxury Presence, Real Geeks, Lofty, BoldTrail, Sierra) | RightAlly 17C-1                                                         |
| ---------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Hosting          | Separate product, separate login, often a separate bill         | Part of the back office; leads go straight into the CRM                 |
| Rendering        | Mix of SPA and server pages; IDX often in iframes or subdomains | Complete server-rendered HTML on the brokerage's own domain; no iframes |
| AI search        | Robots files often block AI crawlers by default; no llms.txt    | AI crawlers welcomed (opt-out), llms.txt, FAQ structured data           |
| Lead attribution | UTM only                                                        | Channel incl. "AI search" from the first-touch referrer (Sprint 16A)    |
| Spam             | reCAPTCHA (slows pages, tracks visitors)                        | Honeypot, encrypted time trap, per-IP rate limit; no third-party script |
| Credit           | Fixed or none                                                   | "Powered by RightAlly" set by HQ, per-brokerage white label             |

## Hosts

- Website hosts are `tenant_domains` rows with `purpose = 'website'`: the included `{slug}.{SITES_PLATFORM_DOMAIN}` and
  custom domains (pending until DNS is verified; CNAME to `SITES_CNAME_TARGET`).
- `ServeWebsite` (global middleware, appended) answers website hosts with the site's own router: no session, no cookies,
  no CSRF, so pages are cacheable at the edge. Back-office routes are unreachable on website hosts; the back office
  rejects website hosts (`EnsureTenantWorkspace`). A website domain is never a brokerage's primary (back-office) domain.
- Suspended or unknown brokerages get a neutral 404.

## Pages

- `sites` (one brokerage site per tenant in 17C-1; agent sites in 17C-3) and `site_pages` (sections, draft sections).
- Section types: hero, listings, features, text, faq, cta, contact. Sections are cleaned on save and again on render.
- Templates: **Aurum** (dark luxury, gold, Cormorant Garamond) and **Modern** (light, Inter). Brand colours are CSS
  variables. Mobile first, respects reduced motion, skip link, visible focus.
- Starter site: Home (hero, new listings, why us, FAQ, call to action), About, Contact.
- Draft sites show a coming-soon page (noindex, robots disallow all). Signed preview links last one hour.

## SEO and AEO (spec 04)

- Title, description, canonical, Open Graph and Twitter tags on every page.
- JSON-LD graph: `RealEstateAgent` (name, contact, address, area served, social profiles), `WebSite`, `WebPage`,
  `BreadcrumbList`, `FAQPage` for FAQ sections.
- `robots.txt` names each AI crawler (Allow, or Disallow when the brokerage opts out) and links the sitemap.
- `sitemap.xml` lists published, indexable pages. `llms.txt` summarises the brokerage and its pages.
- Pages send `Cache-Control: public, s-maxage=SITES_CACHE_SECONDS, stale-while-revalidate=SITES_STALE_SECONDS`.

## Leads

Forms post to `/_lead` on the website host → `LeadIntake` with a "Website" form source: TCPA consent wording, UTM
fields, first-touch referrer and landing page (so AI search is its own channel), routing and speed-to-lead as in 16A.
Bots are dropped quietly (honeypot, encrypted time trap under 3 seconds or forged, 10 per minute per IP).

## Powered by RightAlly

HQ → Websites sets the wording and https link, platform-wide on/off, and a per-brokerage override (white label).
Brokers and agents cannot remove it. The same setting drives the credit on the AIlly assistant (17D-1).

## Permissions

Settings → Website: owners, staff and roles with "Manage CRM settings". HQ → Websites: HQ staff. All changes audited.

## Also fixed

Public CRM endpoints (`api/leads`, hosted forms, the connect webhook, alert unsubscribe) now resolve the brokerage from
the host themselves; previously they relied on the web group's tenant resolution, which they do not use.

## Next

17C-2 IDX search with Mapbox, listing / neighbourhood / valuation pages, visitor accounts and the visitor journey.
