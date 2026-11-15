# Connect 16 — GoHighLevel campaigns (C7a-2)

Sprint **C7a-2** (Team A with D). Approved 2026-11-15, ahead of C7b. Builds on the GoHighLevel import
(specs/connect/07) and the email marketing rules (specs/connect/15).

## The split

GoHighLevel's public API adds a contact to an existing workflow or campaign (and to tags); it doesn't create
workflows or campaigns. So:

- **In GoHighLevel**, the broker or agent builds the campaign once (emails, texts, waits), as today.
- **In RightAlly**, they choose who goes in and where, under **Settings → GoHighLevel campaigns** (linked from the
  app): a tag or a smart list, into a workflow, a campaign, or a **trigger tag** (e.g. "RA: Spring sellers") that
  starts a tag-triggered GoHighLevel automation.
- RightAlly adds the matches now, then **new matches every hour** (on the GoHighLevel sync), each person **once per
  enrolment**, 100 a run. Pause and resume any time.

## Rules

- **Consent per channel** (specs/grow/11): "Emails" sends only people with an email address who haven't opted out
  of email; "Texts" only people with written texting consent and proof; "Either" anyone who may get one of them.
  Those left out are counted on the page.
- **Visibility**: the person who set it up must be able to see each contact; a brokerage connection never sends
  agents' personal contacts; an agent's connection only their own. If that person loses access, or the smart list
  is removed, the enrolment pauses with the reason.
- **Their GoHighLevel contact**: the one already linked by the import, or added with GoHighLevel's upsert (by
  email or phone) and remembered.
- **"Do not disturb" comes back**: GoHighLevel's DND (all channels, or per channel: SMS, Email, Call) on an
  imported contact becomes an opt-out here, recorded with GoHighLevel as the source.
- Every enrolment is audited; refusals are shown on the enrolment.

## Token

Still the sub-account's Private Integration token: to use campaigns it also needs **Edit Contacts**, **View
Workflows** and **View Campaigns** (the setup guide says so). One-click connect through a GoHighLevel Marketplace
app follows once HQ registers the app (optional, at marketplace.gohighlevel.com).

## Acceptance (C7a-2)

- Tagged contacts who may get email go into the chosen workflow once each; opted-out people never do.
- A text campaign takes only people with written consent; a trigger tag is added in GoHighLevel.
- DND in GoHighLevel stops that channel here.
