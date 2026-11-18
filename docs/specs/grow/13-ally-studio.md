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

- **Numbers per post, read daily for 30 days** (`ads:social-results`, 04:17): Facebook — reactions, comments,
  shares, unique views (`post_total_media_view_unique`, Meta's metric since June 2026) and clicks; Instagram — reach,
  views, likes, comments, shares and saves (carousels share no insights, so their likes and comments come from the
  post). LinkedIn, Google and YouTube don't share numbers on the permissions Studio asks for. A metric Meta stops
  offering counts as zero rather than failing.
- **Leads:** people who came in through a post's link (its `utm_campaign`, as for ads).
- **Studio → Results:** a month at a glance (posts, people reached, interactions, engagement rate, clicks, leads), by
  format (single photo, carousel, Reel, tour), by style and by time of day, the top five posts, and what Autopilot
  has learned. Brokerage packages with team reporting add the whole team with a line per agent. Your AI Assistant
  skill `studio_results` answers "how are my posts doing?".
- **Learning:** once 8 of a person's posts (last 90 days) have numbers, Autopilot leads with the style that earns the
  most interactions per person reached (still trying another every third post) and posts first at their best time.
- **Monthly Studio report** by email on the 1st (`ads:studio-reports`): posts, reach, interactions, leads, the best
  format and the top post, with a link to the full results.

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
- **Voice narration (SA5b):** a voice-over on any finished Reel or tour.
    - **Voices.** Everyone can use a professional stock AI voice (ElevenLabs premade voices licensed for commercial
      use). An agent can also add **their own voice**:
        - only the agent can make it, on Studio → My voice, after confirming their password;
        - they read a consent statement aloud (in their name, naming the brokerage, RightAlly and ElevenLabs, the only
          use and the deletion rules) plus a short sample, then type their full name as a signature and tick to agree;
        - the recording is used once to make the voice and is never kept. Only the vendor's voice id (encrypted) and the
          consent record are stored: the exact text, version, time, IP, and a SHA-256 hash of the recording.
    - **Who can use it.** Nobody else can see or use an agent's own voice. It narrates only videos they approve, never
      calls or texts.
    - **Deletion.** The voice is deleted at the vendor and here when the agent deletes it, when they leave (account
      no longer active), or after 12 months unused (daily `ads:studio-voices`). These are the retention and
      destruction rules state biometric laws expect (Illinois BIPA, Texas CUBI).
    - **Script.** AI writes it to fit the video (about 2.2 words a second), using only the listing's facts. It is
      Fair Housing checked; wording that fails is never offered. The agent can edit it, and the edited text is checked
      again.
    - **Audio.** The voice starts 0.7 s in, with the music lowered to 22% beneath it, then loudness-normalised. The
      narrated video gets a new file name, so caches never serve the old one, and the old file is deleted.
    - **Counting and failures.** Each narration counts as one "Voice narration" (allowance, then credits). If the
      voice service fails, the video stays as it was and the narration goes back to the allowance.
    - **Disclosure.** YouTube uploads of a video in the agent's own AI voice set `status.containsSyntheticMedia`.
    - **HQ.** The app is `studio_voice` (ElevenLabs API key). Before go-live, HQ confirms ElevenLabs' current terms
      for cloning through the API on RightAlly's plan.
- **Comment and message replies (SA5c):** Studio → Replies, for packages with the `replies` feature.
    - **Turning it on.** Replies are off until the agent turns them on. Their Pages are then subscribed to `feed` and
      `messages`, added to the fields the Page already sends to RightAlly's app, never replacing them, so Lead Ads keeps
      working. Disconnecting Lead Ads likewise removes only `leadgen`.
    - **How webhooks arrive.** Meta sends all Page and Instagram webhooks for the app to the one Lead Ads address.
      `FacebookLeads::route` splits them:
        - lead forms stay with Lead Ads;
        - Facebook comments (`feed`, item comment), Instagram comments (`comments`), and Messenger and Instagram
          messages go to the Facebook Pages connection (parts carry `app: facebook_pages`), routed by Page and Instagram
          account id.
    - **Drafting.** Each comment or message is kept once (Meta retries). The agent's own comments and echoes are
      ignored. AI classifies it (question, interest, praise, complaint, spam, other), flags sensitive ones, and drafts
      a reply of at most 2–3 sentences.
    - **What happens next.**
        - Spam is ignored.
        - Complaints, money, offers, legal, private details, protected groups, or anything the AI is unsure of go to the
          agent ("Needs you"). They are never sent automatically.
        - A draft that fails the Fair Housing check is never offered.
        - "Approve first" (the default): every draft waits for the agent. "Auto": thanks and simple questions go out on
          their own.
        - One email for a burst of new ones, with no message text in it.
    - **Sending.**
        - Facebook comments: `/{comment}/comments`. Instagram comments: `/{comment}/replies`.
        - Messages: `/{page}/messages` with `messaging_type: RESPONSE`, only within Meta's 24-hour window. Later ones are
          marked expired, to answer in the app.
        - The agent's own words are Fair Housing checked too.
    - **Leads.** A message with an email or phone number becomes a lead (source "Facebook and Instagram messages"),
      assigned to the agent and credited to the post. The sender's name comes from Meta's profile for that Page.
      Texting consent is never assumed. Contact details in public comments aren't taken.
    - **Meta review.** New scopes: `pages_manage_metadata`, `pages_manage_engagement`, `pages_read_user_content`,
      `pages_messaging`, `instagram_manage_comments`, `instagram_manage_messages`. People connected before must
      connect again to allow them.

- **Canva (C7d-3):** "Edit in Canva" on any Studio design, and "Design it in Canva" for a listing, once the agent has
  connected Canva (app `canva`, Canva Connect, OAuth with PKCE).
    - **Going to Canva.** The starting image (the Studio design, or the listing's cover) goes to the agent's Canva
      uploads, with up to four listing photos, the brokerage logo and their headshot. A 1080×1350 design starts with the
      first image in it and opens in the Canva editor, with `correlation_state` set to the session id.
    - **Coming back.** Pressing Return in Canva goes to RightAlly's one return address (`/connect/canva/return` on the
      sign-in host). The `correlation_jwt` is checked against Canva's published keys: the audience is our client id and
      the type is `rti`. The control table `canva_returns` then sends the agent back to the brokerage website the session
      started on.
    - **The result.** There, only the session's owner can bring the design in. Every page is exported as PNG and becomes
      a Studio design (variant `canva`): page one is the post, later pages are carousel slides, with square and story
      sizes made from page one. It can be posted, advertised and downloaded like any design. It is not redrawn as a Reel.
    - **Failures.** If an upload or export fails, the reason is shown, and "Bring it back" tries again.

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

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

| **SA4** | Results: daily numbers from Facebook and Instagram, leads from post links, Studio → Results by format, style and time, team view, Autopilot learning, monthly report, `studio_results` skill |
| **SA5** | Voice narration, comment and message replies (photo enhancement moved up to SA5a) |

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

## Branded intros and outros, and video renderers (MK3, C22)

**Intros and outros.** In the brand kit, **Start and end my videos with my brand** adds two frames to every video: Reels, YouTube tours, and List-IQ listing videos (`Video\Bumpers`).

- The intro (2.5 seconds) shows the logo, the agent's name, the brokerage and the tagline, on the brand colour.
- The outro (3.5 seconds) shows "Ask me anything", the name, phone, email, brokerage, licence line and disclaimer.
- Both are drawn at the video's own size. YouTube chapters move to stay in step.
- The setting is off until the agent turns it on.

**Renderers.** Outside services → Video intros, outros and reels chooses how videos are made (`Video\ServiceVideoMaker`):

- **RightAlly's own renderer** (recommended; ffmpeg on our workers, no extra fee).
- **Shotstack:** Test mode uses its free, watermarked "stage" environment.
- **Creatomate.**

With either provider, the frames, music and voice-over sit on our public storage only while the render runs and are deleted afterwards. If the provider fails or takes more than 5 minutes, our own renderer makes the video, so nothing is lost.

Tests: `tests/Feature/Outreach/MarketingAutomationTest.php`.
