# Billing 02: pricing credits from real costs (Sprint 18A-2a)

The goal is for HQ to know, before selling a package, that it makes money. That holds even if a customer uses
every included credit, whichever AI model serves the request.

## Inputs HQ controls

| Where                                | What                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| HQ → AI gateway → Providers          | Add Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini). Each starts switched off until its data terms are confirmed. Paste each API key with **API key**: RightAlly checks it with the provider, then saves it encrypted and never shows it again (AI1). A key in the server environment (`ANTHROPIC_API_KEY` …) is only a fallback |
| HQ → AI gateway → Models             | Add models and their prices: $ per 1M input, output and cached tokens, or $ per audio minute for speech to text. Saving stamps the "prices checked" date                                                                                                                                                                                 |
| HQ → RightAlly credits → rules       | What one credit sells for; target margin (default 60%); minimum margin (default 40%); usage budget, the share of a package's price that included usage may cost (default 20%)                                                                                                                                                            |
| HQ → RightAlly credits → each action | Credits per use, and the planning estimate (tokens in and out, audio seconds)                                                                                                                                                                                                                                                            |
| HQ → RightAlly credits → other costs | Texts, MMS, calls, map loads (with the free tier) and email, all per unit, plus hosting per brokerage per month. Seeded from the vendors' US price pages in November 2026                                                                                                                                                                |

## Cost per action

For each action:

- **Real cost (last 30 days):**
    - average cost per successful use
    - 90th-percentile cost per successful use

    Both are scaled up by the cost of failed attempts and retries, spread over the successes.

- **Estimate:** the planning size of one use, priced on the **costliest model the action's routes can use** (the
  primary model or any fallback). This covers the case where the gateway falls back to a pricier model.
- **Planning cost:** the higher of the real 90th percentile and the estimate.
- **Suggested credits:** planning cost ÷ (credit value × (1 − target margin)), rounded up, with a minimum of 1.
- **Margin** at the current price: (credits × value − planning cost) ÷ (credits × value). It shows in red below the
  minimum margin.
- **Tokens per credit:** what one credit's value buys on the primary model, at the action's real (or estimated)
  input/output mix.

"Apply all suggested prices" and "Fix those below minimum" update the rate card in one step. Each change is
audited.

Speech to text is now costed **per audio minute**. OpenAI-compatible providers return
`usage: {type: "duration", seconds}` for minute-priced models. Token-priced models are still costed by tokens.

## Packages

For each package, per brokerage per month, assuming every included credit is used:

- **Typical cost:** included credits × the typical cost per credit. That is the real mix of credits used across
  all brokerages over the last 30 days, each at its planning cost per credit. Before there is any use, it is the
  plain average of the actions.
- **Worst cost:** included credits × the costliest planning cost per credit among the actions.
- **Fixed costs:** the monthly items in the cost list.
- **Margins:** (price − cost − fixed) ÷ price, shown for both the typical and the worst case.
- **Suggested included credits:** price × usage budget ÷ typical cost per credit, rounded down to a tidy number.

Saving included credits that put the **typical** margin below the minimum needs a reason of at least 10
characters. The reason is stored on the package and written to the audit log at warning level.

## Switching on

- **"Set suggested credits and switch on"** (password confirmation) gives every active paid package without
  credits its suggested amount, then turns enforcement on. It refuses if it can't price credits, for example
  when no models have prices yet.
- **Switching on alone** is refused while any active paid package has no included credits.

## Margin watch

`credits:margin-watch` runs daily at 06:10. If any action's real average cost over the last 7 days leaves less
than the minimum margin, it emails every active HQ staff member with the action, its real cost, its margin and
the suggested credits. Typical causes are a provider raising prices, traffic moving to a fallback model, or
larger uses.

## Next: allowances (18A-2b, see 03-allowances.md)

- Text and call allowances per package, with cost hints; going over uses credits.
- Map loads counted per brokerage, with a fair-use allowance per package, HQ alerts, and interactive maps
  that load only when a visitor opens or scrolls to them (static image otherwise).
- These feed the package planner as usage costs.

## Tests

`tests/Feature/Billing/CreditEconomicsTest.php` covers:

- providers and models
- per-minute speech to text
- the fallback-based estimate and suggested credits
- the 90th percentile with retries
- applying suggested prices
- package margins and the required reason
- going live, and refusing to go live
- the margin watch
- the cost list
