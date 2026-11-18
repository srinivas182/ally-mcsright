<?php

declare(strict_types=1);

namespace Modules\Network;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Notices\NoticeTypes;

/** Network (AR1-AR3, specs/network): the agent referral network. RightAlly takes no share of referral fees. */
final class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Profiles::class);
        $this->app->scoped(NetworkReferrals::class);
        $this->app->singleton(TrustScore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'network');
        $this->app->make(NoticeTypes::class)->register(Mail\NetworkMail::class, 'Referral network: referrals and updates', 'Deals');
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Grow', 'Referral network', '/network', 'handshake', 30, kinds: [UserKind::Owner, UserKind::Agent]));
        $nav->add(new NavItem('Settings', 'Referral network', '/settings/network', 'handshake', 76, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('HQ', 'Referral network', '/hq/network', 'handshake', 15, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // AR2
        if ($this->app->runningInConsole()) {
            $this->commands([Console\ExpireReferrals::class, Console\RefreshTrust::class]);
        }
    }
}
