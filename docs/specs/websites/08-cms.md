# Websites 08 — Page builder, media library, blog, menus, redirects, SEO/AEO check (Sprint 17C-3a)

Status: delivered in 17C-3a. Builds on 05 (site engine); follows 04 (SEO and AEO standard).

## Leader review

|                  | Leaders (Luxury Presence, Real Geeks, Lofty, Placester, BoldTrail) | RightAlly 17C-3a                                                                                                          |
| ---------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| Editing          | Block editors; many changes go through the vendor's team           | Brokerage edits every section, sees a live preview of the draft (desktop and phone)                                       |
| Safety           | Edits go live immediately                                          | Drafts autosave privately; publish when ready; last 50 versions, restore in one click                                     |
| Images           | Uploaded as is, often huge                                         | Re-encoded to WebP at 400/800/1600 px (never upscaled), location data removed, alt text required, in-use images protected |
| Blog             | Basic posts                                                        | Markdown with safe rendering, table of contents, BlogPosting data, categories, scheduling, RSS, sitemap and llms.txt      |
| SEO help         | Static checklist or none                                           | Live search and AI-answer score while editing, each check with the exact fix                                              |
| Moving providers | Rankings lost on old URLs                                          | Redirects (single or pasted in bulk), applied only when nothing else answers                                              |

## Pages and sections

- Section types: hero, listings, features, text, faq, cta, contact, testimonials, team, stats, gallery, video,
  neighbourhoods. Every section can be hidden, moved, duplicated or removed (max 40 per page).
- Images are a media id or an https link. Videos are YouTube or Vimeo only, shown as a light placeholder and played
  with privacy-enhanced embeds (youtube-nocookie, Vimeo dnt) only when pressed.
- Neighbourhoods sections show live figures from market reports (spec 06).
- Pages: `/{slug}` or `/{slug}/{slug}`; reserved: homes, market, home-value, blog, agents and the engine's own paths.
  New pages start as drafts; the home page cannot be deleted.
- The builder autosaves the draft (`PUT /settings/website/pages/{page}/draft`), returns the fresh SEO/AEO check and a
  signed preview link; the preview reloads. Publishing copies the draft live and records a version.
- Inline editing inside the preview is not possible across origins (the website runs on its own domain); fields are
  edited beside the preview instead.

## Media library

`MediaLibrary`: JPEG, PNG or WebP up to 10 MB and 60 megapixels; decoded and re-encoded (no scripts or EXIF survive);
stored at `sites/{tenant}/{ulid}-{400|800|1600}.webp` on `SITES_MEDIA_DISK` with immutable caching, optionally behind
`SITES_MEDIA_CDN_URL`. Usage is counted across pages, drafts, posts and the brand kit; used images cannot be deleted.

## Blog

- `site_posts`: Markdown body (100k max), category, cover, author, draft or published with a publish time (scheduling).
- Rendering strips raw HTML and unsafe links, adds heading ids for the table of contents, opens external links with
  `rel="noopener"`, lazy-loads images.
- Public: `/blog` (12 a page), `/blog/category/{slug}`, `/blog/{slug}`, `/blog/feed.xml`; signed previews for drafts.
  Post pages carry BlogPosting (author, dates, word count, section) and breadcrumbs, and a contact form.
- The blog joins the automatic menu once a post is live; posts are listed in the sitemap and llms.txt.

## SEO and AEO check

`SeoCheck::page` and `SeoCheck::post` score 0–100 with weighted checks: title length with the site name (30–65),
description (70–160), one main heading, an answer near the top (20+ words in the first sections; 25–90 word opening
paragraph for posts), FAQ or question headings, length, internal links, image descriptions and indexing. Shown in the
builder and post editor and on the blog list.

## Menus and redirects

- Main and footer menus (10 links each; site paths or https). Without a custom menu, the automatic one is used.
- Redirects: 301 or 302, up to 2,000, from old paths (full links from the old site are accepted) to site paths or
  https. Checked only for 404s (including addresses the router does not recognise), with hit counts.

## Also fixed

- The settings page's preview button used a CSRF header the back office does not provide; it now uses the shared
  JSON helper.
