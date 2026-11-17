# Performance and scale (PS1, PS2)

**Status:** PS1 built (cell automation, query budgets). PS2 adds load scripts and a big test brokerage. The full
100,000-user load test runs at launch (Sprint 59).

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

Still to do (PS2): the dashboard, database insights, credits, roster and agent billing pages.

## For review

The 85% / 100% / 75% thresholds, and the default cell size (2,000 brokerages or 50,000 agents).
