# Referral programme (RF1)

**Status:** built in RF1. **Defaults flagged for owner review:** 20% share, one month's credit, 30-day hold.

## What it is

RightAlly's own way to grow by word of mouth. Two kinds of referrers:

| Referrer                              | How they join                                    | Reward                                                                                    |
| ------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| A person (coach, trainer, influencer) | HQ adds them in **HQ › Referral programme**      | 20% of what the brokerage they refer pays RightAlly in its first year, paid in cash by HQ |
| A brokerage already on RightAlly      | Its owner opens **Settings › Refer a brokerage** | One month of its own subscription, as credit                                              |

This is about brokerages joining RightAlly. It has nothing to do with agent-to-agent referral fees: RightAlly never
takes a share of those (see specs/transactions/32 and the referral network sprints).

## How a referral is counted

1. The referrer shares their link: `https://app.rightally.io/r/{code}`.
2. A visit counts a click (a daily total only; nothing about the visitor is kept) and sets a first-party cookie for
   60 days. Unknown codes just go to the signup page.
3. When the visitor signs up, the code is saved on the signup.
4. When the first payment comes through and the brokerage is created, the referral is recorded as **pending**.
5. Every payment in the brokerage's first year adds to the reward (20% for people; a brokerage's credit is one month).
6. After **30 days** (the refund window) a daily job (`billing:affiliates-settle`, 06:20) makes the reward
   **approved**, or **void** if the brokerage already cancelled.
7. HQ pays approved rewards outside RightAlly and marks them **paid** (password confirmed, audited). Nothing pays
   itself.

## Fraud checks

A referral earns nothing ("void", with the reason shown to HQ) when the new brokerage:

- is the referrer's own brokerage,
- has the same owner email as the referrer, or
- shares the referrer's company email domain (free email providers such as gmail.com don't count).

## What each person sees

- **A person** gets a private dashboard link (shown to HQ once, sent by HQ). It shows clicks in the last 30 days,
  how many brokerages joined, and rewards waiting, approved and paid. Brokerage names are not shown. The page is
  never indexed.
- **A brokerage owner** sees the same on Settings › Refer a brokerage.
- **HQ** sees every referrer and referral, with the brokerage names and reasons.

## For review

- 20% of the first year for people, one month's credit for brokerages, and the 30-day hold are defaults. Change
  them in `Modules\Billing\Affiliates\Affiliates` after the business decides.
- Payments to people may need a W-9 and a 1099 at year end; HQ collects these outside RightAlly for now.
