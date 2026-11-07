# ADR 0006: RightAlly Credits priced per action

Status: **Accepted**

## Context

AI, SMS, calls, enrichment and video have real variable costs; brokers need predictable, understandable pricing.

## Decision

One credit currency priced per action from an HQ rate card. Everyday AI is included in seats with fair-use limits; heavy usage consumes credits. Packages include credits; HQ sees cost and margin when building packages; brokerages allocate allowances to agent plans.

## Alternatives considered

- Per-token pricing for users: rejected (not understandable).
- Unlimited AI: rejected (margin risk).

## Consequences

- Every credit movement is a ledger entry and audit event.
