# Connect 19 — Social posting (C7d-1)

Sprint **C7d-1** (Team A with D). Part of the C7 block (specs/connect/15). Google Ads and YouTube are C7d-2;
Canva is C7d-3.

## The apps

Three one-click apps replace the "Social posting" roadmap tile:

| App                              | Sign-in                                       | Where posts go                                                                         | Permissions asked                                                                                                                       |
| -------------------------------- | --------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **Facebook Pages and Instagram** | Meta (the same Meta app as Facebook Lead Ads) | Each Facebook Page the person manages, and the Instagram business account linked to it | `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`, `business_management` |
| **LinkedIn**                     | LinkedIn (RightAlly's LinkedIn app)           | The person's own LinkedIn profile                                                      | `openid`, `profile`, `w_member_social`                                                                                                  |
| **Google Business Profile**      | Google (the shared Google sign-in app)        | Each business location the person manages                                              | `business.manage`                                                                                                                       |

- Agents connect their own; owners and staff may also connect the brokerage's (company Pages, the office's Google
  profile). Agents only post to their own connections; owners and staff post to theirs and the brokerage's.
- Page tokens are encrypted with the connection and never shown. The list of Pages, Instagram accounts and
  locations refreshes once a day.

## Grow → Social posts

- Write the post once, choose where it goes (every connected Page, Instagram account, LinkedIn profile and
  Google location), **post now or schedule** (up to 60 days ahead, in the brokerage's time zone).
- **Start from a listing** (optional): the listing's photo, its page on the brokerage website and a draft line are
  filled in.
- Each place gets its own link tag: `utm_source={facebook|instagram|linkedin|google}`, `utm_medium=social`,
  `utm_campaign=rightally-{post}`, so visits and leads count in reports.
- Results per place (posted with a link to the post, or the reason it failed); scheduled posts can be cancelled.
- The assistant can do the same: "post my new listing to Facebook and LinkedIn tomorrow at 9" shows a preview
  card and posts after **Do it** (skill `social_post`).

## Rules

- **Fair Housing:** the text passes the Fair Housing check (channel `ads`); flagged wording is refused with the
  words to change.
- **Brokerage named:** every post ends with the agent's name and the brokerage (state advertising rules), unless
  the text already names the brokerage.
- **Listings:** only listings the person may advertise (the same rule as Ads: agents their own, owners and staff
  the brokerage's agents'); the post carries the MLS's attribution line and links to the listing's page on the
  brokerage website. Listing photos are sent as JPEG (Instagram needs it).
- **Per place:** Instagram needs a photo (its captions can't hold links, so the link is written as text);
  Google allows 1,500 characters and LinkedIn 3,000; Facebook takes the link as a link preview.
- Each post goes out once per place; a retry never posts twice.

## For HQ (before go-live)

- **Meta:** add `pages_manage_posts`, `instagram_basic`, `instagram_content_publish` and `business_management`
  to the Meta app and request Advanced Access (App Review).
- **LinkedIn:** create a LinkedIn app for RightAlly (company page required), add the products "Sign In with
  LinkedIn using OpenID Connect" and "Share on LinkedIn", add the return address, paste the client ID and secret.
  Posting to company pages needs LinkedIn's Community Management API (a separate review), planned later.
- **Google:** enable the Business Profile APIs (My Business Account Management, Business Information, and the
  v4 local posts API, which Google grants on request) on the shared Google project and add the
  `business.manage` scope to the consent screen (Google verification).

## Acceptance (C7d-1)

- Posting now and scheduling reach every chosen place once, with the right link tags; a failure in one place does
  not stop the others and is shown with its reason.
- Fair Housing wording is refused; the brokerage is named; only the person's own listings, with MLS attribution.
- Agents can't post to the brokerage's Pages; Instagram without a photo is refused.
