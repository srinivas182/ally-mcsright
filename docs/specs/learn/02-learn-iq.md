# Learn 02: Learn-IQ courses (LN2)

Status: built in LN2. Tracker row E20, part 1 of 2; LN3 adds the AI role-play academy. Module: `modules/Learn`.

## What the market leaders do

- **Training programmes**
    - **Keller Williams (BOLD, Ignite)** and **eXp (Mentor Program)** run cohort programmes with set schedules.
    - **Tom Ferry** and **Buffini** sell coaching outside the brokerage's systems.
- **Mentor pairing.** Most brokerages pair mentors on a spreadsheet and check in by email.
- **Where they fall short:**
    - Launch plans are not tied to what the agent actually did in their tools.
    - Nobody sees a late step until the 90-day review.
    - Training is never required before an agent uses a risky tool, such as mass texting or cold-calling.
    - Writing a course takes a broker days.

## What RightAlly does

### New Agent Launch: 30 days, 11 small steps (People › Learn › Launch, mentors and scorecard)

**Starting a launch.** The broker starts a launch for a new agent and picks a mentor. Mentors with room are
suggested first, fewest current mentees first.

**Some steps tick themselves:**

- two-step sign-in turned on;
- "RightAlly in 20 minutes" finished (assigned automatically, due on day 2);
- every required office page confirmed.

**Other steps:**

- The agent ticks their own steps (photo, sphere import, first 10 calls, shadowing, open house), or the mentor or
  broker ticks them.
- Only the broker or the mentor can tick the **30-day check-in**.

**Late steps** show in red with their due date, both on the agent's page and in the broker's list.

### Mentors

- **Offering.** Any agent can offer to mentor: what they are good at, and at most 1–5 new agents at a time.
- **Mentor view.** A mentor sees each mentee's steps and can tick them.

### Training required before a tool

The broker can require a published course before these tools:

| Tool                                                            | Where it is checked         |
| --------------------------------------------------------------- | --------------------------- |
| **Automatic follow-up plans** (texts and emails to many people) | Crm `FollowUpPlans::enroll` |
| **Prospector** (calling homeowners who are not yet clients)     | Crm `Prospector::toContact` |

- **Message.** The agent sees one sentence naming the course and how long it takes. Finishing the course opens
  the tool straight away.
- **Exemptions.** Owners are never blocked.
- **Defaults.** With no requirement set, or without the Learn module, everything stays open.
- **How it works.** A Core contract, `Modules\Core\Learning\TrainingGate` (default: allow all), so other modules
  can ask without depending on Learn.

### AI course builder (Manage training › Build a course with AI)

- **Input.** A topic, plus optional notes pasted by the broker.
- **Output.** AI drafts 3–6 short lessons in plain English and a quiz. It costs 5 credits.
- **Checks.** Quiz questions with fewer than two answers, or with no right answer, are dropped.
- **Grounding.** With notes, the course must stay within them. Without notes, it must not state laws, fees or
  deadlines, and must say "check your state's rules".
- **Draft only.** It is saved as a **draft**, with a banner telling the broker to check every fact. Nothing
  reaches agents until a person publishes it.

### Skill scorecard

- **Skills.** Courses can be tagged with a skill: finding clients, listings, buyers, contracts, rules, or using
  RightAlly.
- **What each person sees.** For each skill: courses finished, their quiz average, and the next course to take.
  Only the person and the broker see it; it is never a public ranking.

## Security

- **Permissions.** Starting launches, setting required training and building courses need `learn.manage` (or
  owner or staff).
- **Ticking steps.** Only the agent, their mentor or the broker can tick, and the check-in only the last two.
- **AI input.** Pasted notes go to AI as untrusted content.
- **Audit log.** Gates, launches and course creation are recorded.

## LN4: Market mastery, accountability pods, live call cards (rest of E20)

- **Know your market** (`/learn/market`, `Learn\MarketQuiz`). Five questions a day on the agent's own area, from today's real MLS numbers:
    - typical asking price;
    - homes for sale;
    - price per square foot;
    - days on market;
    - new listings in the last 30 days.

    Each question has four choices; the wrong ones are 15–40% away. The same area gives the same quiz all day. Answers are checked on the server and never sent to the page. The score and a days-in-a-row streak are kept.

- **Accountability pods** (`/learn/pods`, `Learn\Pods`). Two to six agents (or owners) who each set one goal a week, then add what they did and where they need help.
    - The pod page shows everyone's check-in next to the week's numbers RightAlly already has: calls logged, appointments and new deals.
    - Only members, and the brokerage's managers, can open a pod. Anyone can leave.
- **Live call cards** (`Outreach\CallCards`). On the call queue, "If they say…" chips cover eight common moments, from "I'm busy" to "Please don't call me again". Tapping one shows a short, honest reply and a tip.
    - The cards contain no pressure tactics and are Fair Housing safe.
    - "Commissions are negotiable and not set by law."

**Agent licence look-up** (Outside services → Agent licence look-up, `Roster\Verification\ServiceVerifier`):

- **State public licence data** (recommended, free): HQ loads a state's published licence list with `php artisan roster:licence-data TX file.csv`. Licences in that state are then checked automatically (monthly, and on demand), and the result is recorded as `state_data`. A state without a loaded list is left for a person to verify; it is never flagged.
- **ARELLO:** waits for ARELLO's data agreement and API specification.
- **Typed in by hand:** staff verify, as before.

Tests: `tests/Feature/Crm/DatabaseChecksTest.php`.
