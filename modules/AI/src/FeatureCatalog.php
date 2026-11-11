<?php

declare(strict_types=1);

namespace Modules\AI;

/**
 * AI features declared by modules. `php artisan ai:sync-features` creates a route for any
 * declared feature that has none, on the cheapest active model with the needed capability;
 * HQ can then change it in the AI console.
 */
final class FeatureCatalog
{
    /** @var array<string, array{credit_action: string, description: string, capability: string}> */
    private array $features = [];

    public function declare(string $key, string $creditAction, string $description, string $capability = 'chat'): void
    {
        $this->features[$key] = ['credit_action' => $creditAction, 'description' => $description, 'capability' => $capability];
    }

    /**
     * @return array<string, array{credit_action: string, description: string, capability: string}>
     */
    public function all(): array
    {
        return $this->features;
    }
}
