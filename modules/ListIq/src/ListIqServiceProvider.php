<?php

declare(strict_types=1);

namespace Modules\ListIq;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** List-IQ (specs/listings/02): getting a listing ready. */
final class ListIqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Preps::class);
        $this->app->scoped(Photos::class);
        $this->app->scoped(Descriptions::class);
        $this->app->bindIf(Staging\VirtualStager::class, Staging\ReplicateStager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Descriptions::FEATURE, 'ai.listing_description', 'List-IQ listing descriptions and Spanish versions');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'List-IQ', '/list-iq', 'camera', 11, kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
