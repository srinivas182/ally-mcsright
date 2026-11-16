# Movewise MW6: outcomes, billing models, scorecard and HQ money

Status: **Built** (sprint MW6). Follows [00-overview](00-overview.md) §6.3, §9 and §11.

## 1. Status flows back

- **Updating the status.** On each introduction the agent sets where things stand. Status only moves forward, apart from "Not going ahead", which can be set at any point. The steps are:
    1. Talked with them
    2. Appointment set
    3. Appointment held
    4. Agreement signed
    5. Under contract
    6. Closed (sale price, the agent's side's gross commission and the closing date are required)
- **History.** Every update is kept in `movewise_status_events`, and the agent sees the history on the introduction.
- **Reminders and pauses.** `movewise:outcomes` runs daily at 15:07:
    - 14 days with no update: a reminder.
    - 7 more days with no update: the package pauses. For a broker package, the owners are told too.

## 2. Billing models

| Model           | Charged                                                                                                | How                                                                                                        |
| --------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| Per connection  | On accept (MW4)                                                                                        | Credits, keyed by the introduction                                                                         |
| Per appointment | When the agent marks "Appointment held" **and** the person confirms "Did you meet Ann?" in My Movewise | Credits, once (`movewise-appointment:<id>`); allowed to overdraw, since the service was delivered          |
| Share at close  | At closing                                                                                             | A **referral invoice** from the state's managing broker partner to the agent's brokerage, broker to broker |

- **The referral agreement.** It is shown on the introduction before accepting, and signed by ticking a box at accept. It is stored word for word with the time and the percentage. Its terms:
    - the percentage is the state's share, and applies to the gross commission on that side;
    - it covers closings within 12 months;
    - payment is due within 30 days of closing;
    - after 30 days with no status update, the per-connection price applies instead.
- **The fallback.** The daily run applies that last term: it charges the per-connection price once and switches the model.
- **Invoices.**
    - Numbered `MW-yymmdd-XXXX` and due in 30 days. The brokerage's owners get the details by email.
    - No money moves through RightAlly.
    - In HQ → Movewise money, HQ marks an invoice paid or voids it (a reason is required, and the action is password-confirmed and audited).
- **Partner settlements.**
    - Each partner has "HQ's share of referral fees (%)", set in HQ → Movewise.
    - On the 2nd of each month, `movewise:outcomes --settle=` sums the invoices paid the month before for each partner, along with HQ's share.
    - A settlement can be rebuilt until HQ marks it settled.

## 3. Ratings, scorecard and tiers

- **Ratings.** The person rates the agent from 1 to 5, with an optional note, in My Movewise. Rating opens a week after the introduction, or at closing. These ratings feed the scorecard; MW7 adds public reviews.
- **The scorecard.** It covers the last 90 days and is recalculated every Monday by `movewise:outcomes --scorecards`. The measures are:
    - median minutes to accept;
    - status kept up to date (share of introductions older than 14 days with an update in the last 14 days, or closed, lost or ended);
    - average rating and number of ratings;
    - appointments, agreements and closings per 100 accepted;
    - share stopped, changed or withdrawn.
- **Tiers:**
    - **New:** fewer than 5 rated introductions.
    - **Preferred:** every one of these is met:
        - median accept of 10 minutes or less;
        - status up to date on 90% or more;
        - rating of 4.5 or more;
        - stops of 10% or less.
    - **Standard:** everyone else.
- **What agents see.** The agent sees every measure and each rule as "Met" or "Not met" on their Movewise page. HQ sees the same.
- **Tier changes.** Each change is audited. The tier sets caps (MW3) and ranking (MW4).
- **Strikes.** HQ gives a strike with a reason. The third strike removes the agent and pauses their packages.

## 4. HQ → Movewise money (`/hq/movewise/money`)

- **Revenue.** For the last 90 days, by state and model: introductions, accepted, credits charged and credits refunded.
- **Quality:**
    - accepted;
    - closed;
    - stopped or changed agent;
    - average rating.
- **Referral invoices.** Overdue invoices are flagged.
- **Partner settlements.**

## 5. Not in MW6

- **Automatic status from CRM events** (deal stage changes, completed appointments). The status is reported by the agent for now. Linking it to the deal pipeline is a follow-up once Movewise leads are mapped to deals.
- **Fair Housing and consent audit exports.** These come in MW8.

## 6. Tests

The MW6 section of `tests/Feature/Movewise/MovewiseMatchingTest.php` covers:

- **Status:** forward-only updates, the reminder, then the pause.
- **Per appointment:** charged only after both confirmations, and only once.
- **Share at close:**
    - accepting is refused without the agreement;
    - the agreement is stored;
    - closing requires its figures;
    - the invoice amount and the owner's notice;
    - HQ marks it paid;
    - the settlement and HQ's share.
- **Fallback:** moves to the per-connection price after 30 quiet days, charged once.
- **Scorecards and strikes:**
    - the scorecard and its reasons, with Preferred against New;
    - the rating window;
    - three strikes remove the agent.
