# Websites 01 — Design system and theming (Team C, Sprints 1–3)

## Purpose

One premium design language used by every RightAlly screen — HQ console, back office, agent app,
client portal and public websites — so RightAlly looks and feels like a product from a
billion-dollar company, and every brokerage can apply its own brand without making it look cheap
or inaccessible.

## Source of truth

- **Figma** library owned by the product designers (Sprint 0 visual direction approved first).
- Design tokens exported from Figma (Tokens Studio JSON) into `resources/design/tokens.json`.
- A build script generates CSS custom properties and the Tailwind 4 theme from the tokens; no
  hard-coded colours, sizes or shadows in components (lint rule).

## Tokens

| Group       | Contents                                                                                                                                                                                                                    |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Colour      | Brand primary, secondary and neutral 11-step scales (50–950) in OKLCH; semantic tokens: `surface`, `surface-raised`, `text`, `text-muted`, `border`, `focus`, `success`, `warning`, `danger`, `info`; light and dark values |
| Typography  | Curated font pairings (8, matching spec core/05); type scale (xs–5xl) with line heights; weights 400/500/600/700                                                                                                            |
| Spacing     | 4 px base grid (0.5–24 steps)                                                                                                                                                                                               |
| Radius      | none, sm, md, lg, xl, full                                                                                                                                                                                                  |
| Elevation   | 5 shadow levels; dark-mode equivalents                                                                                                                                                                                      |
| Motion      | Durations (fast 120 ms, base 200 ms, slow 320 ms) and easing curves; all motion off under `prefers-reduced-motion`                                                                                                          |
| Breakpoints | sm 640, md 768, lg 1024, xl 1280, 2xl 1536 (mobile first)                                                                                                                                                                   |
| Z-index     | base, dropdown, sticky, overlay, modal, toast                                                                                                                                                                               |

## Theming engine

- Takes a tenant's palette from settings (spec core/05) and writes CSS variables on the root
  element for that tenant; falls back to the RightAlly default theme.
- **Back office and agent app:** neutral RightAlly surfaces with the tenant logo and a primary
  accent only (consistent usability across brokerages).
- **Websites and client portal:** full tenant palette and font pairing.
- Light and dark mode for every surface; user preference stored; default follows the system.
- Contrast guard: components only use semantic tokens whose pairings passed the AA check.

## Components

Built on Radix primitives and the starter kit's component base, restyled to the tokens. Each
component has: all states (default, hover, focus-visible, active, disabled, loading, error),
light/dark, keyboard behaviour, ARIA attributes, RTL-safe layout, Spanish text-expansion check
(+30% length), and a story with usage notes.

| Sprint | Components                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1      | Tokens pipeline, theming engine, Button (primary, secondary, ghost, destructive, icon, sizes), Input, Textarea, Select, Combobox, Checkbox, Radio, Switch, Label, Form field (label, help, error), Badge, Avatar, Tooltip, Spinner, Icon set (Lucide)                                                                                                                                                          |
| 2      | Dialog, Drawer (mobile sheet), Popover, Dropdown menu, Toast, Tabs, Accordion, Card, Stat card, Data table (sort, select, pagination, column visibility, responsive stacking), Empty state, Skeleton, Pagination, Breadcrumbs, Alert/banner, Progress                                                                                                                                                          |
| 3      | Back-office shell (sidebar with module groups, top bar, tenant switch for HQ, user menu, search/command bar slot, notifications slot), agent mobile shell (bottom navigation, quick actions), page header, filter bar, form layouts, settings layout, wizard/stepper, file upload with progress, date and date-range picker, money and percentage inputs, phone input, address input, chart wrapper (Recharts) |

## Component workbench and visual tests

> **Updated in Sprint 2C (ADR 0011):** Storybook is incompatible with the Vite+ toolchain without
> disabling peer-dependency checks project-wide, so the in-app workbench at `/dev/design` is used
> instead (local, and staging with `CORE_DESIGN_GALLERY=true`). Component tests include axe-core
> accessibility checks. Screenshot regression is deferred until it can be verified (Sprint 3C).

Original plan:

- Storybook (React, Vite) for every component, published from CI artefacts on demand (not on
  every run).
- Playwright visual-regression snapshots for components and shells in light/dark and mobile/desktop.
- CI policy: visual tests run only when files under `resources/js/components`,
  `resources/design` or `resources/css` change.

## Quality bars

| Area          | Bar                                                                                                                                             |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| Accessibility | WCAG 2.2 AA: contrast, focus visible, full keyboard use, screen-reader labels, target size ≥ 24 px                                              |
| Performance   | Shared component bundle ≤ 120 KB gzip; each page route ≤ 200 KB gzip JS on first load; no layout shift from fonts (font-display swap + metrics) |
| Consistency   | Lint bans arbitrary Tailwind values for colour, spacing and shadow outside `components/ui`                                                      |
| Polish        | Designer sign-off on every component before merge (screenshot review in the PR)                                                                 |

## Acceptance criteria

1. Changing a token in `tokens.json` updates every component after the build, with no component
   code changes.
2. A tenant palette applies to websites fully and to the back office as logo + accent only; dark
   mode works everywhere.
3. Every listed component exists with all states, stories and visual snapshots, and passes axe
   accessibility checks in tests.
4. Shells work on a 360 px wide phone and a 1440 px desktop.
5. Bundle budgets are enforced in CI (build fails when exceeded).
6. Designer sign-off recorded on each PR that changes components.
