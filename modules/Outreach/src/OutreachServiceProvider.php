<?php

declare(strict_types=1);

namespace Modules\Outreach;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Sites\Http\SiteExtensions;

/** Outreach (MK1, specs/crm/20): booking links, video messages, the call queue, and A2P 10DLC texting registration. */
final class OutreachServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BookingLinks::class);
        $this->app->scoped(Videos::class);
        $this->app->scoped(CallQueue::class);
        $this->app->scoped(TextingRegistration::class);
        $this->app->scoped(ContentPack::class);
        $this->app->scoped(HandwrittenCards::class);
        $this->app->scoped(MarketReports::class); // MK3
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'outreach');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SendMarketReports::class]); // MK3
        }
        $this->app->make(SiteExtensions::class)->routes(function (Router $r): void {
            $r->get('meet/{slug}', [Http\PublicController::class, 'meet'])->where('slug', '[a-z0-9-]{1,60}');
            $r->post('_meet/{slug}', [Http\PublicController::class, 'book'])->where('slug', '[a-z0-9-]{1,60}');
            $r->get('v/{token}', [Http\PublicController::class, 'video'])->where('token', '[A-Za-z0-9]{48}');
            $r->get('v/{token}/file', [Http\PublicController::class, 'videoFile'])->where('token', '[A-Za-z0-9]{48}');
        });
        // HQ1: these HQ pages had no menu entry, so HQ staff could not reach them.
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Customers', 'Texting approvals', '/hq/texting', 'message-square', 20, ability: 'hq.tenants.manage',
            kinds: [UserKind::HqStaff]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Support', 'Card orders', '/hq/cards', 'mail', 20, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Outreach', '/outreach', 'phone', 37,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
    }
}
