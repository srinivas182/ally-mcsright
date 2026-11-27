<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Billing\Events\AgentPlanStarted;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Crm\Events\SmsKeywordReceived;
use Modules\Sites\Http\SiteExtensions;

/** Recruiting (specs/grow/17): the MLS agent database, the recruiting pipeline, alerts and "what you'd earn here". */
final class RecruitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AgentDatabase::class);
        $this->app->scoped(Pipeline::class);
        $this->app->scoped(Campaigns::class);
        $this->app->scoped(Careers::class);
        $this->app->scoped(Referrals::class);
        $this->app->scoped(Retention::class);
        $this->app->scoped(Results::class);
        $this->app->scoped(SelfJoin::class);
    }

    public function boot(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Recruiting', [
            'recruiting.manage' => ['Recruit agents: the MLS agent database, the recruiting pipeline and offers'],
        ]);
        Brief\RecruitingBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'recruiting');
        Event::listen(UserActivated::class, fn (UserActivated $e) => app(Joining::class)->activated($e));
        // CM4: an agent who joined through a link and paid for their pack is placed under their sponsor.
        Event::listen(AgentPlanStarted::class, fn (AgentPlanStarted $e) => app(SelfJoin::class)->paid($e));
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Referrals::FEATURE, 'ai.recruit_note', 'Personal recruiting note draft for an agent to send');
        Event::listen(SmsKeywordReceived::class, fn (SmsKeywordReceived $e) => app(Campaigns::class)->keyword($e));
        // The careers page on the brokerage's website (RC2).
        $site = $this->app->make(SiteExtensions::class);
        $site->routes(function (Router $r): void {
            $r->get('careers', [Http\CareersController::class, 'show']);
            $r->get('join/{code?}', [Http\MyNetworkController::class, 'fromWebsite'])->where('code', '[a-z0-9]{6,12}'); // AG1: the short link; AG8: /join with no agent
            $r->post('_careers', [Http\CareersController::class, 'apply']);
        });
        // AG8: the plans new agents can pick, for the "Join our team" section of the website's Join us page.
        $site->provide('join_plans', fn (): array => app(SelfJoin::class)->plans());
        $site->pages(fn (): array => app(Careers::class)->live()
            ? [['path' => '/careers', 'title' => 'Careers', 'description' => 'joining as an agent: what you would keep here, in agents\' own words, and how to talk to us']] : []);
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RefreshAgents::class, Console\SendCampaigns::class]);
        }
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('People', 'Recruiting', '/recruiting', 'user-plus', 13, ability: 'recruiting.manage'));
        $nav->add(new NavItem('People', 'Join page', '/recruiting/join-page', 'link', 13, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('People', 'Agent database', '/recruiting/agents', 'user-search', 14, ability: 'recruiting.manage'));
        $nav->add(new NavItem('People', 'Keeping agents', '/recruiting/retention', 'heart-handshake', 15, ability: 'recruiting.manage'));
        // AG1/AG1b: the agent's Overview and their network tree. "Refer an agent" is now part of the Overview.
        $nav->add(new NavItem('Grow', 'My network', '/my-network', 'layout-dashboard', 35, kinds: [UserKind::Agent], mobile: true));
        $nav->add(new NavItem('Grow', 'Network tree', '/my-network/tree', 'network', 36, kinds: [UserKind::Agent]));
    }
}
