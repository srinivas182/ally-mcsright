<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Recruiting (specs/grow/17): the MLS agent database, the recruiting pipeline, alerts and "what you'd earn here". */
final class RecruitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AgentDatabase::class);
        $this->app->scoped(Pipeline::class);
    }

    public function boot(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Recruiting', [
            'recruiting.manage' => ['Recruit agents: the MLS agent database, the recruiting pipeline and offers'],
        ]);
        Brief\RecruitingBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RefreshAgents::class]);
        }
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('People', 'Recruiting', '/recruiting', 'user-plus', 13, ability: 'recruiting.manage'));
        $nav->add(new NavItem('People', 'Agent database', '/recruiting/agents', 'user-search', 14, ability: 'recruiting.manage'));
    }
}
