<?php

declare(strict_types=1);

namespace Modules\Movewise;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
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
        $this->app->tag([Bridge\MovewiseBridge::class], 'crm.contact_bridges'); // the relay (specs/movewise/05)
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'movewise');
        // Movewise has no session to carry errors back, so a refused form shows them on a page (JSON callers get the usual 422).
        $this->app->make(ExceptionHandler::class)->renderable(function (ValidationException $e, Request $request) {
            if ($request->getHost() !== config('movewise.host') || $request->expectsJson()) {
                return null;
            }
            $site = app(Http\Controllers\SiteController::class);
            $chrome = $site->chrome();

            return response()->view('movewise::problem', ['site' => $chrome, 'messages' => array_values(array_map(fn (array $m): string => (string) $m[0], $e->errors())),
                'seo' => $site->seo($chrome, '/account', 'Movewise Homes', 'Movewise Homes', false)], 422)->header('Cache-Control', 'no-store');
        });
        // Every Movewise view gets $t (translate) and $u (the same address in the current language).
        View::composer('movewise::*', function (\Illuminate\View\View $view): void {
            $view->with(['t' => fn (string $key, array $replace = []): string => I18n::t($key, $replace), 'u' => fn (string $path): string => I18n::url($path)]);
        });
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(MovewiseAilly::FEATURE, 'ai.movewise', 'Movewise AIlly (HQ pays)');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SendAlerts::class, Console\RecheckLicences::class, Console\ExpireOffers::class, Console\Outcomes::class, Console\LaunchCheck::class]);
        }
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Platform', 'Movewise', '/hq/movewise', 'house', 39, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Platform', 'Movewise agents', '/hq/movewise/program', 'users', 40, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Platform', 'Movewise money', '/hq/movewise/money', 'banknote', 40, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Platform', 'Movewise launch', '/hq/movewise/launch', 'rocket', 40, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Grow', 'Movewise', '/movewise', 'house', 27, kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
