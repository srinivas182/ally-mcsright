<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Models\AiProvider;
use RuntimeException;
use Throwable;

/**
 * Scripted AI provider for tests: returns queued responses or throws queued exceptions,
 * and records every request it receives.
 */
final class FakeAiProvider implements ProviderAdapter
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
}
