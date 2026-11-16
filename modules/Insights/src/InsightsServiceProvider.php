<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Notices\NoticeTypes;

/** Reports and KPIs (specs/insights/01). */
final class InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Numbers::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(AskYourNumbers::FEATURE, 'ai.insights_ask', 'Ask your numbers in plain English');
        $this->app->make(NoticeTypes::class)->register(AnomalyMail::class, 'Worth a look: big changes in your numbers', 'Reports');
        $this->app->make(NoticeTypes::class)->register(ReportMail::class, 'Scheduled reports', 'Reports');
        $this->app->make(NoticeTypes::class)->register(RoiMail::class, 'Your month with RightAlly', 'Reports');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\InsightsDaily::class]);
        }

        $this->app->make(PermissionRegistry::class)->register('Reports', [
            'insights.view' => ['See brokerage numbers: sales, pipeline, lead sources and agents', true],
        ]);
        Gate::define('insights.numbers', fn (User $user): bool => Numbers::people($user) !== false);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Overview', 'Numbers', '/insights', 'chart-column', 28, ability: 'insights.numbers', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Overview', 'Reports', '/insights/reports', 'file-text', 29, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Overview', 'Business planner', '/insights/plan', 'target', 30, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('Overview', 'My numbers', '/insights/me', 'target', 28, kinds: [UserKind::Agent], mobile: true));
    }
}
