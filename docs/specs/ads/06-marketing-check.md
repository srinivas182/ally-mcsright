# Marketing check: compliance markup, fixes, MLS fact check, approval queue (MK3)

Status: Done. Tracker: D11.

## What it does, in plain words

**Grow → Marketing check.** An agent pastes the words of an ad, flyer, social post, email or sign, chooses what it is, and optionally gives the MLS number.

**Problems are marked in place** (red, wavy underline). Each one says why it matters and how to fix it:

| Check                   | What it catches                                                                                                                                                                                | Level      |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| Fair Housing            | "family-friendly", "safe neighborhood", "great for kids", "adults only" and the rest of the shared Fair Housing list, with neutral wording to use instead                                      | Must fix   |
| Brokerage named         | The brokerage's name is missing (state advertising rules)                                                                                                                                      | Must fix   |
| MLS facts               | Price, beds, full baths and square feet that differ from the MLS; "just listed" or "for sale" when the MLS says pending or sold; an MLS number not found (with the Clear Cooperation reminder) | Must fix   |
| Promises and rank       | "Guaranteed sale", "#1 agent", "best agent in…", "will sell in…" without a source (truth in advertising; NAR Code Article 12)                                                                  | Must fix   |
| Financing trigger terms | "3% down", "$1,999 a month", "6% rate", "30-year fixed", "no money down": these need the full Regulation Z disclosure, including the APR                                                       | Must fix   |
| Square feet source      | Square footage with no "approx." or source                                                                                                                                                     | Should fix |
| Equal Housing           | Print pieces (flyers, signs, postcards) without "Equal Housing Opportunity"                                                                                                                    | Should fix |

Then the agent has three options:

- **Apply the fixes** puts neutral wording in, adds the brokerage name, and adds Equal Housing on print, at no cost.
- **Rewrite it for me (AI)** costs 1 credit. It keeps every fact (or uses the MLS figure where the ad was wrong), fixes the problems, and the check runs again on the result.
- **Send for approval** can attach the flyer (PDF, JPG or PNG). When the broker turns on "Agents must send marketing for approval", this is required.

**Brokers** (owners, or anyone with the new permission "Approve agents' ads, flyers and posts") see a queue showing the marked-up text, the findings and the file. They either **Approve**, or **Send back** with a note, which is required. Every check that flags something goes into the Fair Housing audit pack (channel "Ads"). Every submission and decision is audited.

## Market leaders

| Leader                          | What they do                     | Where they fall short                                               | What we do                                                                |
| ------------------------------- | -------------------------------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Lone Wolf / SkySlope compliance | Document review for transactions | No marketing review at all                                          | A dedicated marketing check and approval queue                            |
| kvCORE / Lofty AI content       | AI writes the marketing          | No fact check against the MLS; Fair Housing filtering varies        | Every piece checked against the MLS, with Fair Housing on the shared list |
| Canva for Teams                 | Brand templates with approval    | Approval checks design, not law; no Reg Z or Fair Housing awareness | Rules a compliance broker would apply, explained in plain words           |
| Manual broker review            | Email the flyer to the broker    | Slow, untracked, and agents don't learn why                         | Marked-up findings teach the agent; tracked decisions protect the broker  |

## Compliance notes

- This is a guardrail, not legal advice. The rules are deterministic and explainable, and the same text always gets the same answer.
- The AI rewrite treats the piece as untrusted data, keeps the facts, and costs 1 credit (shown before running).
- Files are private, shown only to the agent who sent them and to reviewers.

## Settings and review

- Phrase lists and replacement wording, the financing trigger patterns, and the price tolerance (0.5% or $1,000) are defaults to **review** with the brokerage's attorney.
