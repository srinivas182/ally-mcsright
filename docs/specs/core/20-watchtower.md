# 20 · Watchtower: problems found, explained and emailed (OB1)

**Status:** built in OB1 (Nov 2026), ported from the Watchtower built for WURK and adapted to RightAlly.

Watchtower watches all of RightAlly for HQ. When something breaks it groups the errors into one problem, has the AI
find the likely root cause, and emails the people HQ chose a **fix request** to paste to the engineering team.
HQ › Watchtower shows every problem, the state of each system, and the switches.

## What it watches

| Kind            | How it is caught                                                                                                                                     |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Server errors   | Every reported exception (web, background jobs, commands)                                                                                            |
| Failed jobs     | A background job that used up its retries (always at least high)                                                                                     |
| Scheduled tasks | Each task reports when it starts and finishes; one that fails, or misses its time by 10 minutes, is a problem                                        |
| Systems         | Every minute: each database (control, cells, listings, audit), cache, file storage, and the queue (over 5,000 waiting or the oldest over 10 minutes) |
| Browser errors  | Crashes in people's browsers (at most 5 reports a page); switchable                                                                                  |
| Slow pages      | A 2% sample of every route, plus every request over 2 s; a route with more than 5% over 300 ms, or 5+ over 2 s, in an hour; switchable               |
| Downtime        | The outside uptime checker's webhook (Better Stack recommended, or UptimeRobot)                                                                      |

Personal data is removed before anything is kept, emailed or sent to the AI or Sentry: emails, phone, card and
account numbers, SSNs, ids, tokens, provider keys and quoted values. Traces keep file and line, never arguments.

## Problems and severity

- One problem per fingerprint: the error's class, the first place in RightAlly's own code, and the message with
  numbers taken out. A thousand identical errors are one problem.
- Severity only goes up. **High** where money, deals and signatures, leads, messages or signing in are involved, or
  20+ times in an hour. **Critical** when 100+ an hour hit 5+ brokerages, a sensitive place fails at 3+ brokerages,
  a system check fails, or RightAlly is down. The AI can raise it, never lower it.
- Statuses: new, acknowledged (no more emails unless it gets worse), fixed in a release (if it happens again it
  "came back" and emails again), ignored (counted, never emailed), resolved (quiet for 7 days; reopens if it returns).

## Emails (switchable)

- **Problem emails:** critical and high problems straight away, one per problem, with the fix request attached. Again
  only if it came back after a fix, got more serious, or happened ten times more. At most 20 a minute. High problems
  wait up to 3 minutes for the AI's explanation; critical ones never wait.
- **Daily report:** 8 am Central, new problems, what is open, what is failing.
- **Back up:** when a failing system recovers, and when downtime ends (with what else went wrong meanwhile).
- **Who:** up to 5 addresses set in HQ › Watchtower; until then the `WATCHTOWER_EMAILS` setting on the server.

## The AI root cause (switchable)

For a problem worth explaining (critical or high, came back, or a normal one seen 5+ times) the AI reads the masked
error, the trace, the lines of our own code around it (from the running release) and earlier problems in the same
file, and returns a summary, the likely cause, a suggested fix with a test, a severity and its confidence. It never
changes anything. Paid by HQ (feature `hq.watchtower`, prompt `watchtower.explain`), at most the daily limit HQ sets
(30 to start). Its words go into the email and the fix request. HQ can ask it again from the problem page.

## Error tracking: Sentry (switchable)

HQ › Outside services › **Error tracking**: Sentry (recommended), Bugsnag or Honeybadger, each Off, Test or Live.
Test sends with the environment "test"; Live with the server's environment. Only what Watchtower keeps is sent
(already masked), at most once a minute per problem, from the queue. Checking the Sentry connection sends one test
event. Watchtower works fully without it.

## Uptime checker

The one thing Watchtower cannot report is RightAlly itself being down, so an outside checker does.

1. In HQ › Watchtower, make the uptime address (password needed; making a new one stops the old one).
2. In Better Stack (or UptimeRobot), check `https://app.rightally.io/up` (the site) and
   `https://app.rightally.io/up/scheduler` (fails with 503 when the scheduler has stopped for 5 minutes).
3. Send its alerts to the uptime address. The checker emails you itself; Watchtower records the outage for the
   30-day uptime figure and, when it is over, emails what else it saw.

## Server settings

| Setting                     | What it is                                                                              |
| --------------------------- | --------------------------------------------------------------------------------------- |
| `APP_RELEASE`, `APP_COMMIT` | The release and commit, set by the build, so problems say which release they started in |
| `WATCHTOWER_EMAILS`         | Who gets emails until HQ names people (comma-separated)                                 |
| `WATCHTOWER_TIMING_SAMPLE`  | Share of requests timed for slow pages (default 0.02)                                   |

Scheduled: `watchtower:check` every minute, `watchtower:report` daily at 8 am Central, `watchtower:sweep` nightly.
