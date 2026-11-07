# ADR 0008: Native forms and e-sign, plus integrations

Status: **Accepted**

## Context

Agents are loyal to dotloop, SkySlope and Lone Wolf forms; official state forms are licensed.

## Decision

Native RightAlly forms and e-sign are the default (AI PDF-to-smart-form builder, ESIGN/UETA audit trail). Two-way integrations with dotloop, SkySlope, Lone Wolf and DocuSign pull signed documents and dates into RightAlly transactions.

## Alternatives considered

- Integrations only: rejected (no differentiated experience).
- Native only: rejected (forces agents to switch, a sales objection).

## Consequences

- Partner API approvals and state form licences are pre-start items.
