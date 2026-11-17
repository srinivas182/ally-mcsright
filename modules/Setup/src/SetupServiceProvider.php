<?php

declare(strict_types=1);

namespace Modules\Setup;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Setup (SU1-SU3, specs/setup): the AI setup assistant. */
final class SetupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Imports\FileImports::class);
        $this->app->scoped(Imports\Detector::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Imports\Detector::FEATURE, 'ai.setup_map', 'Setup: match the columns of a file from another system (column names only)');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'Bring your old files', '/setup/import', 'list-plus', 66, kinds: [UserKind::Owner]));
    }
}
