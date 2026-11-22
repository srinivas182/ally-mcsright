<?php

declare(strict_types=1);

namespace Modules\Team;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\Core\Assistant\Handoffs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
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

    /**
     * AS1 (specs/know/03): "Your AI Assistant" hands a conversation to a person: the broker (a direct message in team
     * chat, with an @mention so they are told) or RightAlly support (a support request).
     */
    private static function handoffs(Handoffs $handoffs): void
    {
        $broker = fn (User $u): ?User => User::query()->where('kind', UserKind::Owner)->where('status', UserStatus::Active)->where('id', '!=', $u->id)->orderBy('id')->first();
        $handoffs->register('broker', 'Ask my broker', fn (User $u): bool => Chat::allowed($u) && $u->kind !== UserKind::Owner && $broker($u) !== null,
            function (User $u, string $subject, string $conversation) use ($broker): string {
                $owner = $broker($u) ?? throw ValidationException::withMessages(['target' => 'Your brokerage has no owner to ask.']);
                $chat = app(Chat::class);
                $chat->post($chat->direct($u, $owner), "Question for you (passed on by Your AI Assistant): {$subject}\n\n{$conversation}", $u, [$owner->id]);

                return 'Sent to '.$owner->name.' in team chat. You will see the answer there.';
            });
        $handoffs->register('support', 'Ask RightAlly support', fn (User $u): bool => Chat::allowed($u),
            function (User $u, string $subject, string $conversation): string {
                app(SupportTickets::class)->open($u, 'how_to', $subject, "Passed on by Your AI Assistant.\n\n".$conversation, null);

                return 'Sent to RightAlly support. They usually answer by email within one working day.';
            });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'team'); // SX5: printable cheat sheets
        if ($this->app->runningInConsole()) {
            $this->commands([Console\CleanupConcierge::class]); // SX6
        }
        $this->app->make(NoticeTypes::class)->register(MentionMail::class, 'Unread @mentions in team chat', 'Team');
        self::handoffs($this->app->make(Handoffs::class)); // AS1
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Overview', 'Team chat', '/chat', 'message-circle', 27, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'Help', '/help', 'life-buoy', 99, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Customers', 'Support', '/hq/support', 'life-buoy', 13, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Customers', 'Help videos', '/hq/support/videos', 'video', 14, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // SX5
    }
}
