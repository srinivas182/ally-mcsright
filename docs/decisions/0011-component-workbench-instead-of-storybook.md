# ADR 0011: Component workbench instead of Storybook (for now)

Status: **Accepted** (revisit when Storybook supports Vite+)

## Context

The design-system spec (websites/01) planned Storybook for component review and Playwright
screenshot tests for visual regression. In Sprint 2C:

- Storybook 10 requires Vite 5–8; this project builds with Vite+ (`vite` aliased to
  `@voidzero-dev/vite-plus-core` 1.x). npm refuses the install unless peer-dependency checks are
  disabled for the whole project, including CI.
- Screenshot tests need a headless browser download that the build sandbox cannot reach, so a
  CI job could not be verified before merging (CI-green rule).

## Decision

- Use the in-app **component workbench** at `/dev/design` for design review: every component,
  every state, light and dark, phone and desktop widths. Always on locally; enabled in staging
  with `CORE_DESIGN_GALLERY=true`; never in production (tested).
- Run **automated accessibility checks (axe-core)** in the component tests on every pull request.
  Colour contrast is covered separately by the theme builder's WCAG checks.
- Do **not** disable peer-dependency checks project-wide.

## Alternatives considered

- Storybook with `--legacy-peer-deps`: rejected (weakens dependency safety everywhere).
- Ladle or Histoire: same Vite peer constraint; not worth a second toolchain.
- Hosted visual testing (e.g. Chromatic): possible later; needs a paid account decision.

## Consequences

- Designers review in the running app, with real tokens and real tenant theming.
- Visual regression remains manual (designer sign-off on PR screenshots) until a verified
  screenshot job is added; tracked for Sprint 3C.
