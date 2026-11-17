<?php

declare(strict_types=1);

namespace Modules\Team;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Notices\NoticeTypes;
use Modules\Team\Help\HelpCentre;
use Modules\Team\Help\SupportTickets;

/** Team (CH1, specs/team/01): team chat, the help centre and support requests to RightAlly HQ. */
final class TeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Chat::class);
        $this->app->singleton(HelpCentre::class);
        $this->app->scoped(SupportTickets::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(NoticeTypes::class)->register(MentionMail::class, 'Unread @mentions in team chat', 'Team');
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Overview', 'Team chat', '/chat', 'message-circle', 27, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'Help', '/help', 'life-buoy', 99, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('HQ', 'Support', '/hq/support', 'life-buoy', 12, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
    }
}
