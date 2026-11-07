<?php

declare(strict_types=1);

namespace Modules\AI;

use Illuminate\Support\ServiceProvider;
use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\PromptBuilder;
use Modules\AI\Support\CircuitBreaker;
use Modules\AI\Support\JsonShape;
use Modules\AI\Support\NullCreditMeter;
use Modules\AI\Support\PromptRegistry;

/**
 * AI platform: gateway, registry, credit metering and AI data protection.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromptRegistry::class, function (): PromptRegistry {
            $registry = new PromptRegistry;
            $registry->addPath(__DIR__.'/../prompts');

            return $registry;
        });

        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(OutputPolicy::class);
        $this->app->singleton(PromptBuilder::class);
        $this->app->singleton(JsonShape::class);
        $this->app->bind(CreditMeter::class, NullCreditMeter::class);
        $this->app->scoped(AiGateway::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
