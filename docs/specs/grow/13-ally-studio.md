# Grow 13 — Ally Studio: your AI social team

Sprints **SA1a, SA1b, SA2a, SA2b, SA3, SA4, SA5** (Team A with D). Builds on social posting (specs/connect/19, C7d-1),
paid apps (specs/connect/14) and RightAlly Credits. Runs before C7d-2 (Google Ads and YouTube ads).

**Promise:** a marketing agency for an agent or a brokerage, for the price of a plan. Every post looks designed
for that agent (their photo, their brand, their market), never like a template; it goes out at the right time,
checked for Fair Housing and MLS rules, and shows the leads it brought.

Names: **Ally Studio** (Grow → Ally Studio). It sits beside **Ask Ally** (the public website assistant) and **Your
AI Assistant** (the back office), which can drive Studio too ("make a just-listed post for Pine Rd and schedule it").

## Packages (HQ sets everything)

Three monthly add-ons, bought like any paid app (specs/connect/14: credits or saved card, brokerage or agent pays,
monthly renewal, cancel at month end, "included in package X"):

| Package (example)              | Who buys        | Includes per month (HQ edits)                                                                                          |
| ------------------------------ | --------------- | ---------------------------------------------------------------------------------------------------------------------- |
| **Studio Essentials** ~$39     | Agent           | Brand kit, every collection, 30 designed posts, 4 Reels/Shorts, calendar and Autopilot                                 |
| **Studio Luxury** ~$79         | Agent           | 60 posts, 12 Reels/Shorts, 2 YouTube listing tours, launch campaign per listing, Spanish, 10 photo enhancements, voice |
| **Studio Brokerage** ~$X/agent | Brokerage owner | Luxury for every active agent, plus the brokerage's Pages, recruiting posts, approval rules and team reporting         |

- **Allowances** reset each paid month. Past the allowance, each item costs credits from the rate card:
  `studio.post`, `studio.reel`, `studio.tour`, `studio.enhance`, `studio.voice` (HQ sets credits). People see
  "18 of 30 posts used", and can buy credits or upgrade in one tap.
- **HQ cost and margin:** for each item HQ enters what it costs RightAlly (AI caption tokens, rendering, music
  licence, enhancement or voice vendor) and sees price, cost and margin against its margin rules, per package
  (allowance × cost vs package price) and per extra item.
- A brokerage package covers every agent of the brokerage; an agent's own package covers only them. The better
  of the two applies.

## The brand kit

- Agent: headshot (background removed automatically, kept transparent), name, title, licence line, phone, tagline,
  social handles, languages (English, Spanish), and a favourite collection.
- Brokerage: logo (light and dark), brand colours, fonts (from a curated list), required disclaimer, Equal Housing
  logo, and whether agents may change the collection or colours ("brand lock").
- Agents fill in what is missing in two minutes; the kit is used in every design, video and caption.

## Designer collections and the studio

**Photo-first library (SA1e), replacing SA1c/SA1d:** benchmarked against how the leading luxury brokerages and
template studios post today. The photograph is the hero; the cover carries one short line ("Just listed", the street)
and a small logo; the price, rooms and agent move to the last carousel slide and the caption. Two type families at
most: modern serifs (Instrument Serif, Fraunces, Newsreader, Cormorant) with clean grotesks (Inter Tight, Archivo
Expanded, Bricolage, Space Grotesk, Unbounded, Manrope). Sixteen styles, each a signature cover and two alternates:
Gallery, Glass (frosted card), Passe-partout (ivory mat), Monograph (oversized headline), Swiss Modern, Diptych,
Cinema, Arch, Noir, Collage, Bold Block (brand-colour field, agent cut-out), Index, Horizon, Seal (rotating badge),
Wide and Heritage. Earlier style keys map to their nearest new style, so saved kits and favourites keep working.

- **Carousel by default for listings:** cover, up to four more photographs (bleed or framed, numbered), then a
  details slide (price, rooms, a last look at the home, the agent and "Book a tour"). All slides 4:5, downloadable as
  one ZIP; posting carousels to Facebook, Instagram and LinkedIn follows in SA1f.
- **Agent-forward:** the headshot sits on the cover where the style allows (Gallery, Glass, Noir), the cut-out stands
  in Bold Block, and every details slide closes on the agent. Text and agent posts use a statement layout with the
  cut-out portrait in an arch.
- **Photo grade:** every photo gets a gentle grade (warm, clean, rich or bright, by style) and a fine sharpen.
  Heavier enhancement (upscaling, virtual twilight, sky) is SA5a, labelled "Virtually enhanced".
- **Stories** keep words clear of the app's top and bottom bars; the brokerage and Equal Housing are on every image.

- **Collections** designed by RightAlly (not stock), refreshed each season: _Luxury Noir_ (black, gold, serif),
  _Modern_ (white space, sans), _Coastal_ (warm, light), _Estate_ (classic, framed), plus seasonal sets.
- Each collection has layouts for: just listed, open house, price improvement, under contract, just sold, coming
  to market (only with the MLS-entry rule below), market update, testimonial, tip, holiday, milestone, meet the
  agent, recruiting. Each in square (1:1), portrait (4:5) and story (9:16).
- Designs are HTML and CSS rendered to high-quality JPEG on RightAlly's render workers (headless Chromium), so
  typography and layout are print-grade.
- **AI art director** checks every design before anyone sees it: text fits and is readable (contrast), photos are
  sharp and large enough, the headshot and logo are present, brand colours are right, nothing is cut off. Weak
  listing photos are skipped; a design that fails is not offered.
- The studio page: choose a moment (or a listing), see three designs from different layouts, change the words or
  photo, download, or post (to every connected place, specs/connect/19). One design also becomes the postcard
  (C7c), the email header (Mailchimp, C7a) and the website banner.

## Autopilot (SA2)

- **Moments** create posts automatically: new listing, price change, open house scheduled, under contract, sold
  (MLS); deal closed (Transactions; only with the client's yes, address hidden if they prefer); market update
  (weekly, from the brokerage's own MLS data and buyer demand); new 5-star review; holidays; work anniversary and
  milestones; recruiting (brokerage).
- **Listing launch campaign** (Luxury and Brokerage): a 7-day plan for each new listing: teaser, just listed,
  feature (kitchen, view), the neighbourhood, open house, Reel/Short, then status updates.
- **Captions** in the agent's voice (learned from their own past posts, with their permission) in English and,
  where chosen, Spanish; local hashtags (city, neighbourhood, ZIP); a clear call to action; the right length per
  network; Fair Housing checked; name, brokerage and MLS attribution added.
- **Calendar:** month view of everything planned and posted; drag to move; best-time slots per network.
- **Modes:** _Approve first_ (default: a morning note "3 posts ready", approve from the phone or Your AI
  Assistant) or _Autopilot_ (posts go out by themselves). Brokers can require approval for all agents, set posts per
  day and required disclaimers.

## Reels, Shorts and tours (SA3)

**SA3a (built): Reels from listing designs.** Studio → Create → "Make a Reel" turns a listing design into a 9:16
video in the same style: the cover (4 s), each further photo (3 s) and the details card with the agent (4.5 s),
each with a slow camera move (in, then out), 0.6 s crossfades, and a track from RightAlly's licensed music library
faded in and out and levelled (EBU R128); about 13 to 18 seconds. ffmpeg on the render workers makes it (H.264, AAC,
fast start). Making one is free; it counts as one "Reel or Short" when first downloaded or posted. Studio → Reels
shows each with its music, download (MP4) and "Post it".

- **Music:** HQ adds each track with the licence RightAlly holds (title, artist, mood, licence, link) and can turn it
  off; agents pick a mood (Luxury, Calm, Upbeat, Cinematic) or a track; without tracks a Reel has a quiet sound track.
- **Posting:** Instagram gets a Reel (`REELS` container, shared to the feed, published once Instagram has
  processed it); a Facebook Page gets the video; LinkedIn and Google get the cover image. "Post the cover only"
  switches back to a photo.

**SA3b (built): YouTube, tours and Autopilot Reels.**

- **YouTube app** (the shared Google sign-in, `youtube.upload` only): Reels go up as Shorts and tours as videos,
  through the resumable upload, with the post's first line as the title and the rest (with the listing link) as the
  description. Uploads are private until RightAlly's Google project passes YouTube's API audit
  (`STUDIO_YOUTUBE_PRIVACY=public` after). Only videos go to YouTube.
- **YouTube listing tours** (Studio → Reels → Make a tour): 1920x1080 in the person's style — an intro with the
  address and price (6 s), up to six rooms of ten seconds each with the MLS photo caption as a lower-third chapter
  title, and an outro with the agent and "Book a private tour" (8 s); cinematic music. The post starts with the
  chapters ("0:05 Kitchen"), which YouTube shows as chapters (SEO and AEO). Tours count as "YouTube listing tours";
  they go to YouTube and Facebook (Instagram takes Reels).
- **Autopilot Reels:** a new listing's launch also gets a Reel, made from its launch design and queued a day after
  the listing post, when the person allows it and the package has Reels left (credits only with "Use credits for
  extra posts"). It waits for approval like any post.
- **Legibility on photos:** text over photographs sits on a soft progressive blur that frosts and deepens the photo
  toward the text edge (no boxes or bands), proofed on the brightest interiors.

## Results (SA4)

- Per post: reach, views, clicks (network insights where allowed) and, from the link tags, visits, leads,
  appointments and commission.
- The weekly **Studio report** in the daily brief: "your 9 posts brought 4 leads and 1 appointment".
- Studio learns which layouts, times and moments bring leads for each agent and plans more of them.

## Pro tools (SA5)

- **Photo enhancement (SA5a, moved up):** Studio → Enhance photos: choose a listing's photos; AI brightens and
  balances them, straightens verticals, brings out a blue sky and a green lawn, and can turn the cover into a virtual
  twilight. Each enhanced photo (and each twilight) is one "photo enhancement" from the package, then credits past
  the allowance; a failed one is given back. The MLS originals never change. Designs lead with the twilight and use
  the enhanced photos, and every image and caption made with them says "Photos virtually enhanced". When HQ switches
  upscaling on, enhanced photos are doubled in size so crops and Stories stay sharp. Autopilot enhances a new
  listing's best three photos (and a twilight when the allowance allows) before its posts are designed, from the
  allowance only, waiting at most 30 minutes. Every enhanced photo is labelled "Virtually
  enhanced" on the design and in the caption, the original is kept, and MLS photo rules are followed.
- **Voice narration:** Reels and tours narrated in the agent's own voice, only with their recorded consent; the
  voice is theirs alone and is deleted when they withdraw consent.
- **Comment and message replies:** Studio drafts replies to comments and messages on the agent's posts (Meta
  permissions `pages_manage_engagement`, `instagram_manage_comments`, `instagram_manage_messages`); interested
  people become leads with consent recorded; replies are Fair Housing checked; Approve first by default; anything
  sensitive goes to the agent.

## What Studio runs on (all on RightAlly's accounts)

Agents and brokerages only buy a package: every outside service is RightAlly's own account, switched on and paid
by HQ, with its cost per item in the cost list so package margins stay right (HQ → Ally Studio shows each item's
"made with", the services and whether each is on).

| Service                  | Used for                                        | HQ app           | Cost key                      |
| ------------------------ | ----------------------------------------------- | ---------------- | ----------------------------- |
| RightAlly AI gateway     | Captions, Spanish, hashtags, voice learning     | (AI routes)      | `studio.post` (caption share) |
| RightAlly render workers | Designs, carousels, postcards (headless Chrome) | `STUDIO_CHROME`  | `studio.post`                 |
| remove.bg                | Headshot cut-out                                | `studio_cutout`  | `studio.cutout`               |
| Autoenhance.ai           | Photo enhancement, sky, lawn, virtual twilight  | `studio_enhance` | `studio.enhance`              |
| Replicate (Real-ESRGAN)  | Upscaling enhanced photos 2x                    | `studio_upscale` | `studio.upscale` (in enhance) |
| Licensed music library   | Reels, Shorts and tours (SA3)                   | (SA3)            | `studio.reel`, `studio.tour`  |
| AI voice                 | Narration in the agent's consented voice (SA5)  | (SA5)            | `studio.voice`                |

**Autopilot stays inside the package:** it posts at the person's times and posts-per-week, and when the month's
designed posts are used it waits for the renewal, unless the person turns on "Use credits for extra posts".

## Rules (every sprint)

- Only listings the person may advertise (same rule as Ads); MLS attribution on listing posts.
- "Coming to market" only for listings that will be in the MLS within one business day (NAR Clear Cooperation).
- Closings only with the client's yes. No school ratings or "family" wording (Fair Housing).
- Music only from the licensed library; no copied news; photos only from the listing or the agent.
- Every post, caption and video is Fair Housing checked and carries the agent's name and brokerage.

## Sprints

| Sprint   | What                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **SA1a** | Packages (three add-ons), allowances and usage, extra-item credits, HQ cost and margin, brand kit with automatic headshot cut-out, brokerage brand lock                                                                                                                                                                                                                                                                                                                                                                                        |
| **SA1b** | Render workers, the four collections and their layouts in three sizes, AI art director, the studio page, download and post                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **SA1c** | Premium design system v2: editorial type (Cormorant Garamond, Bodoni Moda, Cinzel, Jost, Pinyon Script, Italiana), metallic accents from the brand colour, hairlines, film grain, photo grading; signature layouts per collection (cover, cinema, gallery, frame, arch, masthead, split, statement); premium postcards; public font route for printers                                                                                                                                                                                         |
| **SA1d** | Style library: 18 styles in six families (classic luxury, modern minimal, coastal and resort, editorial and urban, bold, country and land), each with its own type, grounds, ornaments, photo grading and three listing layouts (new: mono, journal, blueprint, colour field); finishes (gold, champagne, platinum, rose gold, bronze, azure, terracotta, emerald, brand colour, black and white); "see your listing in every style" previews; style match; favourites rotated by Autopilot; brokerage-allowed styles when the brand is locked |
| **SA1e** | Photo-first redesign: 16 styles benchmarked against the market leaders (one line on the cover, details on the last slide), new type system, carousel (cover, photos, details with the agent) and ZIP download, story safe zones, agent-forward covers, per-style photo grade and sharpen, old style keys mapped                                                                                                                                                                                                                                |
| **SA1f** | Carousel posting: Facebook multi-photo, Instagram carousel, LinkedIn multi-image                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **SA5a** | Photo enhancement (moved up): Enhance photos page, Autoenhance.ai (sky, lawn, virtual twilight) and Replicate upscaling on RightAlly's accounts, allowance then credits with give-back on failure, designs and captions labelled "Photos virtually enhanced", Autopilot enhances new listings from the allowance and stays inside the package (credits for extra posts only when allowed), HQ sees what each item is made with and the services behind Studio                                                                                  |
| **SA2a** | Autopilot: listing moments (new, coming soon, price improvement, under contract, sold), weekly market update, holidays; AI captions (Spanish, hashtags, Fair Housing); good times; Approve first and Autopilot; daily brief; Your AI Assistant skills `studio_posts` and `studio_approve`                                                                                                                                                                                                                                                      |
| **SA2b** | Calendar (drag to move), listing launch campaign, captions in the agent's voice, closings, reviews, milestones and recruiting moments, broker approval rules                                                                                                                                                                                                                                                                                                                                                                                   |
| **SA3a** | Reels from listing designs (ffmpeg: camera moves, crossfades, licensed music), HQ music library with licences, Studio → Reels, Instagram Reels and Facebook Page videos                                                                                                                                                                                                                                                                                                                                                                        |
| **SA3b** | YouTube app (Shorts and videos, private until the API audit), YouTube listing tours with chapters, Autopilot Reels for new listings, progressive-blur legibility on photos                                                                                                                                                                                                                                                                                                                                                                     |
| **SA4**  | Results, Studio report, learning                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **SA5**  | Photo enhancement, voice narration, comment and message replies                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |

## For HQ (before go-live)

- Prices, allowances and credits for each package and item; cost per item.
- Render workers with Chromium and ffmpeg; a background-removal key (remove.bg or equal).
    - SA1b: designs render on the queue workers with headless Chrome (`STUDIO_CHROME`, default
      `/usr/bin/google-chrome`; `STUDIO_CHROME_NO_SANDBOX=true` only inside an isolated container that runs Chrome as
      root). Fonts are bundled (SIL OFL, `modules/Ads/resources/fonts`), so workers need no font service. One design
      set is nine renders (about 1 s each); size the workers for the expected design volume, and cap sets per person
      per day with `STUDIO_DAILY_SETS` (default 40). SA1e: a listing set is now up to 24 renders (three carousels).
- SA5a: an Autoenhance.ai account with API access (Connect setup → Photo enhancement), and optionally a Replicate
  token plus a tested Real-ESRGAN version id (Photo upscaling); enter both real costs per photo in the cost list
  (`studio.enhance`, `studio.upscale`) and set each package's enhancement allowance under HQ → Ally Studio.
- Music library licences (add each track under HQ → Ally Studio with its licence); ffmpeg on the render workers
  (`STUDIO_FFMPEG`); YouTube API audit; Meta review for engagement permissions (SA5); voice vendor (SA5).
