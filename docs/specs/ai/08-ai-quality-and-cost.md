# AI quality and cost (AQ1, AQ2)

Status: AQ1 done. Tracker: D19 Partly (AQ2 adds cost routing, caching and the cost dashboard).

## What it does, in plain words (AQ1: quality)

- **Every AI feature has a test set.**
    - Each set holds realistic cases, for example: a lead asking for a showing, a buyer asking whether an area is "safe", a Spanish-speaking seller, or a listing note that says "perfect for a young family".
    - Each case says what a good answer must do and must never do, and includes a reviewed example answer.
    - The sets live next to each prompt, at `modules/{Module}/evals/{area}/{name}.json`.
- **Checks on every answer**, in plain words:
    - **Fair Housing**: no "family-friendly", "safe neighborhood", "perfect for families", "master bedroom" and similar.
    - **No promises**: no "guaranteed to", "will definitely sell", "risk-free".
    - **No made-up dollar amounts**: every price must appear in what the AI was given.
    - **No made-up contact details**: every phone number, email or link must appear in what the AI was given.
    - **Per case**: required fields, length limits (for example a text under 300 characters), required and forbidden wording, exact extracted values (names, email, ZIP), and the reply language (Spanish).
- **Every pull request** checks the sets themselves, with no model calls:
    - each set has at least 3 cases;
    - every `{{placeholder}}` the prompt uses is supplied;
    - every example answer passes every check.
- **Prompt lock**: changing a tested prompt without raising its version fails the build. The person who changes it runs `php artisan ai:eval --lock`, and HQ re-tests the new version.
- **No new untested prompts**: `modules/AI/evals.untested.txt` lists the prompts that don't have a test set yet. The build fails if a new prompt is added without a set, and when a prompt gets a set it must leave the list, so the list only shrinks.
- **Live tests against real models**:
    - Run every Monday at 05:30 and whenever HQ presses **Test now**.
    - Each run stays within a budget ($3 by default).
    - Each case goes through the real AI gateway: redaction, the output policy and the feature's model chain.
    - **HQ → AI quality** shows, for each prompt: its version, number of cases, latest score, and what failed, in plain words (for example `Elm St / ignores steering words: Fair Housing: says "perfect for families".`).
    - It also shows the untested prompts and recent runs with their cost.

## First test sets (12 prompts, 36 cases)

- **Messages that reach clients:**
    - lead replies (`crm.reply_draft`, `crm.ai_responder`);
    - long follow-up (`crm.nurture`);
    - the website assistant (`sites.ailly`);
    - the deal concierge (`transactions.portal_concierge`).
- **Public marketing:**
    - listing descriptions (`listiq.description`);
    - listing ads (`ads.listing_copy`);
    - the compliance rewrite (`ads.compliance_rewrite`).
- **Facts people rely on:**
    - lead email reading (`crm.lead_email`);
    - policy answers (`docs.policy_answer`);
    - the CMA summary (`pricing.cma_summary`);
    - recruiting notes (`recruiting.outreach`).

## Market leaders

| Leader                                   | What they do                               | Where they fall short                                             | What we do                                                            |
| ---------------------------------------- | ------------------------------------------ | ----------------------------------------------------------------- | --------------------------------------------------------------------- |
| Lofty (agentic AI OS)                    | Many AI agents, logs and approval controls | Quality is asserted, not measured; there is no public test set    | A measured score per prompt version, with the failures in plain words |
| kvCORE / BoldTrail AI, Follow Up Boss AI | AI texting and email drafts                | No Fair Housing or invented-price checks on the drafts themselves | Fair Housing, no-invention and length checks on every tested answer   |
| Generic LLM evaluation tools             | Evaluation frameworks                      | Not real-estate aware; need engineers                             | Real-estate checks built in, and an HQ page anyone can read           |

## Settings and review

- **Review:** the live test budget is $3 a run (`ai.eval_budget_cents`). Raise it once all prompts have sets.
- **Review:** 45 prompts are still on the untested list. Each future sprint that touches a prompt adds its set.
