<?php

declare(strict_types=1);

namespace Modules\AI;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Console\CollectBatches;
use Modules\AI\Console\RunRedTeam;
use Modules\AI\Console\SyncFeatures;
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
use Modules\Core\Workflows\WorkflowDrafter;

/**
 * AI platform: gateway, registry, credit metering and AI data protection.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureCatalog::class);
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
        $this->app->bind(WorkflowDrafter::class, Workflows\AiWorkflowDrafter::class);
        $this->app->scoped(AiGateway::class);
        $this->app->bind(VectorStore::class, DatabaseVectorStore::class);
        $this->app->bind(AccessScopeResolver::class, OwnRecordsOnlyResolver::class);
        $this->app->scoped(VectorIndexer::class);
        $this->app->scoped(ScopedRetriever::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/hq.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/assistant.php');
        $this->app->make(FeatureCatalog::class)->declare(Workflows\AiWorkflowDrafter::FEATURE, 'ai.workflow_draft', 'Automations described in plain English');
        $this->app->make(FeatureCatalog::class)->declare(Assistant\YourAiAssistant::FEATURE, 'ai.assistant', 'Your AI Assistant: answers and acts in the back office');
        Gate::define('hq.ai.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $this->app->make(NavigationRegistry::class)->add(new NavItem('HQ', 'AI gateway', '/hq/ai', 'sparkles', 20, ability: 'hq.ai.manage', kinds: [UserKind::HqStaff]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('HQ', 'AI quality', '/hq/ai/quality', 'clipboard-check', 21, ability: 'hq.ai.manage', kinds: [UserKind::HqStaff])); // AQ1
        if ($this->app->runningInConsole()) {
            $this->commands([RunRedTeam::class, CollectBatches::class, SyncFeatures::class, Console\PruneAssistant::class, Console\RunEvals::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
