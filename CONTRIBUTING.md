# Contributing

## Sprint workflow

1. Every sprint starts from a detailed specification in `docs/`.
2. Work happens on a short-lived branch: `sprint/<number>-<topic>`.
3. Open a **draft** pull request early; drafts run only the quick CI checks.
4. Run all quality checks locally before pushing (see README).
5. Mark the PR **ready for review**; the full suite runs once.
6. A sprint is done only when CI is green and the PR is merged to `main`.
   The next sprint starts after that.

## CI minutes policy

- Draft PRs: style, static analysis and types only.
- Ready PRs: full tests and production build, once.
- No re-run on `main` after merge.
- Docs-only changes skip CI.
- Superseded runs are cancelled automatically.
- Combine small related sub-sprints into one PR.

## Code standards

- PHP: strict types in modules, PHPStan level 8, Pint style.
- Thin controllers; business logic in module services and domain classes.
- Every tenant-owned model is tenant-scoped; every change is audited.
- AI only ever receives data the requesting user is allowed to see.
- No secrets in code: configuration comes from `.env` via `config/`.
