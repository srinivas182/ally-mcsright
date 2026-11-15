# Movewise 00 — HQ's consumer home site and lead marketplace (overview)

Status: **Direction approved 2026-11-15** by the product owner. Detailed specs (MW1 to MW8) are written one sprint
ahead of each build, as usual.

Owner: **HQ product** (Team E for growth and AI, Team C for the site and IDX, Team A for platform and billing).
Sprint order: `docs/plan/delivery-plan.md` under "Movewise".

## 1. What Movewise is

Movewise is a home search and moving site for **buyers and sellers**, owned and run by **RightAlly HQ**. It is not a
brokerage tenant. Its job is to:

1. be the site people **prefer** to Zillow, Realtor.com and Homes.com, because it is more honest, more helpful and
   never spams them; and
2. turn the people who are ready to move into **exclusive, warm, consented introductions** to one agent each. Agents
   and brokers buy these from HQ.

**Brand and domain (approved 2026-11-15):** the site is **Movewise Homes** at **movewisehomes.com**. It is called
"Movewise" for short in copy and in AIlly's voice.

- **movewise.homes** and **movewiserealestate.com** are also registered. Both send visitors to movewisehomes.com
  with a permanent (301) redirect that keeps the path. They are never a second copy of the site, which would split
  search ranking.
- "REALTOR®" is a National Association of Realtors trademark, so it is not used in the brand or the domains.
- A trademark clearance for "Movewise Homes" is done before public launch (section 14).

## 2. Launch states

**Texas, Mississippi, Louisiana and Alabama.** HQ has a licensed **managing broker partner** in each of them. Each
partner:

- is the licensed broker of record for Movewise activity in that state, shown on the site as state rules require;
- holds the MLS memberships that give Movewise its listing data in that state (IDX or VOW, under each MLS's rules);
- signs the referral agreements and receives the commission share where HQ uses the revenue-split model
  (section 9), then settles HQ's share as agreed in the partner contract.

More states open only when HQ has a managing broker there, has switched the state on, and has finished the state's
legal checklist (section 12). Each state has its own on/off switch, pricing, revenue-split percentage and partner.

## 3. The promises (what makes Movewise better)

These are product rules, not marketing lines. Every screen, message and price must keep them.

**To buyers and sellers**

1. **No spam, ever.** Browse, save, ask anything. You don't need a phone number to see homes or get answers.
   **Nobody contacts you until you choose an agent and tap Connect.**
2. **You choose your agent.** When you're ready, we show **three** matched local agents. You see each one's photo,
   rating and reviews and choose who you want to talk to, and how. Only that agent contacts you.
3. **Your information is never sold to a crowd.** One agent at a time. You can change agent, pause or stop at any
   time.
4. **Honest information.** Fresh MLS listings, each showing when it was last updated. The true monthly cost of a
   home, risks in plain English, and what an agent costs.
5. **Open about money.** A plain page explains how Movewise is paid: agents pay Movewise for introductions. We
   don't sell your data and we don't push our own lender, title company or insurer.

**To agents and brokers**

1. **Pay for real introductions, not forms.** A charge applies only after the consumer has **chosen you and tapped
   Connect**. Wrong numbers and fake contacts are credited back.
2. **Exclusive.** A lead is never sold to more than one agent at a time.
3. **Warm and complete.** Score, brief, the full AIlly conversation, saved homes and searches, and the consent
   record go straight into your RightAlly CRM.
4. **Fair, published rules.** Everyone sees the same scorecard and tier rules. Tiers are earned, not by invitation.
   New agents start in a smaller lane.
5. **The client stays yours.** After the deal, Movewise steps back. Repeat and referral business belongs to the
   agent.

## 4. Consumer experience

### 4.1 Home search (no sign-in needed)

- Map and list search across all four states, with listings, photos and status kept fresh from the managing
  brokers' MLS feeds (the same Listings engine as brokerage sites; specs/websites/02).
- Each home page shows:
    - **True monthly cost**: principal and interest, taxes, insurance estimate, HOA and typical utilities, with each
      line explained.
    - **Risks in plain English**: flood zone, climate and insurance risk where data allows.
    - Nearby schools (facts only, no ratings that could steer), commute times to places the person adds, and
      "Last updated N minutes ago".
    - **Listing attribution as each MLS requires.** The listing brokerage and agent are always shown.
- Saving homes and searches and setting alerts needs only an email address, through a passwordless email link.
  A phone number is optional and is never needed to see anything.

### 4.2 AIlly for Movewise

The same AIlly assistant as brokerage sites (specs/websites/03), with Movewise branding and these extra skills:

- **For buyers:**
    - "How much can I afford", explained step by step.
    - What a buyer agent does and costs, and what a written buyer agreement means. This is the post-settlement
      explainer, kept neutral.
    - Questions about any home, neighbourhood, process step or document.
- **For sellers:**
    - A home value range with the comps shown and explained.
    - A **net sheet** showing what they would walk away with, and a prep and timing plan.
- **Move planner:** budget, timeline and checklist (utilities, movers, address changes). This is what brings
  people back, and it gives us the best intent signals.
- **Guardrails:**
    - AIlly never pretends to be a person or an agent.
    - It never gives legal, tax or lending advice.
    - It follows Fair Housing rules (no steering, nothing about who "fits" a neighbourhood).
    - It never asks for a phone number before the person chooses to connect.
- **English and Spanish from day one.** Large text, simple steps, WCAG 2.2 AA.

### 4.3 Choosing an agent (the "show 3" screen)

When the person shows they are ready, AIlly offers: _"Want to talk to a local expert? Pick who you'd like."_ Ready
means one of:

- asks for a showing;
- asks for a valuation visit or a listing talk;
- asks to make an offer;
- presses "Talk to an agent".

The screen shows **three agents** (section 7 explains how they're chosen). Each card shows:

- **Photo**, name, brokerage and licence number (linked to the state's licence lookup).
- **Rating and number of reviews.** Tapping opens the reviews, each with its source and date (section 8).
- Homes sold nearby in the last 12 months, typical reply time, and languages spoken.
- A short bio written by the agent and approved by Movewise.

The person taps one agent, then chooses **how**:

- **"Text me"**,
- **"Call me"**, or
- **"Pick a time"** (the agent's real calendar availability, from the booking engine).

**This tap is the consent.** It names that one agent and that one way of contact, and the wording is saved
(section 10). The other two agents are not told and not charged.

The person can also say **"Not now"**. AIlly keeps helping, and nobody contacts them.

### 4.4 After connecting

- The chosen agent texts or calls from the person's **Movewise relay number**, inside the same conversation.
- In **My Movewise**, the person sees "Working with Jane Doe" and can:
    - **change agent**, which shows the three cards again with the current agent left out;
    - **pause** ("No contact for 2 weeks");
    - **stop** ("I'm done; no more contact").

    Every one of these takes effect immediately. The agent is told politely, without reasons the person didn't give.

- Alerts and the move planner keep working.

### 4.5 If the agent doesn't respond

The chosen agent has **5 minutes** to accept during their working hours. Outside working hours, they have until
30 minutes after their next start. The time limits are HQ settings.

If they don't accept, nobody is charged and AIlly tells the person honestly: _"Jane hasn't been able to reply yet.
Would you like Sam or Maria instead, or wait for Jane?"_ The lead is **never** passed to another agent without
the person's choice.

## 5. Lead quality and scoring

Every Movewise person has a **readiness score** (0–100), using the Lead-IQ scoring model (specs/grow/02) trained on
Movewise outcomes. The signals are:

- **What they said to AIlly:** timeline, pre-approval, budget, selling first, already has an agent.
- **What they did:** return visits, homes saved, repeat views of the same home, valuation and net sheet use, move
  planner progress, alerts opened.
- **Contact checks:** email verified; phone verified by a one-time code at connect time.

People who say they **already have an agent** are never offered agents. AIlly helps them, and tells them to
bring their agent along.

The agent sees a **brief**:

- who they are, what they want and their timeline;
- money facts they shared;
- their top homes or their own home's details;
- what to say first;
- the full chat.

## 6. Agent and broker program

### 6.1 Who can join

- **Licensed agents and brokers in a launch state, through their brokerage.** Both are allowed:
    - **Agent packages:** the agent buys and works their own leads.
    - **Broker packages:** the brokerage buys, and chooses which of its agents can be shown. Charges go to the
      brokerage.
- **Agents must be on RightAlly** so the relay, inbox, status updates and close tracking work. Agents outside
  RightAlly get a **free Movewise Agent seat**: a small RightAlly tenant with just the CRM, inbox and calendar.
  Being a full brokerage customer is not required.
- **Checks before going live:**
    - licence active in that state (checked against the state's records, and re-checked monthly by the Roster
      verifier);
    - brokerage confirmed;
    - no open disciplinary action shown publicly;
    - profile card approved;
    - program terms signed.

### 6.2 What a package sets

- **States, zip codes or cities, price range, and lead types:** buyer, seller, or both.
- **Monthly cap**, and an optional daily cap.
- **Working hours** for the response timer.
- **Pricing model per state,** from the models HQ has turned on there (section 9).
- **Payment:** agent or brokerage credits, using the existing credit ledger. A per-connection charge is a credit
  charge, and refunds go back to the same wallet.

### 6.3 Scorecard and tiers (published)

| Measure              | What counts                                                                         |
| -------------------- | ----------------------------------------------------------------------------------- |
| Speed                | Median time to accept, and to first real contact                                    |
| Follow-through       | Status updated at least every 14 days until closed or lost                          |
| Consumer rating      | Rating after the first week, and after the deal                                     |
| Results              | Appointments, agreements signed, and closings per 100 connections                   |
| Complaints and stops | Share of people who pressed stop or change agent, with reasons where they gave them |

**Tiers:**

- **New:** small cap, shown less often until 5 connections have been rated.
- **Standard.**
- **Preferred:** shown more often and allowed higher caps.

The tier is recalculated weekly from the last 90 days, and the agent sees exactly why.

Breaking the rules has fixed consequences:

- **No status updates:** a reminder, then a pause.
- **Bulk-messaging a Movewise lead:** blocked by the system (section 10), and a strike.
- **Three strikes:** the agent is removed.

## 7. Choosing the three agents

The three agents come only from active, eligible packages. The steps are:

1. **Eligible:**
    - licensed in the property's state;
    - covers the area;
    - the price is within their range;
    - lead type matches;
    - under their cap;
    - not paused;
    - available in the person's language, if the person asked for one.
2. **Ranked**, with weights set by HQ:
    - tier and scorecard;
    - closings in that area and price band;
    - reply speed;
    - consumer rating;
    - fair rotation, so a few agents don't take everything.
3. **Shown:** the top three, with **one place kept for an agent who hasn't been shown recently**, if one is
   eligible. That keeps it fair and stops the same three appearing every time.

**Fair Housing:** ranking never uses anything about the consumer except the home, area, price, lead type and
language they chose. Every set of three shown is logged with the reasons, for the Fair Housing audit pack.

## 8. Reviews

- **Movewise reviews:** only from people who connected with that agent through Movewise, asked after one week and
  after closing. They are shown with "Verified Movewise client".
- **Imported reviews:** from Google, Zillow, RealSatisfied and Testimonial Tree when those connectors exist
  (Connect C8). Each is shown with its source and date, and never mixed into the Movewise average without saying so.
- **The rules:**
    - no paying for reviews and no hiding bad ones;
    - the agent can reply publicly once;
    - Movewise removes a review only for abuse, personal data or proven falsehood, and logs why (this follows the
      FTC rule on consumer reviews).

## 9. Pricing models (set by HQ per state)

HQ can turn on any of these in each state and set the prices:

| Model                              | When it is charged                                                                              | Notes                                                                                                                        |
| ---------------------------------- | ----------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| **Per connection**                 | When the chosen agent accepts. The consumer has already picked them and tapped Connect          | Price can vary by lead type and price band. Credited back if the phone number is wrong or the person says they never asked   |
| **Per appointment**                | When an appointment the person booked is marked held by both sides                              | Higher price, lower risk for the agent                                                                                       |
| **Share at close** (revenue split) | At closing, as a referral fee from the agent's brokerage to the state's managing broker partner | Only in states HQ enables. Percentage set by HQ. Referral agreement signed electronically at accept. Invoice raised on close |

- **Mixing models:** a package may combine a smaller per-connection fee with a lower share at close, if HQ allows
  both in that state.
- **Fallback for share at close:** if a "share at close" lead shows no status updates for 30 days, it falls back to
  the per-connection price, as stated in the terms.
- **Price changes:** HQ changes prices in settings. Changes apply to new connections only.

## 10. The bridge: the person only ever talks to their own agent

- **Relay number and email.** Each connected person gets a **Movewise relay**:
    - a local number from HQ's number pool;
    - a reply address on a subdomain of movewisehomes.com, such as `reply.movewisehomes.com`.

    The agent texts, calls and emails through it from their RightAlly inbox. The person sees one Movewise number and
    one thread, and the agent's personal number is not needed.

- **Consent record.** For each connection, Movewise saves:
    - the exact wording shown and the agent's name;
    - the way of contact chosen and the time;
    - the person's IP address and device;
    - the page.

    This proof of consent goes with the lead to the agent and to the audit pack. Consent covers **one named agent**.
    It is not transferred if the person changes agent; they choose again.

- **Quiet hours.** Messages only between **8am and 9pm in the person's local time**, plus stricter state rules where
  they apply. Calls and texts outside those hours are held.
- **No bulk messaging.** While a person is bridged, their contact is marked _Movewise_: they can't be added to bulk
  texts, drip campaigns or exports. Only personal messages and the alerts the person asked for go out.
- **Stop words.** STOP, pause and change agent work from any message and from My Movewise.
- **Handing over the number.** After an agreement is signed or the deal closes, the agent can ask to move the person
  to the agent's own number. The person must say yes. The relay then sends one goodbye message, and Movewise steps
  back.
- **Data ownership.** The agent's copy of the lead lives in their own RightAlly database. Movewise keeps its own
  consumer record. Leads from a brokerage's own website or ads are **never** sent to Movewise or resold.

## 11. HQ console

- **States:** on or off, managing broker partner and licence details, models enabled, prices, revenue-split
  percentage, legal checklist status.
- **Agents and brokers:** applications, checks, profile approvals, tiers, strikes and pauses.
- **Leads:** pool by state and score; the sets of three shown and choices made; connections, appointments and
  closings; refunds and disputes.
- **Money:**
    - revenue by model and state;
    - ad spend against connections (Movewise's own cost per connection);
    - share-at-close invoices and partner settlements.
- **Quality:** consumer ratings, stops and complaints, and Fair Housing audit exports.

## 12. Legal checklist per state (a gate before switching a state on)

- The managing broker partner agreement is signed, covering the referral fee split, broker supervision and
  responsibility for the site in that state.
- **Required notices are on the site in that state.** For Texas this means the TREC **Information About Brokerage
  Services** and **Consumer Protection Notice** links. Every state is checked by counsel.
- **Referral-fee rules are confirmed.** Share-at-close is paid only between licensed brokers. Fixed-price
  introductions are reviewed as advertising or lead fees under that state's rules.
- **RESPA:** no lender, title or insurance company pays Movewise for referrals. Any future partner is reviewed
  first.
- **TCPA and state telemarketing rules:** consent wording reviewed, plus any state registration needed for texting.
- **MLS rules:** the IDX or VOW display rules and attribution for each MLS are agreed with the partner.
- **Privacy notice:** the consumer privacy notice covers how data moves to the chosen agent.

## 13. How it is built (architecture)

- **Movewise is an HQ module on the control plane,** with its own public host or hosts and its own database
  schema. Consumers are not tenant users. Consumer accounts, saved homes, chats, scores, matching and the relay
  routing live in the Movewise schema.
- **Listings** come from the existing Listings engine, loaded under each managing broker partner's MLS licences.
  These are Movewise feeds. They are separate from tenant feeds and do not share their licences.
- **Introductions into the agent's CRM** use the existing tenant intake (`ConnectedLeads` into LeadIntake, with
  LeadSource _Movewise_). Each connection carries its external id for two-way status.
- **Status flows back** from tenant events (contact stage, appointment, agreement, deal stage and close) through a
  control-plane outbox. That feeds the scorecard, appointment charges and share-at-close invoices.
- **The relay** uses HQ's Twilio number pool and an inbound email domain. Messages are routed by relay id into the
  right tenant's inbox.
- **Charges** go through the credit ledger (`CreditLedger::charge` and `refundPart`), keyed by connection id, so a
  charge can never happen twice.
- **SEO and AEO** follow `rightally-seo-aeo-standard`:
    - server-rendered pages;
    - schema.org markup (`RealEstateListing`, `Place`, `FAQPage`, `Person` for agent cards);
    - pages for every state, city, zip code and neighbourhood, with market facts and plain-English answers;
    - sitemaps for each state.

## 14. Open items (to settle before or during MW1)

1. Trademark clearance and a US filing for "Movewise Homes" in real estate and online services. A 2019 "MOVEWISE"
   filing for relocation services was abandoned; the lawyer confirms no one still trading under that name holds
   rights in it.
2. Counsel review in all four states (section 12).
3. Launch prices for each model and state, and the revenue-split percentage. HQ sets these in settings; the defaults
   are agreed before launch.
4. The managing broker partner contracts, including MLS feed licences.
5. The budget for Movewise's own ads at launch, and the target cost per connection.

## 15. Sprints (see the delivery plan for order)

| Sprint | Delivers                                                                                                                                                                  |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MW1    | Movewise module and host, state switches and managing broker partners, legal pages and notices, listing feeds for the 4 states, search and home pages (SEO)               |
| MW2    | Consumer accounts (passwordless), saves and alerts; AIlly for Movewise: true monthly cost, affordability, home value, net sheet, move planner; Spanish                    |
| MW3    | Agent and broker program: packages, application and licence checks, profile card, areas, caps, hours, credits; free Movewise Agent seat                                   |
| MW4    | Readiness score and brief; choosing three agents; the "show 3" screen, consent capture, accept timer, per-connection charges and refunds                                  |
| MW5    | The bridge: relay numbers and email, quiet hours, bulk-messaging block, pause, stop and change agent, number hand-over                                                    |
| MW6    | Status flow-back, scorecard and tiers, per-appointment and share-at-close models, referral agreements, invoices and partner settlements, HQ console                       |
| MW7    | Reviews (verified Movewise reviews, imported reviews from Connect C8) and the growth engine: city, zip and neighbourhood pages, market reports, ads top-up with stop-loss |
| MW8    | Launch hardening: load tests, Fair Housing audit pack, consent audit, state legal sign-off, then go live in Texas first, then Louisiana, Mississippi and Alabama          |

## 16. Acceptance (whole program)

- A person can search, save, ask AIlly and plan a move in any launch state **without giving a phone number**, and
  nobody contacts them.
- When ready, the person sees three eligible agents with photo, rating and reviews, chooses one and a way of
  contact, and **only that agent** can reach them, through the relay, within quiet hours.
- The chosen agent is charged once, only after accepting. Bad contacts are refunded to the same wallet.
- Change agent, pause and stop take effect immediately and are respected by every channel.
- A bridged contact can't be added to bulk campaigns or exports.
- Each state has its own on/off switch, prices, models, revenue split and managing broker. Turning a state off stops
  new introductions there, and existing ones carry on.
- Share-at-close deals produce a referral agreement at accept, and an invoice to the agent's brokerage at close.
- Every set of three shown, every consent and every charge can be audited.
