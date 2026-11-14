# Grow 05 — Unified inbox (Sprint 16A-2)

Status: **Approved November 2026.** Follows grow/04 (lead intake). Leader review: Follow Up Boss, Lofty,
kvCORE/BoldTrail, Sierra Interactive, Ylopo.

| Capability                              | Leaders | RightAlly 16A-2                                                                                                                                   |
| --------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Two-way texting from a brokerage number | ✓       | ✓ each brokerage's own number (HQ), or the platform number with replies matched to the brokerage that texted                                      |
| Email from the CRM with replies back in | ✓       | ✓ a private reply address per conversation; quoted history stripped                                                                               |
| One inbox, filters, assignment          | ✓       | ✓ all / needs reply / unread / mine; owners and staff reassign                                                                                    |
| Templates with merge fields             | ✓       | ✓ personal or shared; unknown fields and Fair Housing wording refused                                                                             |
| STOP / START                            | ✓       | ✓ opt-out recorded at once everywhere; START resumes manual texts (not automated ones)                                                            |
| Compliance check before every send      | partial | ✓ consent or a recent text from them, opt-out, Do Not Call, **8am–9pm in their own time zone (area code)**, Fair Housing; blocked with the reason |
| A reply stops the clock everywhere      | partial | ✓ stops follow-up plans, marks their leads engaged, alerts the agent; the agent's reply answers speed to lead                                     |
| AI reply suggestions                    | some    | ✓ in the agent's voice from the conversation; the agent edits and sends (AI never sends by itself here)                                           |
| Text-for-info sign codes                | add-ons | ✓ built in: details texted back, a lead for the listing agent, no further texts without consent                                                   |
| Missed-reply alerts                     | —       | ✓ nudge after 15 minutes (waking hours), broker risk register after an hour                                                                       |

## Rules

- **Texting:** allowed when the contact gave texting consent, or texted us in the last 30 days (a one-to-one
  reply); never after STOP until they text START or give written consent again; never to Do Not Call unless they
  texted us; only 8am–9pm in every time zone their area code covers (toll-free or unknown: both coasts).
- **Email:** blocked after an unsubscribe. Emails are one-to-one from the agent (name and brokerage) with the
  conversation's reply address.
- **Inbound texts** arrive at one Twilio webhook (signature checked). Unknown senders are ignored unless they
  texted an active sign code. Message bodies are encrypted at rest.
- **Sign codes** need the brokerage's own number (strangers text it first). Codes are 3–12 letters and digits,
  unique per brokerage; STOP/HELP/START words are refused.
- **Visibility:** agents see conversations with contacts they can see (ContactVisibility).

## Data

Cell: `crm_conversations` (one per contact; preview encrypted), `crm_messages` (body encrypted),
`crm_templates`, `crm_sign_codes`, `crm_leads.engaged_at`. Control: `hq_messaging_tenants.sms_number`,
`sms_threads` (who last texted a number from the shared line), `crm_inbound_addresses.kind` (`lead` | `reply`).

## Schedule

`crm:inbox-sweep` every minute.
