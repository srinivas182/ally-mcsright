# Listings 04 — Showings and the seller's view (SH1)

Plan item C15 (showings and client portal, ShowingTime connection), in the `ListIq` module, with the seller's view in the existing deal portal (specs/ai/02).

## Leader review

| Leader           | What they do                               | Where they fall short                                               |
| ---------------- | ------------------------------------------ | ------------------------------------------------------------------- |
| ShowingTime      | Showing scheduling and feedback (MLS-wide) | Separate system; feedback chased by hand; sellers phone for updates |
| BrokerBay        | Showings and offers                        | Separate login; partner API only                                    |
| Aligned Showings | Showing scheduling                         | Same                                                                |

RightAlly adds: showing requests on the listing's own page, approval in one tap (or automatic in showing hours), access sent only to approved showings and kept encrypted, automatic feedback with one reminder, and the seller seeing showings, feedback, page views, scans, open-house visitors, the agent's note and plain advice in their deal portal.

ShowingTime, BrokerBay and Aligned Showings share data only with approved partners; they stay in Apps as "coming soon" until RightAlly is accepted (specs/connect/21). Brokerages using them keep doing so; RightAlly's own showings work alongside.

## Requests and approval (`Modules\ListIq\Showings`)

- On the listing page (`/listing/{ulid}`, only once it's in the MLS): name, brokerage, email, phone, date and time. Showings are 30 minutes, start 8am–8pm the brokerage's time, from half an hour to 60 days ahead, and can't overlap a requested or approved one.
- **I approve each one** (default): the listing agent gets an email and approves or declines on the listing (with an optional reason). **Approve automatically**: in-hours requests are confirmed at once.
- Approval emails the buyer's agent the time and the **access instructions**, which are stored encrypted and never sent before approval.

## Feedback

`listiq:showing-feedback` (every 15 minutes): an hour after each approved showing ends, the buyer's agent gets a link (random, 40 characters, only its hash kept) to three questions: interest (1–5), the price (good value, about right, too high) and comments. One reminder a day later, with a new link. Answers go to the agent and the seller without the buyer's agent's name.

## The seller's view

`Core\Deals\ListingActivity` (implemented by List-IQ, null otherwise) adds a "How your home is doing" section for sellers in the deal portal, in English or Spanish: upcoming showings, showings and feedback counts, average interest, price opinions, recent comments, page views, QR scans, open-house visitors, the agent's dated note, and plain advice when there have been no showings for two weeks or most feedback says the price is high (never a price: that's the agent's conversation).

## Brief

The listing agent's brief: "N showing requests to answer" and "No showings at … this week" for listings in the MLS a week or more.

## Tests

`tests/Feature/ListIq/ShowingsTest.php`.
