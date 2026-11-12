# Compensation 01 — Plan builder, commission calculator and caps (Team B, Sprints 3–6)

## Purpose

Brokerages define how every deal's commission is split between the agent and the brokerage,
including caps, post-cap fees, team overrides, company-lead splits and per-deal fees. RightAlly
calculates each deal exactly (to the cent), explains every step in plain English, and keeps cap
progress per agent. This replaces the legacy plan tables and capping logic; the legacy rules
(`docs/legacy/business-rules.md`) are acceptance tests.

Revenue share (multi-level payments to sponsors) is a separate spec (Compensation 02, Sprints 7–9).
Posting results to the ledger happens when a deal is approved (Transactions, Sprints 12–14); this
spec delivers the calculation, snapshots and simulation that posting will use.

Module: `modules/Compensation`, namespace `Modules\Compensation`. Tenant-owned. All amounts use
`Money` (specs/finance/01); percentages are exact decimal strings, never floats.

## Delivery

| Sprint | Delivered                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3B     | Module `modules/Compensation`; plans (onboarding, commission), immutable published versions with effective dates, default plan rules, archive rules, assignments with automatic end of the previous one; validated rules (percentage, flat-fee bands, tiered by company dollar or units, pre-split and post-split deductions, deal classes, lead-source overrides, revenue-share basis); pure calculator with plain-English steps and an every-cent check; plan resolution by closing date; legacy rules 1–5 as tests; 2,000-deal property test |

| 4B | Caps (anniversary or fixed cap year) and post-cap fees (flat or percent of the post-cap portion, optional yearly fee cap); the crossing deal is split exactly at the cap; cap periods per agent across every office and team; saved calculation versions (pending, final, reversed, superseded) with steps; finalise records cap entries under row locks; reverse writes negative entries; **automatic recalculation** of pending deals when a deal closes or is reversed, a plan version is published, the default plan changes or an agent changes plan, with a "what changed" note; nightly `comp:check-caps`; legacy rules 10-13 as tests; 2,000-deal property test with caps |

Co-agent decision (3B): the outside referral comes off the top, the remainder is shared between
co-agents, then each agent's share runs through that agent's own plan (pre-split, split,
post-split). A lead-source rule wins over a deal-class rule, which wins over the plan's split.

## Sprint split

| Sprint | Delivers                                                                                                                          |
| ------ | --------------------------------------------------------------------------------------------------------------------------------- |
| 3      | Plan families and immutable versions; percentage, flat-fee and tiered splits; calculator core with explanations; legacy rules 1–4 |
| 4      | Caps and post-cap fees; cap periods per agent; legacy rules 10–13                                                                 |
| 5      | Team overrides, lead-source splits, per-deal fees from a versioned catalogue (legacy rule 14)                                     |
| 6      | Screens: plan builder, version history, assignments, fee catalogue, simulator, agent "My plan"                                    |

## Additions from the best-in-class review (approved)

Reviewed: BoldTrail BackOffice (Brokermint) and Lone Wolf Back Office (brokerWOLF).

| #   | Addition                                                                                                                              | Why                                     |
| --- | ------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| 1   | **Pre-split and post-split deductions** (franchise, royalty and referral fees before the split; transaction, E&O and tech fees after) | Both leaders support them               |
| 2   | **Franchise and royalty fees** with their own annual caps                                                                             | Franchise brokerages                    |
| 3   | **Credits and bonuses** (signing, production, marketing credits; shared or per agent)                                                 | BoldTrail credit items                  |
| 4   | **Exclude a deal** from plan progression and cap, with a required reason (audited)                                                    | BoldTrail exclusion                     |
| 5   | **Deal classes** (sale, lease, commercial, referral-only, new construction, BPO) with their own rules                                 | Lone Wolf deal classes                  |
| 6   | **Partial payments and instalments**, holdbacks, automatic commission disbursement authorisation                                      | Lone Wolf partial payments              |
| 7   | **Automatic recalculation** of every pending deal when a plan, cap or deal changes, with a "what changed" note                        | BoldTrail requires manual recalculation |
| 8   | **Caps across all offices and teams** in the brokerage                                                                                | BoldTrail caps are per sub-account only |
| 9   | **AI plan setup**: describe the plan in plain English or upload the agent agreement or plan sheet; legacy plans import automatically  | Setup is slow and hard in incumbents    |
| 10  | **Agent earnings view**: distance to cap, projected take-home on pending deals, cap celebration, reset reminder                       | Agent motivation and transparency       |
| 11  | **Plan profitability for brokers**: run the roster's last 12 months through a proposed plan before changing it                        | Data-driven plan decisions              |

Sprint split becomes Sprints 3–7 (the additions land in Sprints 3–5 for 1, 5 and 7, Sprint 6 for
2–4 and 6, and Sprint 7 for 8–11).

## Concepts

| Concept      | Meaning                                                                                                        |
| ------------ | -------------------------------------------------------------------------------------------------------------- |
| Plan family  | A named plan, e.g. "Standard 80/20 with $16k cap"                                                              |
| Plan version | Immutable published rules with an effective date; agents stay on the version in force on a deal's closing date |
| Plan type    | `onboarding` (joining, monthly and annual fees billed by Finance) or `commission` (deal splits)                |
| Assignment   | Which commission plan (and onboarding plan) an agent is on, from a date                                        |
| Cap period   | One agent's cap year: amount, start/end, company dollar paid so far                                            |
| Calculation  | The result for one deal side: every amount, the steps that produced it, and the plan version used              |

## Data model (cell)

### `comp_plans`

`id, ulid, tenant_id, type (onboarding, commission), name, description, is_default (commission only),
status (active, archived), created_by, timestamps`.

### `comp_plan_versions`

`id, ulid, tenant_id, plan_id, version, status (draft, published, superseded), effective_from,
rules (json, validated against the rules schema below), published_by, published_at, change_note,
timestamps`. Published versions are never edited; changes create a new draft version.

### Commission rules schema (`rules` json)

| Field                    | Values                                                                                                        |
| ------------------------ | ------------------------------------------------------------------------------------------------------------- |
| `split.mode`             | `percentage`, `flat_fee`, `tiered`                                                                            |
| `split.agent_percent`    | for `percentage`, e.g. `"80"` (company percent is derived: 100 − agent, legacy rule 4)                        |
| `split.flat_fee`         | for `flat_fee`: list of price bands `{up_to_price_cents                                                       | null, fee_cents}` (legacy "small / large / mega")        |
| `split.tiers`            | for `tiered`: list `{from_company_dollar_cents                                                                | from_units, agent_percent}` measured within the cap year |
| `cap.amount_cents`       | company dollar cap per cap year, or `null` for no cap (legacy −1)                                             |
| `cap.anchor`             | `anniversary` (agent start date) or `fixed` with `cap.anchor_month_day`, e.g. `"01-01"`                       |
| `post_cap.fee`           | `{type: flat                                                                                                  | percent, amount}` per transaction after the cap          |
| `post_cap.fee_cap_cents` | optional maximum post-cap fees per cap year                                                                   |
| `team.override_percent`  | team leader's share of a team member's agent side, e.g. `"10"`                                                |
| `team.cap_contribution`  | `individual` (member's company dollar counts toward the member's cap) or `team`                               |
| `lead_sources`           | overrides by lead-source category: `{category: "company_lead", agent_percent: "50", counts_toward_cap: bool}` |
| `fees`                   | list of fee catalogue codes applied to every deal on this plan                                                |
| `revenue_share.basis`    | `net_profit` or `gci` (used by Compensation 02; validated now: legacy rule 5)                                 |

Rules are validated on save: percentages 0–100 with up to 4 decimals; bands ascending with a final
open band; tiers ascending; references to fee codes exist.

### `comp_plan_assignments`

`id, tenant_id, agent_user_id, plan_id, plan_type, effective_from, effective_to nullable,
assigned_by, timestamps`. At most one assignment per agent per plan type covering any date
(overlaps rejected). Agents without a commission assignment use the default commission plan.

### `comp_fee_catalogue` and `comp_fee_versions`

Legacy rule 14: `code, name, stage (per_transaction, monthly, annual, onboarding), calc_type (flat,
percent_of_gci, percent_of_agent_side), amount, charged_to (agent, client, brokerage), payable_to
(brokerage, vendor), counts_toward_cap (bool), revshare_eligible (bool), effective_from,
effective_to, status`. Edits create a new version; calculations record the version used.

### `comp_cap_periods`

`id, ulid, tenant_id, agent_user_id, plan_version_id, starts_on, ends_on, cap_amount_cents,
company_dollar_cents, post_cap_fees_cents, status (open, capped, closed), timestamps`.
Unique per agent and start date.

### `comp_cap_entries`

`id, tenant_id, cap_period_id, calculation_id, company_dollar_cents (signed), post_cap_fee_cents
(signed), reason (deal, reversal), created_at`. Period totals equal the sum of entries (nightly check).

### `comp_calculations`

`id, ulid, tenant_id, deal_ref (string), side (listing, buying), agent_user_id, version
(1, 2, … per deal side), plan_version_id, inputs (json), result (json: every amount in cents),
steps (json: ordered explanation), status (draft, final, reversed), created_by, created_at`.
A recalculation creates a new version; nothing is overwritten (legacy rule 15, 17).

## Calculation (Sprint 3–5)

`CommissionCalculator::calculate(DealSideInput $input): Calculation` — pure and deterministic:
no database writes; the caller decides whether to save a draft or finalise.

**Input:** deal side GCI (after any outside referral fee), sale price, closing date, agent,
co-agents with their percentage shares of the side, lead-source category, team membership,
per-deal fee overrides, and the agent's cap period state as of the closing date.

**Order of operations** (each step recorded in `steps` with amounts):

1. **Outside referral fee** taken off the top if present (paid to the referring brokerage).
2. **Co-agent shares**: the side is allocated between agents by their shares (`Money::allocate`);
   steps 3–8 run per agent.
3. **Plan version** in force on the closing date, from the agent's assignment or the default plan.
4. **Lead-source override** replaces the split when the deal's lead category has one.
5. **Base split**: percentage → company dollar = GCI × company %, agent = remainder;
   flat fee → company dollar = band fee (never more than GCI); tiered → percent of the tier the
   agent is in at the start of the deal.
6. **Cap**: if the cap would be crossed by this deal, company dollar is limited to the remaining
   cap amount; the rest goes to the agent and the post-cap fee applies to the portion after the
   cap. If already capped, company dollar is zero and only the post-cap fee applies (legacy rules
   10–12). Post-cap fees stop at the post-cap fee cap when set.
7. **Team override**: team leader receives the override percent of the agent's side (after cap).
8. **Per-deal fees** from the catalogue (agent-charged fees reduce the agent's net; fees marked
   `counts_toward_cap` add to cap progress).

**Rounding rule:** company dollar is computed and rounded half-even; the agent's amount is always the
remainder, so the parts sum exactly to the GCI. Every split uses `Money::allocate` or remainder.

**Result:** agent gross, agent net (after fees and team override), company dollar, post-cap fee,
team override, each fee, outside referral, cap progress before and after, plan version, and steps.

**Plain-English steps,** for example:
"GCI $9,000.00 → company 20% = $1,800.00 → cap remaining $600.00, so company dollar limited to
$600.00 → agent receives $8,400.00 → post-cap fee $50.00 on the post-cap portion."

## Cap periods (Sprint 4)

- Opened automatically for an agent on the first calculation in a new cap year.
- Anniversary anchor uses the agent's start date; fixed anchor uses the plan's month and day.
- Finalising a calculation writes cap entries; reversing writes negative entries (legacy rule 13).
- Changing an agent's plan mid-year keeps the current cap period (company dollar already paid still
  counts); the new version's cap amount applies from the change date. Shown to the broker before
  saving the change.

## Validation rules (legacy rules as tests)

| Legacy rule | Acceptance test                                                                                                                                     |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1           | Plans are `onboarding` or `commission`                                                                                                              |
| 2           | Onboarding plans are never default; exactly one default commission plan; setting a new default clears the old; removing the only default is blocked |
| 3           | Commission sub-types `percentage` and `flat_fee` (small/large/mega bands supported)                                                                 |
| 4           | Company percent = 100 − agent percent; never entered separately                                                                                     |
| 5           | `revenue_share.basis` is `net_profit` or `gci`                                                                                                      |
| 10          | Cap amount per cap year; `null` (legacy −1) means no cap                                                                                            |
| 11          | Below the cap, the normal split applies                                                                                                             |
| 12          | After the cap, agent receives 100% minus the post-cap fee                                                                                           |
| 13          | Cap totals reset each period; reversals tracked per period                                                                                          |
| 14          | Fees come from the versioned catalogue with charged-to, payable-to, counts-toward-cap and revenue-share-eligible                                    |

## Screens (Sprint 6)

- **Plans:** list by type with agent counts; default badge.
- **Plan builder** (wizard): basics → split (percentage slider with live company percent, flat-fee
  bands, or tiers) → cap and post-cap → team and lead sources → fees → review with a live example
  deal → publish (approval rule `compensation.plans.publish` when approval rules ship).
- **Version history** with a plain-English diff between versions.
- **Assignments:** assign one or many agents with an effective date; preview of cap impact.
- **Fee catalogue:** list, add, new version, retire.
- **Simulator:** enter or generate a year of deals for an agent on a plan, or compare two plans
  side by side: agent take-home, company dollar, the date the cap is reached. Reused by recruiting
  ("what would I earn here?").
- **Agent "My plan":** plan summary in plain English, cap progress bar, company dollar paid and
  remaining, post-cap fees, last calculations with their steps.

## Permissions

`compensation.plans.view`, `compensation.plans.manage`, `compensation.plans.publish`,
`compensation.assignments.manage`, `compensation.fees.manage`, `compensation.simulate`;
agents always see their own plan and calculations (scope `own`).

## Events and audit

`PlanVersionPublished`, `DefaultPlanChanged`, `PlanAssigned`, `CalculationFinalised`,
`CalculationReversed`, `AgentCapped`, `FeeVersionCreated`. All audited with before/after.

## Edge cases

- Deal crossing the cap: split into pre-cap and post-cap portions in one calculation.
- Dual agency in one brokerage: each side calculated separately with its own agent and plan.
- Co-listing agents on different plans: each agent's share uses their own plan and cap.
- GCI of zero: no company dollar, fees still evaluated (some may be waived by rule).
- Flat fee above GCI: company dollar limited to GCI; step explains why.
- Closing date before an agent's first assignment: default plan applies; flagged for review.
- Recalculation after an edit: new calculation version; cap entries adjusted by the difference only.
- Team leader is also the agent: no override on their own deals.

## Acceptance criteria

1. Every legacy rule in the table passes as an automated test.
2. Property test: for random GCIs, splits, caps, co-agent shares and fees, all parts always sum
   exactly to the GCI (no cent lost or created).
3. Crossing-the-cap examples from the brokerage's own plan documents reproduce to the cent.
4. Published versions are immutable; deals use the version in force on their closing date.
5. Calculations are versioned per deal side and never overwritten; reversals restore cap progress.
6. Cap period totals always equal the sum of their entries (nightly check).
7. The simulator's yearly totals equal the sum of individual calculations.
8. The parallel run against legacy commissions (Sprints 23–25) matches to the cent.

## Approved additions (version 1.0; leader review: Brokermint, BoldTrail BackOffice, Lone Wolf, Real reZEN)

1. **AI plan setup**: upload the brokerage's current plan PDF → a draft plan, checked with the simulator
   before publishing; nothing is published without the owner's approval.
2. **Agent earnings forecast**: "$18,400 to cap; at your pace you'll cap in August."
3. **Company dollar and plan profitability** by plan and by agent.
4. **Legacy parallel run started early**: every new calculation compared with the current RightAlly result
   to the cent, well before the pilot.
