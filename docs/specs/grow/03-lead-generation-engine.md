# Grow 03 — Lead Generation Engine: buyer and seller funnels

Team E (with C for pages and A for ads). Part of Lead-IQ (specs/grow/02, "Generate leads the brokerage owns").

- **LG1a:** funnels, their pages and capture, and results.
- **LG1b:** monthly home-value reports for sellers, and promoting a funnel with a Meta ad from the agent's own
  account or with RightAlly Boost.

## Best-in-class review (what the leaders do, Nov 2026)

| Product                            | What it does well                                                                               | What we do better                                                                                                                                              |
| ---------------------------------- | ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Ylopo**                          | Buyer and seller funnels on dynamic ads; a "home value" seller funnel; 90-day AI text follow-up | Our funnels sit on the brokerage's own website and domain, feed the same CRM, AI first responder and commission results, and need no separate vendor           |
| **BoldTrail (kvCORE) Lead Engine** | Landing-page templates (home value, new listings, open house); leads tagged by source           | Ours are built in three taps from the agent's own areas, with Fair Housing-safe copy checked before publishing, and results down to commission                 |
| **Lofty**                          | "Smart" landing pages and IDX registration funnels                                              | Consent is captured on every funnel with the exact TCPA wording; a lead never gets automated texts without it                                                  |
| **CINC**                           | Buyer search funnels that force registration                                                    | We don't force registration: the visitor gets value first (homes or a value range), then a soft ask, which converts better and is friendlier to people over 50 |
| **Homebot**                        | Monthly home-value emails that keep sellers engaged                                             | Built in (LG1b), with the agent's brand and real nearby listings, instead of a separate subscription                                                           |

## Funnels (LG1a)

A **funnel** is one page with one job, plus what happens after the visitor submits. It lives at
`/go/{slug}` on the brokerage's website (or the agent's site). These pages aren't indexed by search
engines, because they are ad landing pages. There are four templates, chosen in three taps: template, area,
publish.

| Template                           | The visitor gets                                                   | We capture                                              | After capture                                                                       |
| ---------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| **What's my home worth?** (seller) | A price range for their home from nearby comparable homes, at once | Address, ZIP, beds, size, name, email, phone, consent   | A seller lead (intent "sell") with the estimate; LG1b adds the monthly value report |
| **New listings in {area}** (buyer) | Today's matching homes, then daily alerts                          | Area, price range, beds, name, email, phone, consent    | A buyer lead and a saved search with daily alerts, owned by the funnel's agent      |
| **First home in {area}** (buyer)   | Plain steps to a first home, plus homes at their budget            | Budget, timeline, pre-approval yes/no, contact, consent | A buyer lead with timeline and pre-approval, and a saved search at the budget       |
| **Moving to {area}** (relocation)  | An area guide (market facts from MLS) and homes                    | Move date, price range, contact, consent                | A buyer lead tagged "Relocation", and a saved search                                |

**Rules:**

- **Who:** owners and staff make brokerage funnels, and leads route by the brokerage's lead rules. Agents make
  their own funnels, and those leads are theirs, the same as on an agent website.
- **Value first:** the funnel shows the homes or the value range on the page. The contact step is a short, clear
  ask ("Send me these homes as they come up"). Nothing hides content behind a wall.
- **Consent:** the TCPA texting consent box, with the brokerage's wording, is shown unticked. Without it, the lead
  is emailed and called by a person, never texted automatically.
- **Fair Housing:** templates describe homes and places, never people. The headline and intro can be edited, and
  the edits are checked with the same Fair Housing check as ads before the funnel is published.
- **Every lead goes through the normal intake.** That means:
    - a named source ("Funnel: New listings in Fondren"), routing and speed to lead;
    - the AI first responder, when consent allows;
    - duplicates merged;
    - first touch and UTM attribution, so ads are credited.
- **Spam:** the website's existing protections apply (hidden field, minimum time on page, rate limit).
- **Results:** per funnel, visits, leads and conversion rate, then appointments, deals and commission through the
  existing lead-to-commission results. The funnel list sorts by commission, not by clicks.
- **SEO and AEO:** the pages aren't indexed (`noindex`), are fast, are server-rendered and work well on mobile.
  They carry structured data for the area where it helps (Place) without competing with the site's real market
  pages.

## Acceptance (LG1a)

- An agent creates "New listings in Fondren under $400k" in three taps. It is live at `/go/…` on their site, shows
  matching homes, and on submit creates a lead routed to them, a saved search with daily alerts, and the consent
  record.
- The home-value funnel shows a range from nearby comparable homes and creates a seller lead with the estimate.
- Edited copy that names who should live there is refused before publishing.
- Funnel results show visits, leads and conversion. Leads keep the funnel as their source for commission
  reporting.
- A paused funnel shows a friendly "no longer available" page and links to the website's home search.

## Monthly home-value report (LG1b)

- Starts when a homeowner asks a home-value funnel and gives an email. The funnel page says so before they submit
  ("We'll email you an updated range once a month. Stop any time with one click.").
- Once a month, from 8am brokerage time (`sites:home-reports`, hourly): the updated range for their home, how it
  moved since last month, and three homes for sale in their ZIP with MLS attribution. Sent as their agent (the
  contact's owner, else the funnel's agent, else the first owner), replies go to the inbox.
- Never after an email opt-out. One-click stop (signed link, `List-Unsubscribe` and one-click POST). Skipped for a
  month when there is neither a range nor homes nearby. Each send is on the contact's timeline.
- The range comes from homes for sale, so the email says asking prices can differ from sale prices and the agent
  confirms with recent sales. Equal Housing Opportunity on every email.

## Promote a funnel (LG1b)

- "Promote with an ad" on each live funnel opens the ad builder with the funnel chosen. Pay from the person's own
  Meta ad account or with RightAlly Boost credits, exactly as listing ads (budget agreement, stop-loss, results).
- The ad links to the funnel's page with `utm_source=facebook`, `utm_medium=paid_social`,
  `utm_campaign=rightally-{ad}`, so leads carry the ad and reach commission reports.
- The picture is a home for sale in the funnel's area; the ad reaches everyone within the chosen radius (at least
  15 miles) of the middle of those homes. Housing ads use no other targeting.
- Agents promote their own funnels; owners and staff any funnel. Headline and text pass the Fair Housing check.

## Acceptance (LG1b)

- A home-value lead gets a report a month later with the new range and the change; none after stop or opt-out.
- A funnel ad publishes with no listing, links to `/go/{slug}` with the UTM tags and reaches the funnel's area.
