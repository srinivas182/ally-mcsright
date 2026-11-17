# Website designs 11–15, plain-words search, MLS Grid and Trestle presets (IX2)

Status: Done. Tracker: C16 Done; C03 and C05 Partly.

## What it does, in plain words

- **Fifteen website designs.** The five new ones:
    - **Villa**: warm Mediterranean, good for bilingual teams.
    - **Loft**: industrial, for downtown condos.
    - **Meadow**: friendly and rounded, for suburbs and first-time buyers.
    - **Mesa**: southwest sand and turquoise.
    - **Ember**: dark and dramatic, for luxury. It stays dark in both light and dark mode.
- **Search in plain words, in English or Spanish.** A visitor types "3 bed homes under 500k in Austin with a pool" or "casa de 3 recámaras hasta 400 mil en San Antonio".
    - The site reads it into the normal filters (beds, baths, price range, square feet, land, rent, multi-family, commercial, city or ZIP).
    - It then sends the visitor to the same shareable filter page the form makes.
    - A line at the top says what it understood, in the visitor's language.
    - It also says honestly what it cannot filter on yet, such as a pool, garage, view or condo, with a link to ask an agent.
- **Rules, not AI.** The search is instant, costs nothing and gives the same answer every time. It reads only facts about the home, never words about people (Fair Housing).
- **MLS Grid and Trestle from Settings.** In HQ, MLS connections, choose the provider and paste the keys.
    - The MLS Grid and Trestle addresses, including the Trestle token address, are filled in automatically.
    - Bridge and direct RESO still ask for the address the MLS gave.
    - Keys stay write-only, encrypted and password-confirmed.

## Market leaders

| Leader            | What they do                                             | Where they fall short                                              | What we do                                                                                                             |
| ----------------- | -------------------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Zillow / Redfin   | Natural-language search ("homes with a pool under 600k") | Consumer portals; agents lose the lead; English-first              | The same plain-words search on the brokerage's own site, in English and Spanish, and the lead stays with the brokerage |
| Lofty (Chime)     | AI search on agent sites                                 | Sometimes guesses filters it doesn't support and shows wrong homes | It says plainly what it understood and what it could not filter on                                                     |
| CINC / Real Geeks | Fixed filter forms                                       | Older buyers struggle with many dropdowns                          | One box: type what you want                                                                                            |
| kvCORE            | Many MLS connectors                                      | Setup needs vendor tickets                                         | HQ pastes keys, and the addresses fill themselves                                                                      |

## SEO and AEO

- Plain-words searches redirect (302) to the normal filter URL. Search engines see only the clean, canonical filter pages. Filtered pages stay `noindex` like before.
- The "we searched for" line carries `lang="es"` for Spanish.
- The search box placeholder shows an example sentence, and the label explains it.

## Easy for agents and buyers aged 50+

- One box, everyday words, no dropdowns needed. The result line repeats back what was understood.

## Settings and review

- Small numbers mean thousands for homes for sale ("under 500" is $500k) and dollars a month for rentals. **Review:** this is a default.
