# ADR 0002: Shared-schema multi-tenancy inside cells

Status: **Accepted**

## Context

Specs 02–04 assumed a deployment and database per customer. At thousands of brokerages that means thousands of deployments and migrations per release, and no cross-brokerage analytics.

## Decision

Tenants share a schema inside a cell, scoped by tenant_id with a global scope, per-tenant encryption keys, tenant-carrying caches, queues, storage and search. Enterprise brokerages can get a dedicated cell. Supersedes the per-customer deployment model.

## Alternatives considered

- Deployment + DB per brokerage: rejected (operations cost).
- DB per brokerage, shared app: rejected (migrating thousands of databases per release).
- One global database: rejected (scaling ceiling, single point of failure).

## Consequences

- Cross-tenant leak tests run on every build.
- Moving a brokerage between cells is an automated, rehearsed operation.
