# Grow 03 — RightAlly for Agents (Team E)

Status: **SO1 and SO2 built** (solo plans and signup, simple view by default, portability guarantee, goal planner;
reactive follow-up, ready-made plans, three new website designs). Works with the brokerage-partner and agent-pays model (`specs/core/07`).

## Approved additions (leader review: Lofty, Follow Up Boss, kvCORE solo plans)

1. **Ten-minute self-serve start**: sign up, add a card, import the old CRM with notes, Today's 10 on day one.
2. **"Ask my broker" answered by AI first** from the brokerage's policy manual; escalates to the broker
   only when needed.
3. **Portability guarantee**: an agent's personal sphere is theirs, exportable at any time.
4. **Goal planner**: "I want 24 closings" → the calls and appointments needed, feeding Today's 10.

## SO1 (built)

### Leader review

| Leader                   | What they do                                               | Where they fall short                                                                                                     |
| ------------------------ | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Follow Up Boss           | $69/month one-user plan, calling and texting, action plans | No IDX website or built-in AI lead response; time-based plans; API only on the $499 tier                                  |
| Lofty                    | Website, CRM, drips and an AI assistant                    | No public prices (an industry estimate is $400–600/month for solo agents), possible setup fee, 10–15 hours to build drips |
| kvCORE / BoldTrail       | Full suite, usually paid by the brokerage                  | Agents often lose their contacts when they leave; heavy to learn                                                          |
| Budget CRMs (Wise Agent) | Cheap                                                      | Dated, no websites or deal tools, little AI                                                                               |

### What was built

- **Solo plans and signup.** Packages are for brokerages or for solo agents (`billing_packages.audience`; HQ sets it). `/agents` on the RightAlly host shows solo plans with Product/Offer and FAQ markup and takes signups; the first payment creates a solo RightAlly (`TenantType::Solo`), invites the agent, starts the subscription and a solo go-live plan (sign in, bring people, set a goal, bring deals, website, first deal, first close). Brokerages can't buy solo plans and solo agents can't buy brokerage packages.
- **Simple view by default.** In a solo agent's RightAlly the view is simple until the person chooses (`SimpleMode::mode`); brokerage people are still asked once.
- **Portability guarantee.** "Download my people" (contacts page, `/my-people/download`): the agent's own personal contacts with notes and tags, in the same columns the RightAlly spreadsheet import reads; cells that spreadsheets would run as formulas are neutralised. When an agent is deactivated and has people of their own, they are emailed a private link (`/take-my-people/{token}`, 30 days, 5 downloads, only the hash stored; opening the page uses no download). Company contacts — from the brokerage's own site, ads and lead sources — stay with the brokerage. Both are audited.
- **Goal planner, the short way.** "How many closings this year?" on Today (`POST /insights/plan` with `closings`) uses the business planner (K3) backwards to conversations a working day. Today lists that many people (at least 10, at most 25) with a progress bar; the daily brief says how many conversations are left today, and asks solo agents without a goal to set one.

Tests: `tests/Feature/Grow/RightAllyForAgentsTest.php`.

## SO2 (built)

### Leader review

| Leader                        | What they do                                     | Where they fall short                                                                                        |
| ----------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Follow Up Boss                | Action plans: timed emails and tasks             | Plans run on fixed timings, not on what the lead does                                                        |
| Lofty                         | Plans that react to opens, clicks and home views | Long setup (an industry write-up estimates 10–15 hours for a full library); hard to debug; uneven AI replies |
| Ylopo / BoldTrail             | Reactive marketing with automated texts          | Expensive add-ons; hard to see why a message went out                                                        |
| Luxury Presence / Agent Image | Polished agent websites                          | Priced well above a solo CRM, and separate from it                                                           |
| Placester and budget builders | Cheap templates                                  | Dated; weak for Google and AI answers                                                                        |

### What was built

- **Reactive follow-up** (`Crm\Plans\Reactions`). Plans still run on their days; on top of that:
    - _Steps up_: when a known website visitor saves a home, works out a payment, keeps coming back to the same home, or returns after 14+ days away, the agent gets a task for today titled "Reach out now: {name} {what they did}" — a call if they may be called, otherwise an email (or a follow-up task). At most one a day per person; never once they are under contract, a past client or archived. The timeline records it.
    - _Hands over_: a reply or a connected call already stops plans (unchanged).
    - _Keeps going_: 3–10 days after a plan finishes with no reply, a lead or active contact who is on no other plan moves to "Not ready yet (monthly)", once. Runs with `crm:run-plans`.
    - Nothing sends itself: the agent sends; texts still need consent and the 8am–9pm window.
- **Ready-made plans** (`FollowUpPlans::TEMPLATES`, keyed by `crm_plans.template_key`): new online lead, new seller lead, open house, sphere, past client, expired or for-sale-by-owner, not ready yet (monthly), and Spanish versions of buyer lead, seller lead and past client. Each RightAlly gets each one once; older plans are matched by name; a plan switched off stays off.
- **Three new website designs**: Hearth (warm, Fraunces + Source Sans 3), Coastal (airy, Nunito Sans) and Metro (bold, Manrope), each with its own starting colours, browser-bar colour and dark-mode palette. Designs are now fully described in `sites.templates` (fonts, theme colour, brand).

Not in SO2: Spanish website pages (with the Spanish back office, MB1), email open and click tracking as signals, and editing plans in plain words.

Tests: `tests/Feature/Crm/ReactiveFollowUpTest.php`; `VisitorJourneyTest` checks the step-up task.

## AG1 (built 2026-11-18): agents' own assistants

Completes E01. Solo packages and signup, the workspace (Today, simple view, goal planner), portability (SO1 and the
"take my people" link) and "Ask my broker" were already built. "Ask my broker" is answered by AI first from the
brokerage's policy manual and passed to the broker when needed (Policies, spec transactions/31), and since AS1
"Your AI Assistant" can also hand any conversation to the broker.

**Market check:** Follow Up Boss and Lofty let a team add an ISA or assistant seat, but usually only the account owner
can, and assistants see the whole team's leads. RightAlly lets the agent add their own assistant, limited to that
agent's own contacts and deals, with a preset.

- **Settings → My assistants** (agents): add up to 3 assistants (name, email, preset); change the preset; remove one
  (password confirmed; they can no longer sign in).
- **Presets** (what they may open; enforced on every page and in the menu):
    - Full: everything the agent uses, except the agent's money;
    - Leads: contacts, tasks, inbox and follow-up;
    - Marketing: Studio, social posts, ads and the website;
    - Admin: deals, documents, tasks and contacts.
      Money pages (my money, my plan, revenue share, billing) are never open to assistants.
- Assistants work only the agent's own contacts and deals (existing rule: `assisted_user_id`).
- The brokerage can require approval before an agent adds an assistant (Settings → Approvals, "An agent adds their
  own assistant"; no approval by default). The owner sees every assistant on the People page.

Tests: `tests/Feature/Core/AgentAssistantsTest.php`.
