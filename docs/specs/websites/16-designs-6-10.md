# Website designs 6–10 and feed freshness (IX1)

Status: Done. Tracker: C10.

## What it does, in plain words

- Brokerages now choose from **ten** website designs. The five new ones:
    - **Estate**: light luxury with an ivory background, editorial serif headings and fine lines. For luxury and architectural homes.
    - **Nordic**: minimal and calm, with lots of white space and square corners. For modern homes and design-led teams.
    - **Heritage**: classic and trusted, with a traditional serif, navy and brass. For established brokerages and historic areas.
    - **Summit**: outdoorsy, with forest colours and sturdy type. For mountain, lake and acreage markets.
    - **Atrium**: architectural and bright, with frosted glass and big photos. For new construction and city teams.
- Every design has a dark-mode version, large readable text (body text at least 16px), strong contrast and the same markup. Switching designs never loses content or SEO.
- **Freshness, part one: visitors.** If any result on the home-search page comes from an MLS feed that is behind the MLS's own freshness rule, the page shows a calm note: "Listing updates are running a little late right now." The note links to Contact. Listing pages already had their own note.
- **Freshness, part two: owners.** If a feed is more than an hour behind, the owners of every brokerage licensed on that MLS get a plain-words email: what is late, that visitors are told, that support is on it, and nothing for them to do. They get at most one every six hours. HQ still gets its technical alert after 30 minutes, at most hourly.

## Market leaders

| Leader                          | What they do                             | Where they fall short                                                                  | What we do                                                                                      |
| ------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Luxury Presence                 | Award-winning custom luxury designs      | Custom work costs thousands and takes weeks; designs are locked to the vendor's studio | Five luxury-grade designs included, each switchable in one click with the brokerage's brand kit |
| Real Geeks / Sierra Interactive | Solid IDX templates that convert leads   | Templates look dated; few choices; weak dark mode                                      | Ten modern designs, dark mode built in, SEO and AEO identical across all of them                |
| kvCORE (BoldTrail)              | Brokerage-wide sites with several themes | Feed delays are silent; agents learn from upset clients                                | Visitors see an honest "updates are delayed" note, and owners get a plain email                 |
| Agent Image                     | Premium hand-built sites                 | Expensive; every change needs a ticket                                                 | Owners change the design themselves in Settings, Website                                        |

## SEO and AEO

- The design changes the CSS only. Headings, schema (RealEstateListing, SearchAction, BreadcrumbList), canonical URLs and llms pages stay identical.
- Google Fonts load with `display=swap`. The stylesheet version is bumped (`site.css?v=6`) so caches refresh.
- The freshness note is plain text with `role="status"`. It is not hidden from crawlers, so it stays honest.

## Easy for agents aged 50+

- Every design is picked from a visual list with a one-line description of who it suits.
- Email wording has no jargon such as "feed lag" or "RESO".

## Settings and review

- Freshness thresholds use the MLS rules profile (`freshness_minutes`). Owner alerts start at 60 minutes, with a six-hour quiet window. **Review:** these are defaults.
