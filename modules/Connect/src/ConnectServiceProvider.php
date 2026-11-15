<?php

declare(strict_types=1);

namespace Modules\Connect;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Connect\Webhooks\WebhookOutbox;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\Outbox;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Connect: the app marketplace (specs/connect/00, 01). */
final class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Connections::class);
        $this->app->scoped(SyncRunner::class);
        $this->app->scoped(Health::class);
        $this->app->singleton(AppRegistry::class);
        $this->app->scoped(WebhookOutbox::class);
        $this->app->bind(Outbox::class, WebhookOutbox::class);
        $this->app->scoped(OAuth\OAuthFlow::class);
        $this->app->scoped(Calendars\ConnectCalendars::class);
        $this->app->bind(\Modules\Core\Connect\Calendars::class, Calendars\ConnectCalendars::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SyncConnections::class]);
        }
        Catalog::declare($this->app->make(AppCatalog::class));
        OAuth\ClientSettings::apply();

        Gate::define('hq.apps.view', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Apps', '/apps', 'blocks', 61, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('HQ', 'App demand', '/hq/apps', 'blocks', 18, ability: 'hq.apps.view', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('HQ', 'Connect setup', '/hq/connect', 'key-round', 19, ability: 'hq.apps.view', kinds: [UserKind::HqStaff]));
    }
}
