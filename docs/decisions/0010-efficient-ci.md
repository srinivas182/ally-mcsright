# ADR 0010: Efficient CI

Status: **Accepted**

## Context

GitHub Actions minutes are limited and were exhausted on a previous project.

## Decision

Draft PRs run quick checks only; the full suite runs once when a PR is ready; no re-run on main; docs-only changes skip CI; superseded runs are cancelled; checks run locally before pushing; small related sub-sprints share one PR.

## Alternatives considered

- Run everything on every push: rejected.

## Consequences

- Each sprint is done only when CI is green and merged.
