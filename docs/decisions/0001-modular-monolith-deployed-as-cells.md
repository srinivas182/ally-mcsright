# ADR 0001: Modular monolith, deployed as cells

Status: **Accepted**

## Context

RightAlly must reach 100,000 brokerages and millions of agents with a small team, and many modules share money and data in single transactions (deal → commission → ledger → payout).

## Decision

One Laravel codebase made of independent modules with hard boundaries (own tables, public interfaces, domain events), deployed as identical cells. A module is extracted into its own service only when measured load justifies it.

## Alternatives considered

- Classic monolith (today's code): no boundaries; rejected.
- Microservices from day one: distributed transactions for money flows, ~10x operations cost; rejected for now.

## Consequences

- Architecture tests must enforce module boundaries.
- Candidates for later extraction: listing search, messaging, AI gateway.
