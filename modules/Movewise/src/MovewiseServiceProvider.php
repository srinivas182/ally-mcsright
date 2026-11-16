<?php

declare(strict_types=1);

namespace Modules\Movewise;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Movewise Homes (specs/movewise): HQ's consumer site and lead marketplace, on the control plane. */
final class MovewiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/movewise.php', 'movewise');
        $this->app->scoped(Movewise::class);
        $this->app->scoped(People\Accounts::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'movewise');
        // Every Movewise view gets $t (translate) and $u (the same address in the current language).
        View::composer('movewise::*', function (\Illuminate\View\View $view): void {
            $view->with(['t' => fn (string $key, array $replace = []): string => I18n::t($key, $replace), 'u' => fn (string $path): string => I18n::url($path)]);
        });
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(MovewiseAilly::FEATURE, 'ai.movewise', 'Movewise AIlly (HQ pays)');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SendAlerts::class]);
        }
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Platform', 'Movewise', '/hq/movewise', 'house', 39, kinds: [UserKind::HqStaff]));
    }
}
