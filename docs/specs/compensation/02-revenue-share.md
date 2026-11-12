# Compensation 02 — Revenue share (Team B, with Team D screens and Team E recruiting)

Status: **Specification approved; 11B (legacy parity) delivered** — rules (basis net profit, GCI or company dollar; up to 15 levels; legacy totals validation; missing and ineligible policies; per-deal root cap), dated sponsorships (no self or cycles; audited), the engine (distribution in the finalise transaction, missed earnings, root fallback, wallets as Revenue share payable sub-accounts with one balanced journal per deal, idempotent posting keys, exact-negative reversals) and a 1,000-deal property test. **11B-2 delivered**: tier unlocking (`unlock.open_levels`, qualifying front-line agents by closings or GCI in a window with per-level thresholds, own-production unlock after capping), funding limits (`funding.per_deal_max_cents`, `per_recipient_yearly_max_cents`; never above the deal's company dollar), the brokerage budget guard (`rs_settings.budget_percent` of company dollar year to date; owners emailed once a year at 80%), co-sponsorship (level 1 split; deeper levels follow the primary sponsor); every unpaid amount is a missed earning with its reason (`locked_level`, `recipient_limit`, `budget`, `fallback_cap`, `missing_upline`, `ineligible_upline`). Next: vesting and beneficiaries, attribution lock and disputes, abuse controls, milestone bonuses (owner, after leader review of eXp, Real, LPT; all 16 additions in
version 1.0). Delivery: 11B legacy parity → additions 1, 2, 4 (Team B) → screens (Team D) → AI recruiting
assistant (Team E).

## Purpose

Each brokerage designs its own revenue share plan: agents earn a share of the brokerage's revenue on
deals closed by agents they sponsored, across levels. RightAlly is not one brokerage's plan (as at eXp or
Real) but a **plan builder** for many brokerages; every feature below is a versioned plan setting, tried in
the simulator before publishing.

**Compliance principle**: revenue share is **paid only from closed transactions — never for sign-ups**; every
payment traces to a source deal; brokerages can publish a yearly income disclosure.

## Legacy parity (must match RightAlly today; each rule is an automated test)

| #   | Rule (docs/legacy/business-rules.md)                                                                                                                       |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 5   | Basis is **Net Profit** or **GCI** (plus **company dollar** as an option)                                                                                  |
| 6   | Level percentages (up to **15 levels**) total 100% for Net Profit, or the company revenue % for GCI, within 0.01 points                                    |
| 7   | Example: GCI with Primary Agent 90% → company revenue 10% → levels must total 10; 50+30+20 is invalid                                                      |
| 8   | An inactive or blocked upline receives nothing; the amount is recorded as a **missed earning**                                                             |
| 9   | A missing or ineligible upline falls back to the **root account** under versioned settings (missing-upline policy, ineligible-upline policy, fallback cap) |
| 15  | Approving a deal creates an immutable snapshot with revenue-share rows in the same database transaction as the calculation                                 |
| 16  | Revenue share and root fallback are credited to the agent's **wallet** (primary agent commission is paid outside it)                                       |
| 17  | Reversals create new linked rows; nothing is deleted                                                                                                       |

Plan rules (`revenue_share` in a compensation plan version): `basis`, `levels` (list of percents, 1–15),
`missing_upline` (`root` | `forfeit`), `ineligible_upline` (`root` | `forfeit`), `fallback_cap_cents`
(per deal), plus the additions below. The **root account** is a brokerage setting (default: the first owner).

Sponsorship: one sponsor per agent with effective dates (sponsor changes never rewrite history), optional
co-sponsor; genealogy derived from sponsorships at the deal's closing date.

## Approved additions

| #   | Addition                             | Notes                                                                                                                                                                                                                                                                                 |
| --- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Tier unlocking**                   | First N levels open; deeper levels unlock by **qualifying front-line agents** (broker defines qualifying: e.g. 2 closings or $5,000 GCI in 6 months) or by the **sponsor's own production** (e.g. capping opens levels 4–5 for 13 months); locked levels follow the ineligible policy |
| 2   | **Funding limits**                   | Revenue share as a % of company dollar; per-deal and per-agent yearly maximum; never more than the brokerage earned on the deal                                                                                                                                                       |
| 3   | **Co-sponsorship**                   | Two sponsors with a split (e.g. 50/50)                                                                                                                                                                                                                                                |
| 4   | **Vesting and beneficiaries**        | After N years, revenue share continues after leaving; can pass to a named beneficiary                                                                                                                                                                                                 |
| 5   | **"Unlock next tier" coaching**      | "2 more qualifying agents unlocks level 4 — about $340/month at your network's pace"; missed earnings shown honestly                                                                                                                                                                  |
| 6   | **Get paid now**                     | Withdraw earned (closed-deal) revenue share any time for a small fee, once agent payouts exist                                                                                                                                                                                        |
| 7   | **Simulator and what-if**            | Replay last year's deals through a proposed plan before publishing                                                                                                                                                                                                                    |
| 8   | **Compliance guardrails**            | Production-only payments; yearly **income disclosure** (median and typical earnings by level)                                                                                                                                                                                         |
| 9   | **AI recruiting assistant** (Team E) | Finds producing agents to approach (licensed MLS agent production data) and drafts outreach                                                                                                                                                                                           |
| 10  | **Milestone bonuses**                | Optional broker-funded bonus to a sponsor when a recruit closes their first deal (production only)                                                                                                                                                                                    |
| 11  | **Retention alerts**                 | "Sam (level 1) has no closings in 90 days" with suggested help (mentor match, Learn-IQ)                                                                                                                                                                                               |
| 12  | **Attribution lock and disputes**    | Sponsor fixed at sign-up (join link or code); disputes go to the broker with evidence, decision and audit trail                                                                                                                                                                       |
| 13  | **Every cent explained**             | Each payment traced to its source ("$42 from a level 2 closing on 12 Jun"); sponsors see level and amount, never client details                                                                                                                                                       |
| 14  | **Abuse controls**                   | Flags for self-sponsoring (shared bank, address or device), tiny qualifying deals, sudden sponsor changes; broker reviews before paying                                                                                                                                               |
| 15  | **Brokerage budget guard**           | Yearly ceiling (e.g. ≤ 50% of company dollar) with a forecast warning before it is reached                                                                                                                                                                                            |
| 16  | **Year-end tax data**                | Each agent's revenue share totals for the brokerage's 1099-NEC filing, exportable                                                                                                                                                                                                     |

## Acceptance (parity sprint)

Legacy rules 5–9 and 15–17 pass as tests; a 1,000-deal property test proves that for every deal the
revenue-share rows, missed earnings and root fallback add up exactly to the distributable amount; sponsor
changes apply only from their effective date; reversals produce exact negatives.
