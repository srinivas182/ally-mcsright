<?php

declare(strict_types=1);

namespace Modules\Api;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Modules\Connect\Http\McpConsentController;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Public API v1 and partner apps (API1, specs/api/01). Boots after Connect, whose OAuth server it shares. */
final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Keys::class);
        $this->app->scoped(PartnerApps::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // The API's OAuth scopes join the AI assistant's ("api:" first, so they never clash).
        $scopes = Passport::scopes()->mapWithKeys(fn ($s): array => [$s->id => $s->description])->all();
        foreach (Scopes::ALL as $id => $description) {
            $scopes[Scopes::oauth($id)] = $description;
        }
        Passport::tokensCan($scopes);
        Passport::authorizationView(fn (array $p) => Http\PartnerConsent::wants($p) ? Http\PartnerConsent::render($p) : McpConsentController::render($p));
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Connections', 'Partner apps', '/hq/partners', 'network', 14, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
    }
}
