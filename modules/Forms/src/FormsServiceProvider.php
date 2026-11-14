<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class FormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Templates::class, Packets::class, Signing::class, Sealer::class, Negotiations::class, Identity\IdentityChecks::class, Summaries::class, ClauseCheck::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->bindIf(Identity\IdentityProvider::class, fn ($app): Identity\IdentityProvider => config('forms.identity_driver') === 'fake'
            ? $app->make(Identity\FakeIdentity::class) : $app->make(Identity\StripeIdentity::class));
        $this->app->bindIf(Identity\OwnerRecords::class, Identity\NoOwnerRecords::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SigningReminders::class, Console\SealKey::class]);
        }
        $this->app->make(PermissionRegistry::class)->register('Transactions', [
            'forms.manage' => ['Manage forms: templates, fields and clauses'],
        ]);
        // AI: the plain-English summary prompt lives with the module; the feature is declared for ai:sync-features.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Summaries::FEATURE, 'ai.forms_summary', 'Plain-English summary of a forms packet for signers');
        Gate::define('forms.templates', fn (User $user): bool => Templates::manages($user));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'Forms', '/forms/templates', 'file-text', 77, ability: 'forms.templates'));
    }
}
