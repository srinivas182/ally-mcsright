<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class TransactionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Checklists::class, Deals::class, Documents::class, Reviews::class] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->app->make(PermissionRegistry::class)->register('Transactions', [
            'transactions.review' => ['Review deal documents and see every deal'],
            'transactions.templates' => ['Manage checklist templates'],
        ]);

        $nav = $this->app->make(NavigationRegistry::class);
        // The review queue follows the same rule as the pages: owners, staff, or the review permission.
        Gate::define('transactions.queue', fn (User $user): bool => $user->isKind(UserKind::Owner, UserKind::Staff)
            || $user->can('transactions.review'));
        $nav->add(new NavItem('Overview', 'Deals', '/deals', 'briefcase', 25, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $nav->add(new NavItem('Overview', 'Review queue', '/deals/review', 'clipboard-check', 26, ability: 'transactions.queue'));
    }
}
