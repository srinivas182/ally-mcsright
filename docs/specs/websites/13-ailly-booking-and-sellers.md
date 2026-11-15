# 13 — AIlly: real booking, seller mode, returning visitors, nudge, languages (Sprint 17D-3)

## Why

Chat leads convert best when the visitor leaves with a time on the calendar. Most website chats stop at "an agent
will call you". AIlly now books a real call, showing or listing appointment in an agent's open hours, sends a
calendar invite, and puts the booking in the agent's tasks.

## Agent availability (CRM)

- Settings → **My availability** (agents, owners, staff): weekly hours (up to three ranges a day), call length
  (15/30/45/60 min), minimum notice (0–72 h). Off until the agent turns it on, so nobody gets booked on hours they
  never chose.
- Times use the brokerage's time zone.
- Open slots are the agent's hours, minus the notice, minus open CRM tasks of type call (one slot long),
  showing or other (an hour each).
- Showings and listing appointments last 60 min.
- A booking creates a **high-priority task** for the agent, linked to the lead's contact, with a reminder 30 min
  before.
- The slot is checked twice: before the lead is saved, and again when booking. A taken time returns 422 with fresh
  slots, and no lead is saved.
- Later: busy times from Google/Outlook calendars plug into the same check.

## Who is booked

- An agent's website (their own domain, or `/agents/{slug}` on the brokerage address — the widget sends the
  public slug): that agent only.
- The brokerage website: the **booking team** chosen in Settings → AIlly (agents and owners). With no team chosen,
  everyone who turned on website booking is used. When several agents share a time, it goes to the one with the
  fewest upcoming open tasks.
- The lead goes to the booked agent (`SiteLeads::capture(..., $assignTo)`).

## Endpoints (site host, JSON, rate limited)

- `POST /_ailly/slots {kind: call|showing|listing}` returns up to 8 slots: `{start ISO, label, agent ulid,
  agent_name}`.
- `POST /_ailly/contact` adds `want: listing`, `slot`, `slot_agent`. The response includes
  `booked: "Wednesday, June 3 at 12:00 PM CDT"`.
- Confirmation email: `BookingConfirmedMail` with an `.ics` attachment (UTC times, a random UID). It contains
  only the agent's name and phone and the website name.

## Seller mode

- The prompt (v3) asks sellers for ZIP, bedrooms and approximate sq ft (no street address), then returns
  `valuation`.
- `Valuation::estimate` (comparable homes for sale) gives a range, and the widget shows a "Likely range" card that
  says an agent confirms it with recent sales.
- The next step becomes `book_listing`.
- The conversation outcome `listing` ranks above `call`.
- The lead intent is `sell`.

## Returning visitors

- On `start`, a visitor with 2 or more visits whose last search was over an hour ago is greeted with how many homes
  are new or reduced for that search since then (`ListingSearch` `since`).
- The greeting includes a link to the search. It is worked out in code, with no AI or credits.

## Nudge

- On `/homes/{ulid}` after 40 seconds, a small bubble appears, at most once per tab session.
- It never opens the chat by itself.
- The broker can switch it off (`nudge`, on by default).
- It respects reduced motion.

## Languages

- The widget's UI is in Spanish when `navigator.language` starts with `es`.
- The prompt tells AIlly to reply in the visitor's language. Search and valuation data stay plain.
- The closing brief for agents stays in English.

## Results

Settings → AIlly shows a "Listing appointments" tile and filter, and "Showings booked".

## Tests

`tests/Feature/Crm/AvailabilityTest.php`, `tests/Feature/Sites/AillyBookingTest.php`.
