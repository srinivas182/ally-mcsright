# ADR 0003: Laravel 13, PHP 8.5, React 19 with Inertia

Status: **Accepted**

## Context

The team's strength is Laravel; the product needs a premium, fast, SEO-friendly front end and one API for web, Flutter and partners.

## Decision

Laravel 13 on PHP 8.5 with Octane; React 19, TypeScript, Inertia 3 (SSR for public pages), Tailwind 4; started from the official React starter kit (Fortify auth, 2FA, passkeys).

## Alternatives considered

- Vue 3: team preference is React for new projects.
- Separate SPA + API only: more moving parts without benefit for logged-in portals.

## Consequences

- Every screen also uses the versioned API so Flutter apps can follow.
