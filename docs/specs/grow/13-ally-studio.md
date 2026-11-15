# Grow 13 — Ally Studio: your AI social team

Sprints **SA1a, SA1b, SA2, SA3, SA4, SA5** (Team A with D). Builds on social posting (specs/connect/19, C7d-1),
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

- 15–30 second vertical videos from listing photos (slow camera motion), licensed music (HQ's library, each track
  with its licence), burned-in captions, the agent's opening and closing cards. Posted to Instagram Reels, Facebook
  Reels and **YouTube Shorts**.
- **YouTube listing tours:** 60–90 second landscape videos with chapters (Kitchen, Primary suite, Yard), the
  listing link in the description; searchable for years (SEO and AEO).
- New app **YouTube** (Google sign-in, upload permission only). HQ: YouTube API compliance audit, so uploads can be
  public.

## Results (SA4)

- Per post: reach, views, clicks (network insights where allowed) and, from the link tags, visits, leads,
  appointments and commission.
- The weekly **Studio report** in the daily brief: "your 9 posts brought 4 leads and 1 appointment".
- Studio learns which layouts, times and moments bring leads for each agent and plans more of them.

## Pro tools (SA5)

- **Photo enhancement:** virtual twilight and sky replacement. Every enhanced photo is labelled "Virtually
  enhanced" on the design and in the caption, the original is kept, and MLS photo rules are followed.
- **Voice narration:** Reels and tours narrated in the agent's own voice, only with their recorded consent; the
  voice is theirs alone and is deleted when they withdraw consent.
- **Comment and message replies:** Studio drafts replies to comments and messages on the agent's posts (Meta
  permissions `pages_manage_engagement`, `instagram_manage_comments`, `instagram_manage_messages`); interested
  people become leads with consent recorded; replies are Fair Housing checked; Approve first by default; anything
  sensitive goes to the agent.

## Rules (every sprint)

- Only listings the person may advertise (same rule as Ads); MLS attribution on listing posts.
- "Coming to market" only for listings that will be in the MLS within one business day (NAR Clear Cooperation).
- Closings only with the client's yes. No school ratings or "family" wording (Fair Housing).
- Music only from the licensed library; no copied news; photos only from the listing or the agent.
- Every post, caption and video is Fair Housing checked and carries the agent's name and brokerage.

## Sprints

| Sprint   | What                                                                                                                                                    |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **SA1a** | Packages (three add-ons), allowances and usage, extra-item credits, HQ cost and margin, brand kit with automatic headshot cut-out, brokerage brand lock |
| **SA1b** | Render workers, the four collections and their layouts in three sizes, AI art director, the studio page, download and post                              |
| **SA2**  | Moments, launch campaign, captions (voice, Spanish, hashtags), calendar, Approve first and Autopilot, broker rules, recruiting                          |
| **SA3**  | Reels, Shorts and tours, music library, YouTube app                                                                                                     |
| **SA4**  | Results, Studio report, learning                                                                                                                        |
| **SA5**  | Photo enhancement, voice narration, comment and message replies                                                                                         |

## For HQ (before go-live)

- Prices, allowances and credits for each package and item; cost per item.
- Render workers with Chromium and ffmpeg; a background-removal key (remove.bg or equal).
- Music library licences; YouTube API audit; Meta review for engagement permissions (SA5); voice vendor (SA5).
