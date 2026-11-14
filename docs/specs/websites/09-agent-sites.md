# Websites 09 — Agent websites, brand kit, agent directory, landing pages (Sprint 17C-3b)

Status: delivered in 17C-3b. Builds on 05 (site engine), 07 (visitor journey) and 08 (CMS).

## Leader review

|                | Leaders (kvCORE/BoldTrail, Real Geeks, Lofty, Luxury Presence)    | RightAlly 17C-3b                                                                                                                |
| -------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Agent sites    | Separate product or add-on; often a copy the broker cannot govern | Built in, created from the agent's profile and roster credentials in one click                                                  |
| Agent listings | Manual selection or office feed                                   | "My listings" from the MLS by the agent's own MLS id                                                                            |
| Compliance     | Agents can remove brokerage disclosures                           | Brokerage name, licence, Equal Housing and MLS disclaimers always on; the agent's state licence shown automatically             |
| Brand control  | All or nothing                                                    | Brand kit: the broker decides whether agents may edit sections, choose a design, change colours or use their own domain         |
| Leads          | Often go to the broker first                                      | Leads from an agent's website go to that agent (unless the person already has one), with the visitor journey                    |
| SEO            | Agent sites on separate domains compete with the brokerage        | /agents/{name} on the brokerage domain, RealEstateAgent data linked to the brokerage; directory as ItemList; agents in llms.txt |
| Landing pages  | Separate tool                                                     | Any page can be a landing page: no menu, hidden from search by default, leads reported per landing page with UTMs               |

## Agent websites

- `sites.kind = 'agent'`, `user_id`, unique `slug` (from the name). Created from Settings → My website (agents) when
  the brokerage website exists and agent websites are on; a draft until the agent publishes.
- Starter home page: hero (name, tagline, home search), "my listings" (listings section with `mine`), about, testimonials,
  contact. Pages use the same sections, builder, drafts and versions as the brokerage site.
- Served at `/agents/{slug}` on the brokerage's addresses and, if allowed, on the agent's own domain
  (`tenant_domains.site_ulid`). On an agent domain the agent's pages are at the root and every other page (search,
  listings, market reports, blog, home value) carries the agent's header and routes leads to the agent.
- `SiteContext` (per request, reset by `ServeWebsite` at the start and end of every website request) says which
  website is being served; `Chrome` then shows the agent's name, photo, phone and licence, the brokerage name beneath,
  and "Brokered by" with the brokerage's details in the footer.
- Profile: name, tagline, story, phone, email, areas, languages, photo (agent's media library). Licence and MLS ids come
  from the roster through Core's `AgentCredentials` (Roster implements it).
- Structured data: RealEstateAgent with licence (PropertyValue), areas, languages, parentOrganization → the brokerage.

## Brand kit

`sites.settings.agents` on the brokerage website: `enabled`, `sections`, `template`, `colours`, `domain` (defaults:
on, sections on, the rest off). Owners and staff can take any agent website offline.

## Directory

`/agents`: live websites of active agents, filter by name/area and language, ItemList structured data; listed in the
sitemap (with each agent's pages) and llms.txt.

## Leads

`SiteLeads::capture` assigns to the agent from the request's agent website or a verified `agent` form field (a live
agent website only). Contact forms use the "Agent website" source; sign-ups and home-value requests keep their sources.

## Landing pages

`site_pages.layout = 'landing'`: no menu or account button, compliance footer kept, `noindex` by default, starter
hero + benefits + form. Leads are recorded under the source "Landing page: {title}"; UTMs come from the landing URL.

## Also fixed

- Website context could carry over between requests in long-running workers; now reset on every website request.
