<?php

declare(strict_types=1);

namespace Modules\Learn;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Learning\TrainingGate;
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
        $this->app->scoped(LaunchProgram::class);
        $this->app->scoped(CourseBuilder::class);
        $this->app->bind(TrainingGate::class, Certifications::class); // LN2: certifications that open tools
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'learn');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(CourseBuilder::FEATURE, 'ai.course_builder', 'Learn-IQ: a short course drafted by AI for the broker to check');
        $this->app->make(PermissionRegistry::class)->register('Learn', ['learn.manage' => ['Manage training: courses, knowledge base pages and assignments']]);
        $this->app->make(NavigationRegistry::class)->add(new NavItem('People', 'Learn', '/learn', 'book-open', 16,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
    }
}
