# ADR 0009: Legacy CodeIgniter code is reference only

Status: **Accepted**

## Context

The legacy platform has no tests, no foreign keys and critical security issues; it is being replaced, not repaired.

## Decision

No legacy code is reused or fixed. It is the reference for business rules (docs/legacy/business-rules.md), schema mapping and the data migration of existing customers. The legacy platform stays in maintenance mode until customers move.

## Alternatives considered

- Refactor the legacy app first: rejected (months of throwaway work).

## Consequences

- Legacy migration tooling and a to-the-cent parallel commission run come before each customer moves.
