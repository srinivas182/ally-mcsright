<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Contracts\StreamingProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Models\AiProvider;
use RuntimeException;
use Throwable;

/**
 * Scripted AI provider for tests: returns queued responses or throws queued exceptions,
 * and records every request it receives.
 */
final class FakeAiProvider implements ProviderAdapter, StreamingProviderAdapter
{
    /** @var list<ProviderResponse|Throwable> */
    private array $queue = [];

    /** @var list<ProviderRequest> */
    public array $requests = [];

    public function respond(string $text, int $in = 100, int $out = 20): self
    {
        $this->queue[] = new ProviderResponse($text, $in, $out);

        return $this;
    }

    public function fail(Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new RuntimeException('FakeAiProvider has no queued response.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * Streams the next queued answer in pieces of $chunkSize characters, like a real model.
     */
    /** @var int<1, max> */
    public int $chunkSize = 7;

    /** Throw this after the first piece has been streamed (simulates a dropped connection). */
    public ?Throwable $failMidStream = null;

    public function stream(AiProvider $provider, ProviderRequest $request, Closure $onDelta): ProviderResponse
    {
        $response = $this->send($provider, $request);

        foreach (mb_str_split($response->text, $this->chunkSize) as $i => $piece) {
            $onDelta($piece);
            if ($i === 0 && $this->failMidStream !== null) {
                throw $this->failMidStream;
            }
        }

        return $response;
    }
}
