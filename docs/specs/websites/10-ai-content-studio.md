# Websites 10 — AI content studio (Sprint 17C-4)

Status: delivered in 17C-4. Builds on 06 (market figures), 08 (CMS, SEO/AEO check), 09 (agent websites).

## Leader review

|                | Leaders (Luxury Presence, Lofty, Real Geeks) | RightAlly 17C-4                                                                                                          |
| -------------- | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Content        | Generic text, same for every brokerage       | Grounded in the brokerage's live MLS figures for the chosen area, dated "as of"                                          |
| Accuracy       | Model may invent prices, rates, statistics   | Code removes any sentence whose prices or percentages were not given, and reports it                                     |
| Compliance     | None                                         | Fair Housing wording check removes risky sentences and reports them                                                      |
| SEO / AEO      | None                                         | Must reach our own score of 80 (answer first, question headings, FAQ, links); one automatic rewrite with the exact fixes |
| Publishing     | Often straight to live                       | Always a draft; the broker reviews and publishes                                                                         |
| Market updates | Manual                                       | Optional monthly drafts per area on the 1st, emailed for review                                                          |

## Features (AI gateway feature → credit action)

- `sites.ai_post` → `ai.site_post`: blog post from a brief (topic, readers, tone, length, area figures, keywords).
- `sites.ai_page` → `ai.site_page`: page from a brief, as sections (answer-first text, parts, FAQ, call to action).
- `sites.ai_assist` → `ai.site_assist`: rewrite (improve, shorter, longer), FAQ from content, search title and
  description, agent bio from the profile. Agents only on their own website.
- `sites.ai_market` → `ai.site_market`: the monthly market update drafts (`sites:market-updates`, 1st of the month).

Credits are charged through the gateway's `CreditMeter`. Note: the RightAlly Credits ledger (core/07) is not built
yet, so `CreditMeter` is still the null meter and no credits are deducted until it is.

## Guards (in code, after the model answers)

- Numbers: allowed figures are those in the market record, the brief and the source text. Money and percentages must
  match exactly ("$450,000" = "450,000" = "450k"); plain small counts (up to 12) and nearby years are allowed. Any
  sentence with another figure is removed.
- Fair Housing: sentences matching `Crm\FairHousing` flags are removed.
- What was removed is shown to the editor ("Written with AI: score 86 …") so nothing changes silently.
- The prompts send only the brokerage's own data; source text is passed as untrusted content; the gateway applies
  redaction and output policy.

## Also fixed

- The post check's "answer first" test read the whole post as one paragraph (rendered HTML has no blank lines); it now
  checks the first paragraph.
