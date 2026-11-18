# Grow 18 — One-tap written texting consent by email (CN1)

Status: **delivered (Sprint CN1, 2026-11-18)**. Completes A27 (consent builder; personal memory and lead ponds were
11D).

## Why

Agents may only text people who agreed, and automated or AI texts (Lead-IQ, AI voice) need **written** consent with
proof. Most contacts in a brokerage's database never gave it, so automation can't reach them.

## Market check

- Follow Up Boss, Lofty and BoldTrail record an opt-in checkbox on their own forms and let agents tick "has consent"
  by hand. None asks existing contacts for written consent in one tap with stored proof.
- RightAlly: one email, one tap, full evidence, and automated texts unlock for that person.

## How it works

- Contact page → **Email a one-tap consent request**, or Contacts → **Texting consent** for everyone in the agent's
  own list who can be asked (only contacts they may see).
- The email comes in the agent's and brokerage's name: "May Ann text you?". The link opens a plain page (no sign-in,
  never indexed or cached, no referrer) showing the brokerage's name, the last four digits of the number, and the
  exact wording: the standard wording with "from **{Brokerage}** and its agents".
- **Yes, you may text me** records written consent (`web_form`, source `email_link`) with the wording, time, IP,
  browser, the email it was sent to and the number shown; texting and automated texts are allowed from then on.
  **No thanks** is recorded and nothing changes. Answers can't be changed from the link afterwards.
- Never asked: no mobile number or email; unsubscribed from email; replied STOP in the last 30 days; already gave
  written consent; asked in the last 30 days. Links last 30 days. At most 300 emails a day per brokerage.

## Defaults to review

- A tap on a page showing the wording counts as written (electronic) consent under the E-SIGN Act, as it does on web
  forms; attorney to confirm the wording.
- 30-day link life, 30 days between asks, 300 a day per brokerage, 30-day wait after STOP.
