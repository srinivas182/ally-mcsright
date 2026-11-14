<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class TransactionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Checklists::class, Deals::class, Documents::class, Reviews::class, EarnestMoney::class, TitleDesk::class, Cdas::class, Referrals::class, FileExport::class, Forecast::class, KeyDates::class, Reading\DocIq::class] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // DocIQ: prompts live with the module; features are declared for ai:sync-features.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $features = $this->app->make(FeatureCatalog::class);
        $features->declare(Reading\DocIq::READ, 'ai.doc_read', 'DocIQ: facts read from each uploaded deal document');
        $features->declare(Reading\DocIq::ASK, 'ai.doc_ask', 'DocIQ: questions answered from one document');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SendDateReminders::class]);
        }

        $this->app->make(PermissionRegistry::class)->register('Transactions', [
            'transactions.review' => ['Review deal documents and see every deal'],
            'transactions.templates' => ['Manage checklist templates'],
            'transactions.cda.approve' => ['Approve commission disbursement authorisations (CDAs)'],
            'transactions.trust.manage' => ['Record earnest money received and released'],
            'transactions.export' => ['Export complete transaction files'],
        ]);

        $nav = $this->app->make(NavigationRegistry::class);
        // The review queue follows the same rule as the pages: owners, staff, or the review permission.
        Gate::define('transactions.queue', fn (User $user): bool => $user->isKind(UserKind::Owner, UserKind::Staff)
            || $user->can('transactions.review'));
        $nav->add(new NavItem('Overview', 'Deals', '/deals', 'briefcase', 25, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $nav->add(new NavItem('Overview', 'Review queue', '/deals/review', 'clipboard-check', 26, ability: 'transactions.queue'));
        $nav->add(new NavItem('Settings', 'Transactions', '/settings/transactions', 'briefcase', 76, ability: 'transactions.queue'));
    }
}
