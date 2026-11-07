# ADR 0007: AI data protection by construction

Status: **Accepted**

## Context

One brokerage must never see another's data, and one agent must never see or request another agent's data, including through AI.

## Decision

Permission-filtered retrieval before any model call; per-request context only; no training on customer data; zero-retention providers; prompt-injection defence; confirmed actions; anonymised cross-tenant learning; release-blocking leak tests; AI source records in the audit log.

## Alternatives considered

- Relying on prompts or the model to keep tenants apart: rejected.

## Consequences

- Agent personal-sphere contacts are private by default; brokerage business data is visible to the brokerage; configurable per brokerage.
