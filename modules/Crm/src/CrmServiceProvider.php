<?php

declare(strict_types=1);

namespace Modules\Crm;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Console\SendTaskReminders;

final class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ContactVisibility::class);
        $this->app->scoped(ManageContacts::class);
        $this->app->scoped(Timeline::class);
        $this->app->scoped(Tasks::class);
        $this->app->scoped(Pipelines::class);
        $this->app->scoped(Actions\Tags::class);
        $this->app->scoped(SmartLists::class);
        $this->app->scoped(Consent::class);
        $this->app->scoped(ContactBriefs::class);
        $this->app->scoped(Actions\MergeContacts::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // AI: prompts live with the module; features are declared for ai:sync-features.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(ContactBriefs::FEATURE, 'ai.crm_brief', 'AI contact brief on every contact');

        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Contacts', '/contacts', 'contact', 10,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Overview', 'Tasks', '/tasks', 'list-checks', 20,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));

        if ($this->app->runningInConsole()) {
            $this->commands([SendTaskReminders::class]);
        }
    }
}
