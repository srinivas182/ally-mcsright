# AI 01 — AI gateway, model registry and AI data protection (Team D, Sprints 1–3)

## Purpose

Every AI feature in RightAlly (lead replies, daily game plan, DocIQ, flyer compliance, Compliance
Knowledge, the assistant, listing descriptions, …) calls AI through one gateway. The gateway
chooses the model, enforces AI data protection, meters credits, records cost and sources, and
falls back when a provider fails. No module may call an AI provider directly (architecture test).

Module: `modules/AI`, namespace `Modules\AI`. Registry tables are platform-level (control);
request logs are tenant-owned (cell).

## Gateway API

```php
AiGateway::run(AiTask $task): AiResult        // synchronous
AiGateway::stream(AiTask $task, Closure $onText): AiResult  // guarded streaming (Sprint 3D-2)
AiGateway::batch(array $tasks): AiBatch                     // overnight work at batch pricing (Sprint 3D-2)
```

`AiTask` fields: `featureKey` (e.g. `crm.lead_reply`), `tenant`, `actingUser`, `promptKey` and
version, `inputs` (typed values), `context` (list of `RetrievedRecord`), `untrustedContent`
(documents, emails, form text), `outputSchema` (optional JSON schema), `maxOutputTokens`,
`requiresConfirmationForActions` (default true), `idempotencyKey`.

`AiResult`: text or validated JSON, `modelUsed`, tokens in/out, `costCents`, `latencyMs`,
`sourceRecordIds`, `creditsCharged`, `requestUlid`.

## Model registry (control)

### `ai_providers`

`key (anthropic, openai, google, selfhosted, …), name, base_url, credential_secret_ref,
zero_retention_confirmed (bool, required to activate), no_training_confirmed (bool, required),
dpa_signed_at, status (active, disabled), circuit_state`.

### `ai_models`

`id, provider_key, model_id (API model string), display_name, capabilities (text, vision, json,
tools, embeddings), context_window, input_cost_per_mtok_cents, output_cost_per_mtok_cents,
cache_read_cost_per_mtok_cents, status (active, evaluating, retired)`.

Initial models: Claude Haiku (high-volume classification, extraction, routing), Claude Sonnet
(default workhorse), Claude Opus (hardest reasoning); OpenAI and Google Gemini models registered
as fallbacks; an embeddings model; a self-hosted OpenAI-compatible endpoint for cheap bulk
classification. Exact model IDs and prices are entered in the registry from the providers'
current documentation at setup time.

### `ai_feature_routes`

`feature_key, primary_model_id, fallback_model_ids (json, ordered), temperature, max_output_tokens,
locked (bool; compliance features cannot change model without HQ approval and a passing
evaluation), eval_suite_key, credit_action_key (rate card, spec core/07)`.

## Request handling

1. Resolve route for `featureKey`; check the tenant has the AI feature enabled (tenant AI settings)
   and the module entitlement.
2. **Build context safely** (data protection, below).
3. Meter credits with `CreditMeter::charge` using the route's `credit_action_key`
   (fair use first; spec core/07). Insufficient credits → friendly error, no model call.
4. Call the primary model through its provider adapter (Anthropic, OpenAI, Google, OpenAI-compatible).
   Timeouts: 30 s default, 120 s for long documents.
5. On timeout, 5xx, rate limit or open circuit → next fallback model; each attempt logged.
6. If an output schema is set, validate; on failure retry once with the validation errors, then
   fail with a clear error.
7. Run the output policy check (below).
8. Log the request and write an audit event with source record IDs.

Circuit breaker per provider: opens after 5 failures in 60 s, half-open after 30 s.

Prompts live in code (`modules/AI/prompts/{feature}/{version}.md` with front matter), reviewed in
pull requests, and versioned; the version is logged with every request.

## AI data protection (ADR 0007)

| Rule                                  | How it is enforced                                                                                                                                                                                     |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Only permitted data reaches the model | `context` accepts only `RetrievedRecord` objects, which can only be created by tenant-scoped, permission-checked repositories (`ScopedRetriever`); the gateway rejects anything else at the type level |
| No cross-user memory                  | Each request is built from scratch; no conversation state is shared between users; chat history is stored per user and reloaded only for that user                                                     |
| No training on customer data          | Providers activate only with `no_training_confirmed` and `zero_retention_confirmed`; gateway refuses disabled providers                                                                                |
| Personal data minimised               | Redactor replaces SSNs, bank and routing numbers, card numbers and dates of birth with placeholders before the call; placeholders mapped back only where the output needs them                         |
| Prompt-injection defence              | `untrustedContent` is wrapped in clearly delimited data blocks with a fixed instruction that it is data, never instructions; tool calls from AI output are never executed without user confirmation    |
| Output policy check                   | Output is scanned for identifiers (ULIDs, emails, phone numbers) that were not in the permitted context; any hit blocks the answer and raises a security alert                                         |
| Anonymised cross-tenant learning      | Only aggregated, de-identified outcome statistics may be used across tenants (e.g. daily game plan ranking); enforced by a separate analytics pipeline with no access to raw records                   |
| Tenant controls                       | AI on/off per module and feature; retention of chat history; export and deletion                                                                                                                       |

### Retrieval service

> **Implemented in Sprint 2D.** Embeddings go through `AiGateway::embed()` with the same protections
> (credits, redaction, compliant providers, logging) but **never fall back to another model**,
> because vectors from different models are not comparable. The retriever resolves the access
> scope itself (`AccessScopeResolver`; own records only until roles ship) and searches through the
> `VectorStore` interface: `DatabaseVectorStore` (exact cosine) now, OpenSearch k-NN when the AWS
> environment exists.

`ScopedRetriever` searches the tenant's vector and keyword indexes (OpenSearch k-NN). The tenant
filter and the user's permission scope are added by the query builder and cannot be removed by
callers; vector documents carry `tenant_id`, `owner_user_id`, `team_ids`, `office_id` and
`sensitivity`. Embeddings are created through the gateway (metered, logged).

## Logging

### `ai_requests` (cell)

`id, ulid, tenant_id, user_id, feature_key, prompt_key, prompt_version, model_id, attempt,
status (ok, failed, blocked), tokens_in, tokens_out, cache_read_tokens, cost_cents, latency_ms,
credits_charged, credit_ledger_ulid, source_record_ids (json), output_policy_result,
idempotency_key, created_at`.

Prompts and outputs are **not stored** by default. A tenant may opt in to keep conversation
history for its users (retention set per tenant). Evaluation samples use synthetic data only.

## Leak-test suite

1. **Deterministic retrieval tests (every PR touching AI or data access, no model calls):**
   two tenants and two agents per tenant seeded with canary values (`CANARY-T1-A1-…`); every
   `ScopedRetriever` query type is run as each user with adversarial queries; tests assert no
   foreign canary is ever retrieved.
2. **Gateway contract tests:** passing a raw string as context, a record from another tenant, or
   disabled providers must fail.
3. **Model-in-the-loop red-team run (weekly, scheduled, budget-capped):** documents carrying
   hidden attacker instructions (wire redirection, contact swaps, phishing links, jailbreaks, fake
   tool output) are sent through the full gateway for every AI feature using real models
   (`php artisan ai:red-team`, Mondays 05:00, default budget $5). If a canary reaches the final
   answer the model **followed the injection**: a **critical** scenario (payment, contact, link)
   **fails the run, raises `RedTeamFailed` and exits non-zero so the release is blocked**; others
   mark a **warning** tracked per model. Answers the output policy withholds count as caught.
   _Decided in Sprint 3D:_ cross-tenant data leaks cannot be tested this way because a model cannot
   reveal records it never receives; they are prevented by permission-scoped retrieval and proven
   by tests 1–2 on every pull request.

## Streaming and batch (delivered in Sprint 3D-2)

**Guarded streaming.** Text reaches the user only after the output policy has checked everything
received so far, only up to a word boundary, and never the last 32 characters, so an email, phone or
record ID can never appear half-written before it is checked. On a violation the stream stops, the
answer is withheld and credits are refunded; the browser receives a `withheld` event and replaces
what it showed. Fallback to another model only while nothing has been shown. Structured (schema)
tasks are not streamed. Adapters: Anthropic, OpenAI-compatible and Gemini (Server-Sent Events);
`AiEventStream` sends `text`, `done`, `withheld` or `error` events.

**Batch.** One feature per batch, primary model only, credits charged per task and refunded per
task if it fails or is withheld. Anthropic Message Batches (half price, within 24 hours); other
providers run the tasks immediately at normal price. `ai:batches:collect` (every 10 minutes)
checks every answer with the output policy, logs it at half price and stores it **encrypted** for
the feature to read; stored answers are purged after 7 days. The request log still never stores
prompts or answers.

## HQ console screens

> **Delivered in Sprint 3D** at `/hq/ai`: usage and cost across all tenants (7 or 30 days, by
> feature and model, failure rate, latency, answers blocked by the output policy), feature routes
> (primary model, lock; embedding routes refuse fallbacks), providers (activation only with zero
> retention and no training confirmed, plus a confirmation note; circuit-breaker state), models and
> prices, and red-team results. Streaming and batch follow in Sprint 3D-2.

Providers (with compliance confirmations), models (costs, capabilities, status), feature routes
(primary, fallbacks, locked flag), evaluation results per model and feature, usage and cost
dashboard (by tenant, feature, model; cost vs credits), security alerts from the output policy.

## Tenant screens

Settings → AI: on/off per AI feature, chat history retention, a plain-English summary of AI data
protection. Audit log shows AI answers with their source records (spec core/04).

## Permissions

HQ: `hq.ai.manage`, `hq.ai.routes.approve`. Tenant: `ai.settings.manage`.

## Acceptance criteria

1. No module can call a provider except through the gateway (architecture test).
2. Routing, fallback, timeouts, schema validation and circuit breaking work against provider
   test doubles; one real call per provider succeeds in a manual smoke test.
3. Credits are charged once per request (idempotent) and refused cleanly when insufficient.
4. The gateway rejects context that is not a `RetrievedRecord` and providers lacking
   no-training/zero-retention confirmations.
5. Redaction removes the listed personal data from outgoing prompts (tests on samples).
6. Output policy blocks answers containing identifiers not in the permitted context.
7. Deterministic leak tests pass for every retrieval type; the weekly red-team run is scheduled
   and reports results to HQ.
8. Every request is logged with cost, model and source records; prompts and outputs are not
   stored unless the tenant opts in.
