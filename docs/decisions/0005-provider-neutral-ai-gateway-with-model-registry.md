# ADR 0005: Provider-neutral AI gateway with model registry

Status: **Accepted**

## Context

AI runs in almost every module. Prices, quality and availability of models change quickly.

## Decision

All AI calls go through one gateway. Claude is primary (Haiku, Sonnet, Opus by task); OpenAI, Google Gemini and self-hosted open-source models are alternatives and fallbacks. HQ assigns a model and fallback chain per feature, evaluating quality before switching.

## Alternatives considered

- Hard-wiring one provider: rejected (lock-in, outage risk).
- Letting each module call providers directly: rejected (no cost control or data protection).

## Consequences

- Compliance features stay locked to one validated model.
- Only providers with no-training, zero-retention terms.
