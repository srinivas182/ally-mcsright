# Movewise MW4: choosing an agent

Status: **Built** (sprint MW4). Follows [00-overview](00-overview.md) §4.3–4.5, §5 and §7.

## 1. The person's side

1. **"Talk to an agent"** on any home, or **"Selling? Talk to a local agent"** on the home page. The person must be signed in (MW2).
2. **About your move:**
    - first name, buying or selling, timeline, pre-approval, needs to sell first, language;
    - for sellers, their home's ZIP code, city, state and an optional value;
    - "I already have an agent".
      People who already have an agent are never shown agents. They are told to bring their agent along.
3. **Three agents** (section 3), shown on a server-rendered page. Each card shows:
    - photo, name, brokerage, licence number with a link to the state's own licence lookup;
    - years of experience, languages and the approved introduction;
    - "New to Movewise: no reviews yet", until MW7 adds reviews.
4. **Verify the mobile.** A 6-digit code by text (10 minutes, 5 codes an hour, 5 tries). The number is stored encrypted and goes only to the agent the person chooses.
5. **Choose one agent and one way to be contacted:**
    - "Text me";
    - "Call me";
    - "Pick a time", from the agent's real open times in the booking engine (CRM availability), shown only when they have some.

    **The tap on "Connect with Ann" is the consent.** The wording, saved word for word with the time and IP, names:
    - the agent, their brokerage and licence;
    - the way they may make contact, and the masked number;
    - that it isn't a condition of purchase, that automated technology may be used, message and data rates, and STOP.

    The other two agents are not told and not charged.

6. **My Movewise** shows the status of the introduction: waiting (with the deadline), working with Ann, not answered yet, or declined. It links to choose another agent or to wait.
7. **Only one open introduction at a time** for each kind of move (buying or selling).

Every Movewise POST checks that it came from Movewise's own pages (`Http\Guard`) and is rate-limited. Movewise has no session, so a refused form shows its messages on a page with a 422. JSON callers get the usual 422 JSON.

## 2. Readiness score (0–100)

`Matching\Readiness` is a rules-based score. The Lead-IQ model trained on Movewise outcomes replaces it later.

| Signal                              | Points                                           |
| ----------------------------------- | ------------------------------------------------ |
| Timeline                            | within 3 months 30; 3–6 months 18; 6–12 months 6 |
| Pre-approved                        | 12                                               |
| Email verified                      | 8                                                |
| Mobile verified                     | 12                                               |
| Saved homes                         | 3 each, up to 15                                 |
| Saved searches with alerts          | 4 each, up to 8                                  |
| Questions to AIlly                  | 2 each, up to 10                                 |
| Moving date set in the move planner | 5                                                |

The agent sees the score with its reasons. The score never decides who may connect.

## 3. Choosing the three (`Matching\Matcher`)

**1. Eligible.** An agent is eligible only when all of these hold:

- an active package and an active agent, both in the home's state;
- the agent's account is active, not suspended;
- the package covers the ZIP code, or the city (case-insensitive);
- the price is inside the package's range;
- the lead type and language match;
- the package is under its monthly cap and its daily cap. Both offered and accepted introductions count.

Agents the person tried in the last 30 days (no answer or declined) are left out. An agent in two packages counts as one candidate.

**2. Ranked:**

- tier first: Preferred, then Standard, then New;
- then fair rotation: fewest times shown in the last 7 days;
- then a random tie-break. The list is shuffled before a stable sort.

**3. Shown.** The top three. If none of the three has gone unshown in the last 7 days and an unshown eligible agent exists, that agent takes the third place.

**4. Logged for Fair Housing.** Every set shown is saved in `movewise_shows`: what it was based on (state, ZIP code, city, price, lead type, language, home) and each agent's reasons. Nothing else about the person is read.

## 4. The agent's side

- **Email:** "Someone chose you on Movewise: accept by 10:05 AM CDT", with a link to the introduction.
- **The introduction** (back office → Movewise → Introductions):
    - a live countdown;
    - the brief: what they told us, readiness and reasons, the home, saved homes, what to say first, the consent text and the full AIlly conversation;
    - contact details only after accepting.
- **Accept timer** (`AcceptClock`), both limits set by HQ in config:
    - inside the package's working hours: `movewise.accept_minutes` (5);
    - outside them: until `movewise.after_hours_minutes` (30) after the next start.
- **On accept:**
    1. **Charge.** The state's per-connection price for the lead type, in credits, as action `movewise.connection` with quantity equal to the price. It comes from the agent's wallet, or the brokerage pool for broker packages. It is keyed by the connection, so it can never be charged twice. With too few credits the accept is refused with a top-up message, and the timer keeps running.
    2. **The person goes into the CRM:**
        - through `LeadIntake`, with lead source "Movewise", assigned to the agent;
        - with text consent recorded when they chose text or a time;
        - with the AI first responder skipped, because the agent contacts them personally;
        - with the brief and consent on the timeline.
    3. **"Pick a time"** books the slot as a task on the agent's calendar (`Availability::book`).
    4. **The person is emailed:** "Ann will be in touch", with a signed **"Didn't ask for this? End it here"** link.
- **Decline:** the person is told politely and can choose one of the others. Nothing is charged.

## 5. When nobody answers

`movewise:expire-offers` runs every minute. Each offer past its deadline:

- expires, and nobody is charged;
- the person is emailed: "Ann hasn't been able to reply yet", with **Choose another agent** (the same three, minus Ann) and **Wait for Ann**.

Waiting gives Ann one fresh deadline, once. The introduction never passes to another agent without the person's choice.

## 6. Refunds

- **The person never asked:** the signed link from the email ends the introduction and refunds the charge in full, automatically. A note goes on the contact's timeline: "don't contact them again". The agent is told politely.
- **Wrong number or never asked, reported by the agent:**
    - within `movewise.refund_days` (7) of accepting, with a reason;
    - HQ decides in HQ → Movewise agents → Refund requests (password confirmed);
    - a refund goes back to the wallet that paid, through `CreditLedger::refund`.

## 7. Not in MW4

- **MW5:**
    - relay numbers and email;
    - quiet hours and the bulk-messaging block;
    - pause, stop and change agent from My Movewise.

    Until then, contact details go to the accepting agent under the consent the person gave.

- **MW6:**
    - per-appointment charges, when an appointment is held;
    - share-at-close invoices;
    - the scorecard and tier recalculation.
- **MW7:** reviews on the cards.
- **Agent alerts:** agents get email alerts now. Text alerts to agents come with the relay in MW5.

## 8. Tests

`tests/Feature/Movewise/MovewiseMatchingTest.php` covers:

- **Accept clock:** inside hours, before the start, overnight, and across the weekend.
- **Before agents are shown:** people with an agent see none; readiness.
- **Matching:** eligibility (price range, ZIP code, Spanish), tier order, and the Fair Housing log.
- **Choosing:** mobile verification, the origin check, the consent text, only the chosen agent notified, one open introduction at a time.
- **The brief:** hidden contact details before accepting.
- **Accepting:** a single charge and the lead in the CRM.
- **Endings:** the signed withdraw link with a full refund; expiry and choosing again (with no charge); waiting once.
- **"Pick a time":** booked on the agent's calendar.
- **Refunds:** the agent's refund request decided by HQ.
