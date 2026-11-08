<?php

declare(strict_types=1);

namespace Modules\AI;

use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Data\AiResult;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\EmbeddingResult;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Events\AiOutputWasBlocked;
use Modules\AI\Events\AiRequestCompleted;
use Modules\AI\Exceptions\AiOutputBlocked;
use Modules\AI\Exceptions\AllModelsFailed;
use Modules\AI\Exceptions\OutputSchemaViolation;
use Modules\AI\Exceptions\ProviderRejected;
use Modules\AI\Exceptions\ProviderUnavailable;
use Modules\AI\Exceptions\UnknownFeature;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiRequest;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\PromptBuilder;
use Modules\AI\Security\Redactor;
use Modules\AI\Support\CircuitBreaker;
use Modules\AI\Support\JsonShape;
use Modules\AI\Support\PromptRegistry;
use Modules\AI\Support\PromptTemplate;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * The only entry point to AI in RightAlly (ADR 0005, ADR 0007, specs/ai/01).
 *
 * For each task: resolve the feature route → charge credits once → build a redacted prompt
 * from permitted records only → try the model chain (skipping non-compliant providers and
 * open circuits, falling back on outages) → validate output shape → block answers that
 * mention identifiers outside the permitted context → log every attempt without storing
 * prompts or outputs.
 */
final class AiGateway
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PromptRegistry $prompts,
        private readonly PromptBuilder $builder,
        private readonly ProviderRegistry $providers,
        private readonly CircuitBreaker $circuit,
        private readonly OutputPolicy $policy,
        private readonly JsonShape $shapes,
        private readonly CreditMeter $credits,
    ) {}

    public function run(AiTask $task): AiResult
    {
        $tenantId = $this->tenants->id();

        $route = AiFeatureRoute::query()->where('feature_key', $task->featureKey)->first()
            ?? throw new UnknownFeature("No AI route for feature [{$task->featureKey}].");

        $template = $this->prompts->get($task->promptKey);

        // Charged once, before any model call; refunded if no usable answer is produced.
        $this->credits->charge($route->credit_action_key, $task->userId, $task->idempotencyKey);

        $prompt = $this->builder->build($task, $template, new Redactor);
        $sourceIds = array_map(fn ($record) => $record->type.':'.$record->id, $task->context);
        $attempt = 0;

        foreach ($route->modelChain() as $model) {
            $provider = $model->provider;

            if (! $provider->isUsable()) {
                $this->log($task, $template, $model, ++$attempt, 'skipped', 'provider not active or not compliant', $sourceIds);

                continue;
            }

            if ($this->circuit->isOpen($provider->key)) {
                $this->log($task, $template, $model, ++$attempt, 'skipped', 'circuit open', $sourceIds);

                continue;
            }

            $request = new ProviderRequest(
                $model->model_id,
                $prompt['system'],
                $prompt['user'],
                $route->max_output_tokens,
                $route->temperature,
                $route->timeout_seconds,
            );

            $started = hrtime(true);

            try {
                [$response, $json] = $this->callWithShape($model, $request, $task);
            } catch (ProviderUnavailable $e) {
                $this->circuit->recordFailure($provider->key);
                $this->log($task, $template, $model, ++$attempt, 'failed', $this->reason($e), $sourceIds, latency: $this->ms($started));

                continue;
            } catch (ProviderRejected|OutputSchemaViolation $e) {
                $this->log($task, $template, $model, ++$attempt, 'failed', $this->reason($e), $sourceIds, latency: $this->ms($started));
                $this->credits->refund($task->idempotencyKey, 'failed: '.$e::class);

                throw $e;
            }

            $this->circuit->recordSuccess($provider->key);
            $latency = $this->ms($started);
            $cost = $model->costMicrocents($response->tokensIn, $response->tokensOut, $response->cacheReadTokens);

            $violations = $this->policy->violations($response->text, $prompt['permitted']);
            if ($violations !== []) {
                $log = $this->log($task, $template, $model, ++$attempt, 'blocked', 'output policy', $sourceIds, $response, $cost, $latency, 'blocked');
                $this->credits->refund($task->idempotencyKey, 'output blocked');
                AiOutputWasBlocked::dispatch($tenantId, $log->ulid, $task->featureKey, $violations);

                throw new AiOutputBlocked('The answer referred to information outside the permitted records and was withheld.');
            }

            $log = $this->log($task, $template, $model, ++$attempt, 'ok', null, $sourceIds, $response, $cost, $latency, 'passed');
            AiRequestCompleted::dispatch($tenantId, $log->ulid, $task->featureKey, $sourceIds);

            return new AiResult(
                $response->text,
                $json,
                $log->ulid,
                $provider->key,
                $model->model_id,
                $response->tokensIn,
                $response->tokensOut,
                $cost,
                $latency,
                $sourceIds,
            );
        }

        $this->credits->refund($task->idempotencyKey, 'all models failed');

        throw new AllModelsFailed("No model could serve [{$task->featureKey}].");
    }

    /**
     * Turn texts into embedding vectors through the feature's model, with the same protections
     * as every AI call: credits charged once, personal data redacted before it leaves RightAlly,
     * compliant providers only, every request logged without its content.
     *
     * Unlike text generation there is NO fallback to another model: vectors from different
     * embedding models are not comparable, so mixing them would corrupt search results.
     *
     * @param  list<string>  $texts
     */
    public function embed(string $featureKey, array $texts, string $idempotencyKey, ?int $userId = null): EmbeddingResult
    {
        $this->tenants->id();

        if ($texts === []) {
            throw new \InvalidArgumentException('Nothing to embed.');
        }

        $route = AiFeatureRoute::query()->where('feature_key', $featureKey)->first()
            ?? throw new UnknownFeature("No AI route for feature [{$featureKey}].");
        $model = $route->primaryModel()->with('provider')->firstOrFail();
        $provider = $model->provider;
        $template = new PromptTemplate('embeddings', '1', '');
        $task = new AiTask($featureKey, 'embeddings', $idempotencyKey, userId: $userId);

        if (! $provider->isUsable()) {
            $this->log($task, $template, $model, 1, 'skipped', 'provider not active or not compliant', []);

            throw new AllModelsFailed("The embedding provider for [{$featureKey}] is not available.");
        }

        if ($this->circuit->isOpen($provider->key)) {
            $this->log($task, $template, $model, 1, 'skipped', 'circuit open', []);

            throw new AllModelsFailed("The embedding provider for [{$featureKey}] is temporarily unavailable.");
        }

        $this->credits->charge($route->credit_action_key, $userId, $idempotencyKey);

        $redactor = new Redactor;
        $clean = array_map(fn (string $text): string => $redactor->redact($text), $texts);
        $started = hrtime(true);

        try {
            $response = $this->providers->embeddingAdapterFor($provider)->embed($provider, $model->model_id, $clean, $route->timeout_seconds);
        } catch (ProviderUnavailable|ProviderRejected $e) {
            if ($e instanceof ProviderUnavailable) {
                $this->circuit->recordFailure($provider->key);
            }
            $this->log($task, $template, $model, 1, 'failed', $this->reason($e), [], latency: $this->ms($started));
            $this->credits->refund($idempotencyKey, 'embedding failed');

            throw $e;
        }

        $dimensions = count($response->vectors[0] ?? []);
        foreach ($response->vectors as $vector) {
            if (count($vector) !== $dimensions || $dimensions === 0) {
                $this->credits->refund($idempotencyKey, 'malformed embeddings');

                throw new OutputSchemaViolation('The embedding provider returned vectors of inconsistent size.');
            }
        }

        if (count($response->vectors) !== count($clean)) {
            $this->credits->refund($idempotencyKey, 'malformed embeddings');

            throw new OutputSchemaViolation('The embedding provider returned the wrong number of vectors.');
        }

        $this->circuit->recordSuccess($provider->key);
        $cost = $model->costMicrocents($response->tokens, 0);
        $log = $this->log(
            $task, $template, $model, 1, 'ok', null, [],
            new ProviderResponse('', $response->tokens, 0), $cost, $this->ms($started), 'not_applicable',
        );

        return new EmbeddingResult($response->vectors, $model->model_id, $dimensions, $log->ulid, $cost);
    }

    /**
     * Call the model; when an output schema is required, validate it and retry once with the
     * validation problems so the model can correct itself.
     *
     * @return array{0: ProviderResponse, 1: array<string, mixed>|null}
     */
    private function callWithShape(AiModel $model, ProviderRequest $request, AiTask $task): array
    {
        $adapter = $this->providers->adapterFor($model->provider);
        $response = $adapter->send($model->provider, $request);

        if ($task->outputSchema === null) {
            return [$response, null];
        }

        [$json, $errors] = $this->decode($response->text, $task->outputSchema);
        if ($errors === []) {
            return [$response, $json];
        }

        $retry = new ProviderRequest(
            $request->modelId,
            $request->system,
            $request->userMessage."\n\nYour previous answer was not valid JSON for the required format. Problems: "
                .implode('; ', $errors).'. Reply with only the corrected JSON.',
            $request->maxOutputTokens,
            $request->temperature,
            $request->timeoutSeconds,
        );

        $second = $adapter->send($model->provider, $retry);
        [$json, $errors] = $this->decode($second->text, $task->outputSchema);

        if ($errors !== []) {
            throw new OutputSchemaViolation('Output did not match the required format: '.implode('; ', $errors));
        }

        return [
            new ProviderResponse($second->text, $response->tokensIn + $second->tokensIn, $response->tokensOut + $second->tokensOut, $response->cacheReadTokens + $second->cacheReadTokens),
            $json,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function decode(string $text, array $schema): array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));

        try {
            $data = json_decode($clean, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, ['output is not valid JSON']];
        }

        $errors = $this->shapes->validate($data, $schema);

        /** @var array<string, mixed>|null $json */
        $json = is_array($data) ? $data : null;

        return [$errors === [] ? $json : null, $errors];
    }

    /**
     * @param  list<string>  $sourceIds
     */
    private function log(
        AiTask $task,
        PromptTemplate $template,
        AiModel $model,
        int $attempt,
        string $status,
        ?string $reason,
        array $sourceIds,
        ?ProviderResponse $response = null,
        int $cost = 0,
        int $latency = 0,
        ?string $policy = null,
    ): AiRequest {
        return AiRequest::query()->create([
            'user_id' => $task->userId,
            'feature_key' => $task->featureKey,
            'prompt_key' => $template->key,
            'prompt_version' => $template->version,
            'provider_key' => $model->provider->key,
            'model_id' => $model->model_id,
            'attempt' => $attempt,
            'status' => $status,
            'failure_reason' => $reason,
            'tokens_in' => $response->tokensIn ?? 0,
            'tokens_out' => $response->tokensOut ?? 0,
            'cache_read_tokens' => $response->cacheReadTokens ?? 0,
            'cost_microcents' => $cost,
            'latency_ms' => $latency,
            'source_record_ids' => $sourceIds,
            'output_policy_result' => $policy,
            'idempotency_key' => $task->idempotencyKey,
        ]);
    }

    private function reason(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 255);
    }

    private function ms(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
