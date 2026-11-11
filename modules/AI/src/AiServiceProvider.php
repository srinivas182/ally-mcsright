<?php

declare(strict_types=1);

namespace Modules\AI;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Console\RunRedTeam;
use Modules\AI\Contracts\CreditMeter;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Retrieval\AccessScopeResolver;
use Modules\AI\Retrieval\DatabaseVectorStore;
use Modules\AI\Retrieval\OwnRecordsOnlyResolver;
use Modules\AI\Retrieval\ScopedRetriever;
use Modules\AI\Retrieval\VectorIndexer;
use Modules\AI\Retrieval\VectorStore;
use Modules\AI\Security\OutputPolicy;
use Modules\AI\Security\PromptBuilder;
use Modules\AI\Support\CircuitBreaker;
use Modules\AI\Support\JsonShape;
use Modules\AI\Support\NullCreditMeter;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

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
        $this->app->bind(VectorStore::class, DatabaseVectorStore::class);
        $this->app->bind(AccessScopeResolver::class, OwnRecordsOnlyResolver::class);
        $this->app->scoped(VectorIndexer::class);
        $this->app->scoped(ScopedRetriever::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/hq.php');
        Gate::define('hq.ai.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $this->app->make(NavigationRegistry::class)->add(new NavItem('HQ', 'AI gateway', '/hq/ai', 'sparkles', 20, ability: 'hq.ai.manage', kinds: [UserKind::HqStaff]));
        if ($this->app->runningInConsole()) {
            $this->commands([RunRedTeam::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
