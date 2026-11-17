<?php

declare(strict_types=1);

namespace Modules\Learn;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Learn (specs/learn): training, the knowledge base, Learn-IQ courses and the role-play academy. */
final class LearnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Library::class);
        $this->app->scoped(Progress::class);
        $this->app->scoped(Knowledge::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'learn');
        $this->app->make(PermissionRegistry::class)->register('Learn', ['learn.manage' => ['Manage training: courses, knowledge base pages and assignments']]);
        $this->app->make(NavigationRegistry::class)->add(new NavItem('People', 'Learn', '/learn', 'book-open', 16,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
    }
}
