<?php

declare(strict_types=1);

namespace Modules\Engage;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Notices\NoticeTypes;
use Modules\Crm\Events\ActivityLogged;
use Modules\Engage\Models\CheckIn;

/** Engage (EN1, specs/engage/01): game plan and coach, agent activity, the database assistant and recognitions. */
final class EngageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(GamePlan::class);
        $this->app->scoped(Engagement::class);
        $this->app->scoped(DatabaseAssistant::class);
        $this->app->scoped(Recognitions::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $features = $this->app->make(FeatureCatalog::class);
        $features->declare(GamePlan::FEATURE, 'ai.game_coach', 'Game plan: the daily coach (counts only)');
        $features->declare(DatabaseAssistant::DRAFT, 'ai.checkin_draft', 'Database assistant: a personal check-in email for the agent to send');
        $features->declare(DatabaseAssistant::READ, 'ai.checkin_reply', 'Database assistant: what a reply asks for, with a suggested answer');
        $notices = $this->app->make(NoticeTypes::class);
        $notices->register(CheckInsReadyMail::class, 'Check-in emails ready to send', 'Engage');
        $notices->register(CheckInReplyMail::class, 'Replies to your check-in emails', 'Engage');
        $notices->register(RetentionAlertMail::class, 'Agents to check in with (Mondays)', 'Engage');
        $notices->register(RecognitionMail::class, 'Messages from your broker', 'Engage');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\EngageWeekly::class]);
        }
        // A reply to a check-in email is read for what the person wants.
        Event::listen(ActivityLogged::class, function (ActivityLogged $e): void {
            if ($e->type === 'email') {
                app(DatabaseAssistant::class)->onActivity($e->contactId, $e->activityId);
            }
        });
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Overview', 'Game plan', '/engage', 'target', 11, kinds: [UserKind::Owner, UserKind::Agent]));
        $nav->add(new NavItem('People', 'Agent activity', '/engage/activity', 'heart-handshake', 17, kinds: [UserKind::Owner, UserKind::Staff]));
        // The daily brief points to what is waiting here.
        $this->app->make(DailyBriefs::class)->add('engage', function (User $user): array {
            $items = [];
            $drafts = CheckIn::query()->where('user_id', $user->id)->where('status', 'draft')->count();
            if ($drafts > 0) {
                $items[] = ['key' => 'engage:drafts', 'title' => "{$drafts} check-in email(s) ready to send", 'why' => 'Past clients and friends you have not been in touch with for a while.',
                    'action' => 'Read and send', 'url' => '/engage', 'urgency' => 2];
            }
            $replies = CheckIn::query()->where('user_id', $user->id)->where('status', 'replied')->whereIn('intent', ['buy', 'sell', 'refer'])
                ->where('replied_at', '>=', now()->subDays(3))->count();
            if ($replies > 0) {
                $items[] = ['key' => 'engage:replies', 'title' => "{$replies} check-in repl(ies) about buying, selling or a referral", 'why' => 'Answer while it is fresh.',
                    'action' => 'Open', 'url' => '/engage', 'urgency' => 3];
            }
            if (Recognitions::leads($user)) {
                $n = count(app(Recognitions::class)->milestones());
                if ($n > 0) {
                    $items[] = ['key' => 'engage:milestones', 'title' => "{$n} agent milestone(s) to recognise", 'why' => 'A short note from you means a lot.',
                        'action' => 'Send a note', 'url' => '/engage/activity', 'urgency' => 1];
                }
            }

            return $items;
        });
    }
}
