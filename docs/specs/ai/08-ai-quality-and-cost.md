# AI quality and cost (AQ1, AQ2)

Status: Done (AQ1 and AQ2). Tracker: D19 Done.

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

## What it does, in plain words (AQ2: cost)

- **A lighter model for short requests.** For each feature, HQ can choose a cheaper model for requests up to a set length. Most texts and quick replies fit. If the lighter model is down, the usual model answers.
- **Reusing answers.**
    - For features where the same question should always get the same answer (for example, translating a listing or reading a lead email), HQ can let an identical request reuse the answer for up to 24 hours.
    - The reused answer is free to the brokerage: the credit is refunded.
    - It is stored encrypted, kept only for that brokerage, and never shared with another brokerage.
- **Prompt caching.** Long instructions sent to Claude are marked for Anthropic's prompt cache, so repeat calls read them at a fraction of the price. Writing to the cache is counted as input at 1.25 times the normal rate, so costs stay exact.
- **HQ → AI cost**:
    - cost for the last 7, 30 or 90 days, compared with the period before;
    - AI answers, answers reused (with the money saved), and answers from a lighter model;
    - a bar for each of the last 30 days;
    - cost by brokerage and by feature (cost per answer, share reused);
    - each feature's cost settings, on the same page.

    It shows only figures from the request log. No one's questions or answers are stored, so nothing here shows what anyone asked.

- **Cost jump alert.** Every morning HQ is emailed when a brokerage spent more than three times its usual day on AI, and over $5. The email lists common causes: a new automation, a big import, or a loop.

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

- **Review:** reusing answers is off for every feature until HQ turns it on. Turn it on only for features where the same question should always get the same answer.
- **Review:** the cost-jump alert is set at more than three times the usual day and over $5.

- **Review:** the live test budget is $3 a run (`ai.eval_budget_cents`). Raise it once all prompts have sets.
- **Review:** 45 prompts are still on the untested list. Each future sprint that touches a prompt adds its set.
