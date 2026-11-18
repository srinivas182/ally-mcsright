# 08 — Brand Kit and Brand Voice (Team D with Team C)

Status: **Delivered.** Visual brand kit in Ally Studio (SA1a, specs/grow/13); **Brand Voice, the brand guide reader
and the consistency report in BV1 (2026-11-18)**, see "BV1" below.

## Rule

**Every AI message, video and document is professional and branded to the brokerage, never plain,
and passes the brand and compliance check before it goes out.**

## Capabilities

1. **Upload the brand guide once:** the broker uploads the brand guide PDF and logos; the AI
   extracts colours, fonts, logo versions and usage rules, taglines, required disclaimers and tone of
   voice; the broker reviews and approves.
2. **Brand Voice:** tone rules for all AI writing (formal or warm, words to use and avoid, emojis
   yes or no, sign-off style), in English and Spanish; applied by the AI gateway to every text,
   email, script and lead message.
3. **Professional templates for everything** (Team C): designer-made templates filled with the
   brand: emails (logo, colours, agent photo, signature, licence number, Equal Housing logo),
   seller and buyer guides, lead briefs, flyers, social posts, property websites and reports.
4. **Branded videos** (Team C): List-IQ videos and reels get branded intros and outros, logo
   watermark, captions in brand fonts and colours, approved music style and brand-tone voice-over.
5. **Texts:** follow the Brand Voice, always name the brokerage as state advertising rules require,
   and can link to a branded page or card.
6. **Team and agent branding within the rules:** agent photo, team name and colours only within the
   brokerage's limits; compliance enforces brokerage-name prominence rules.
7. **Quality gate:** brand check (logo, colours, tone, disclaimers), spelling, grammar and compliance
   on every AI output before sending; fixed automatically or flagged; the agent sees a preview.
8. **Brand consistency report:** outputs by agent with brand issues caught.

Builds on settings, branding and domains (specs/core/05) and AI Ad & Flyer Compliance.

## Approved additions (version 1.0; leader review: Canva for Teams, Lofty and BoldTrail marketing)

1. **Brand setup from a brand guide PDF or the brokerage's website**: colours, fonts, logo and tone extracted.
2. **Advertising compliance built in**: the brokerage name and licence number required by state advertising
   rules added automatically to every flyer, post and email.
3. **Brand quality score** on every AI output with one-tap fixes.
4. **Agent sub-brands within the broker's limits** and a consistency report.

## BV1: Brand Voice, brand guide and consistency report (delivered 2026-11-18)

**Market check.** Lofty and BoldTrail let a brokerage upload logos and colours; Jasper and Writer (general AI writing
tools) offer brand voice from uploaded guides. No brokerage platform applies a brand voice to every AI message across
CRM, website, ads and listings, or reports how consistent it is. RightAlly does both.

- **Settings → Brand voice** (owner, or "Run company marketing"):
    - **Upload the brand guide (PDF, up to 20 MB).** The text is read (the file is not kept) and the AI pulls out tone,
      words to use and to avoid, emojis, sign-off, colours, fonts, taglines and disclaimer.
    - **Review and approve.** Nothing applies until the owner reviews it, changes anything and approves it.
    - **Optionally copy into Ally Studio.** On approval, the guide's colours (primary and accent), fonts (from the
      curated list) and disclaimer can go into the brokerage's Studio brand kit.
- **The voice:**
    - tone: warm, friendly, formal or plain;
    - up to 40 words to use and 40 never to use;
    - emojis yes or no;
    - sign-off;
    - Spanish "usted" or "tú";
    - "texts and posts name the brokerage" (state advertising rules).
- **Applied by the AI gateway** to every prompt marked `voice: true`: lead replies and emails, reply drafts, nurture,
  the AI responder, website pages and posts, AIlly, listing copy and descriptions, Studio captions, narration and
  replies, recruiting outreach, networking intros, database check-ins and the client portal concierge. The voice
  never overrides facts or safety rules.
- **Checked on every such answer.** Words to avoid and emojis are checked, and emojis are removed from plain-text
  answers when the brand says none. Nothing is blocked; every check is recorded.
- **Consistency report**, last 30 days: share of pieces on brand, the most common slips, and results by feature.
- New AI feature `ai.brand_guide` (3 credits), with an eval set of 3 cases.
