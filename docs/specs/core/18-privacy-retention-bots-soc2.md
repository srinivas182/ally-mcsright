# 18 — Privacy requests, data retention, bot protection and SOC 2 evidence (TR1)

Status: **delivered (Sprint TR1, 2026-11-18)**. Completes A23 (SOC 2 controls, data export and deletion,
retention; the brokerage's full export is DS1, spec 12) and A31 (AIlly cost dashboard and what-if calculator,
allowances per package, bot protection and rate limits).

## Market check

- Lofty, BoldTrail and Follow Up Boss publish privacy policies with an email address for requests; requests are
  handled by hand. None gives the brokerage a request inbox that finds the person's records and does the work.
- Parity: SOC 2 Type II is table stakes for enterprise brokerages; competitors show a badge. RightAlly adds a live
  evidence page HQ can share with the auditor.
- Add-ons: email-confirmed requests (nobody can ask about someone else), 45-day answer dates with reminders,
  transaction files automatically kept, retention the owner chooses per kind of data, bot checks without picture
  puzzles.

## Privacy requests

- Every brokerage website's privacy page has a request form: see, delete, correct, or stop selling or sharing.
  Honeypot, bot check and 5 an hour per address. We email a link (valid 7 days, single use) to prove the request is
  from the owner of the email address; nothing reaches the brokerage until it is confirmed.
- Confirmed requests are due 45 days later (the longest US state laws allow without an extension); owners and people
  with "Answer privacy requests" are emailed at once and again when a request is due within a week or late
  (nightly).
- Settings → Privacy requests shows what RightAlly found (contacts by any email or the phone's last 10 digits;
  website accounts by email) and does the work with one button (password confirmed):
    - **See**: a copy of every record (details, notes and activity, tasks, messages, consent history, saved homes,
      pages viewed), emailed as a signed link valid 7 days, built fresh when opened.
    - **Delete**: names and contact details removed; notes, tasks, messages, memories, AI summaries, lead records,
      website account, browsing, saved homes and AIlly chats deleted. Kept: contacts in a deal (transaction files the
      brokerage must keep) and the consent history (proof texts already sent had permission), with the phone, IP and
      browser removed from it. The answer email says what was kept and why.
    - **Stop selling or sharing**: RightAlly never sells personal information; marketing email and texts are switched
      off and recorded.
    - **Correct**: the person handling it records what they changed.
    - **Decline**: with a reason of at least a sentence, emailed to the person.
- Modules register sources, holds and retention kinds in `Modules\Core\Privacy\PrivacyRegistry`.

## Retention

Settings → Privacy requests → "How long data is kept" (owner, password confirmed), run nightly
(`core:privacy-daily`, 03:25):

| Kind                                                              | Suggested | Minimum |
| ----------------------------------------------------------------- | --------- | ------- |
| Website browsing                                                  | 13 months | 3       |
| AIlly chats                                                       | 24 months | 6       |
| Leads that went cold (no activity, never in a deal, not opted in) | Keep      | 24      |

Deals, money records and the audit log are never removed here (the audit log has its own 7–25 year rule).

## Bot protection

`Modules\Core\Security\BotProtection` uses the "Bot protection" outside service (spec 16): Turnstile (recommended),
hCaptcha or reCAPTCHA; HQ's account, or the brokerage's own on its website. Applied to the online signup, the Health
Check, website contact forms (every page and listing), home value, landing pages and privacy requests, on top of the
honeypot, timing check and rate limits. A wrong or missing answer fails; if the provider cannot be reached the form
goes through and the outage is logged (a lead is never lost to a provider outage).

## AIlly what-if (HQ → AI cost)

Change brokerages, conversations, replies, the reply cost (pre-filled from real AIlly use), package price,
conversations included and the credit per extra conversation: see the cost per conversation, per brokerage, for all
brokerages, the share of the package price (a warning above 20%), extra conversations and the credits they bring.

## SOC 2 evidence (HQ → Security controls)

Ten controls (CC6.1, CC6.2, CC6.3, CC6.6, CC6.7, CC7.2, A1.2, CC8.1, CC9.2, P4.2), each with what RightAlly does,
live evidence (counts only) and Met / Needs attention / Written process; downloadable as CSV for the auditor.

## Defaults to review

- 45 days to answer; confirmation links 7 days; copy links 7 days.
- Retention suggestions above; consent history kept after deletion; contacts in deals kept.
- Bot checks let forms through when the provider is down.
