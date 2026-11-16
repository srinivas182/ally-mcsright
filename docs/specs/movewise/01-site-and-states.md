# Movewise 01 — The site, states and partners (MW1)

Builds on specs/movewise/00 (sections 1, 2, 4.1, 12, 13).

## Hosts

- **The site.** `movewise.host` (movewisehomes.com) is served by `ServeMovewise`, a global middleware that runs before
  website hosts. It uses Movewise's own small router (`MovewiseRouter`), with no session, cookies or CSRF, so pages are
  cacheable at the edge (`public, max-age=60, s-maxage=300`).
- **Redirect domains.** `movewise.redirect_hosts` (movewise.homes, movewiserealestate.com and their www hosts, and
  www.movewisehomes.com) answer with a 301 to the same path on the main host.

## States and partners (HQ → Movewise)

- **Tables.** `movewise_partners` holds the managing broker partners, and `movewise_states` holds TX, LA, MS and AL.
  Both are on the control plane.
- **A state can be switched on only when all three are true:**
    1. **Partner:** its managing broker partner is active, has a signed contract, and is licensed in that state.
    2. **Checklist:** every item of the legal checklist in specs/movewise/00 §12 is signed off.
    3. **Feed:** a live MLS feed covers the state.
- **Switching off.** A state goes off at once when a checklist item is taken back, the partner goes inactive, or the
  partner is removed.
- **Approvals.** Every change needs a password confirmation and is audited.
- **Feeds.** Feeds are MLS authorisations held by the HQ account under the partner's broker MLS id. They are
  requested from the Movewise page and approved in HQ → MLS like any feed. Brokerages never see them, because
  listings are licensed per account. Listing search gained a `states` filter, so Movewise shows only the states that
  are on.

## Public pages

| Path                                                                                         | What                                                                                                                                        |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `/`                                                                                          | Search, the four promises, newest homes, state links, FAQ (FAQPage). "Coming soon" and noindex until a state is on                          |
| `/homes`                                                                                     | Search by place, state, price and beds. Unfiltered results are indexable; filtered and paged results are noindex                            |
| `/homes/{ulid}/{slug}`                                                                       | Photos, facts, the true monthly cost, remarks, the listing brokerage, the MLS disclaimer and logo, and "Last updated …" (RealEstateListing) |
| `/{state}` (e.g. `/texas`)                                                                   | Count, top cities, newest homes and FAQ. The indexable entry points                                                                         |
| `/how-movewise-is-paid`, `/brokers`, `/fair-housing`, `/privacy`, `/terms`, `/accessibility` | Plain-English legal pages                                                                                                                   |
| `/robots.txt`, `/sitemap.xml`, `/sitemaps/{state}.xml`, `/sitemaps/pages.xml`                | One sitemap per live state (up to 50,000 homes) plus the site's pages                                                                       |

- **Footer.** Every page names the broker of record and licence for each live state. While Texas is on it carries
  TREC's **Information About Brokerage Services** (the partner's form) and the **Consumer Protection Notice**.
- **True monthly cost** (`MonthlyCost`). Each line says what it assumes, and all are estimates:
    - loan payment: 10% down, 30-year fixed at the configured rate;
    - property tax and home insurance: typical for the state;
    - mortgage insurance: below 20% down;
    - HOA: "ask the listing agent", because it isn't in the MLS data;
    - utilities: typical.
- **Nothing asked of the visitor yet.** Accounts, AIlly and choosing an agent come in MW2 to MW4.

## Tests

`tests/Feature/Movewise/MovewiseSiteTest.php`
