# Performance and scale (PS1, PS2)

**Status:** PS1 and PS2 built. The full 100,000-user load test runs at launch (Sprint 59).

## Cells that look after themselves (PS1)

Brokerages live in **cells**: databases that each hold many brokerages, with soft limits for brokerages and
agents. **HQ › Cells** shows every cell, how full it is (the higher of the two limits), its status and its last
check.

A watcher runs every 10 minutes (`cells:watch`):

| When                                             | What happens                                                                    |
| ------------------------------------------------ | ------------------------------------------------------------------------------- |
| A cell reaches **85%**                           | HQ is emailed (at most once a day) to add a cell                                |
| A cell reaches **100%**                          | It closes itself to new brokerages ("Full (automatic)"); existing ones carry on |
| An automatically closed cell drops below **75%** | It reopens on its own                                                           |
| A person closed the cell                         | It is never reopened automatically                                              |
| No shared cell has room                          | HQ gets an **urgent** email: new signups would fail                             |

**Adding a cell** (password confirmed): HQ creates the empty database, then enters its name, region, host,
database, user and password, and how many brokerages and agents it should hold. Details are stored encrypted and
never shown again. RightAlly connects, copies the table structure from the main cell, checks every table is
there, and only then opens the cell. A cell that fails its checks stays closed ("Not open yet") and can't be opened
by hand until a check passes. The nightly safeguards job keeps added cells' tables in step after each release.

## Query budgets (PS1)

The busiest lists ran one or more database queries **per row**, so a brokerage with 300 agents or deals waited
much longer than one with 10. PS1 rewrote them to fetch everything in a fixed number of queries:

- Deals list: what each deal is missing, and the agent's name (3 queries per deal before).
- Directory: offices, teams and licences (3 per person before).
- Agent activity: outreach, tasks, deals, courses, practice, "keeping agents" signals, milestones and launch
  programme (about 10 per agent before).
- Connected AI assistants (1 per person before).

`tests/Feature/Scale/QueryBudgetTest.php` fails if any of these pages runs more than two extra queries when the
brokerage grows from a handful of agents to fifteen with dozens of deals. New list pages should be added there.

PS2 did the same for:

- Credits (each agent's balance and use: 4 queries per agent before).
- Roster compliance (credentials, documents and W-9s: 3 per agent before).
- Agent billing (what each agent owes, invoice names; the chart of accounts check went from about 40 queries on
  every view to 1).
- Database insights: the per-agent table (about 15 queries per agent) is worked out at most every 10 minutes per
  brokerage; the brokerage's own report on the same page is always live.

Known remaining: agent billing still looks up each agent's commission-payable account (2 queries per agent), and
the dashboard checks a brokerage setting several times per view. Both are constant or small and are left for
after launch measurements.

## Load tests (PS2)

- `php artisan perf:seed loadtest-… --agents=200 --contacts=50000 --deals=5000 --password=…` fills a load-test
  brokerage on staging with made-up data. It refuses production, any brokerage whose address doesn't start with
  `loadtest`, and short passwords. Every email ends in `.invalid` and phones are 555-01xx fiction numbers, so
  nothing can reach a real person.
- `tests/load/app.js` (k6): 500 signed-in agents moving between their busiest pages, 1,000 website visitors, and 5
  website enquiries a second, together for 15 minutes. Pass marks: p95 under 800 ms for pages (1.5 s for report
  pages like the dashboard), under 1% errors, CDN bypassed. `tests/load/README.md` has the steps.
- The 100,000-user run (Sprint 59) uses the same script scaled out over many load generators.

## For review

The 85% / 100% / 75% thresholds, and the default cell size (2,000 brokerages or 50,000 agents).
