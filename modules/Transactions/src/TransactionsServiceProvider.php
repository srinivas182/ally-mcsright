<?php

declare(strict_types=1);

namespace Modules\Transactions;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\Can;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Deals\DealOutcomes;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\McpToolbox;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Notices\NoticeTypes;
use Modules\Core\Workflows\WorkflowRegistry;

final class TransactionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Checklists::class, Deals::class, Documents::class, Reviews::class, EarnestMoney::class, TitleDesk::class, Cdas::class, Referrals::class, FileExport::class, Forecast::class, KeyDates::class, Reading\DocIq::class, Compliance\ComplianceScan::class, Compliance\Findings::class, Compliance\Rules::class, Deadlines\Deadlines::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->bindIf(DealIq\FormDrafter::class, DealIq\NoFormDrafter::class); // the Forms module drafts (specs/ai/02, §3)
        // Lead return on spend (CRM) reads deals and commission through this core interface.
        $this->app->bind(DealOutcomes::class, Reports\ContactDealOutcomes::class);
    }

    public function boot(): void
    {
        Workflows\DealWorkflows::register($this->app->make(WorkflowRegistry::class)); // specs/core/06, W1
        Event::listen(Events\DealStatusChanged::class, [Workflows\DealWorkflows::class, 'statusChanged']);
        Event::listen(Events\DealStatusChanged::class, [Mls\MlsGuard::class, 'statusChanged']); // the MLS update clock (specs/ai/02, §20)
        $this->app->tag([Mls\MlsStatusRisks::class], 'risk.sources');
        Brief\DealBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // DocIQ: prompts live with the module; features are declared for ai:sync-features.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $features = $this->app->make(FeatureCatalog::class);
        $features->declare(Reading\DocIq::READ, 'ai.doc_read', 'DocIQ: facts read from each uploaded deal document');
        $notices = $this->app->make(NoticeTypes::class);
        $notices->register(DealIq\DatesChangedMail::class, 'Deal dates recalculated', 'Deals');
        $notices->register(DealIq\UploadReceivedMail::class, 'Documents uploaded by the other side', 'Deals');
        $features->declare(DealIq\Intake::FEATURE, 'ai.deal_intake', 'Deal-IQ: a new deal from what the agent says');
        $features->declare(DealIq\DealAssistant::FEATURE, 'ai.deal_assistant', 'Deal-IQ: talk or type to a deal');
        $features->declare(Portal\Concierge::FEATURE, 'ai.deal_concierge', 'Deal-IQ: the client concierge in the deal portal (English and Spanish)');
        $features->declare(Offers\OfferReader::FEATURE, 'ai.offer_read', 'Deal-IQ: the terms of an uploaded offer');
        $features->declare(Offers\OfferSummary::FEATURE, 'ai.offer_summary', 'Deal-IQ: the offer comparison in plain words for the seller (English and Spanish)');
        $features->declare(Repairs\RepairRequests::FEATURE, 'ai.repair_read', 'Deal-IQ: findings read from a home inspection report');
        $notices->register(Mls\MlsStatusMail::class, 'MLS status updates due', 'Deals');
        $notices->register(Playbooks\AgentWeeklyMail::class, 'Your deals this week (Monday summary)', 'Deals');
        $notices->register(Offers\OfferReceivedMail::class, 'Offers received on your listings', 'Deals');
        $notices->register(Portal\PortalQuestionMail::class, 'Client questions from the deal portal', 'Deals');
        $notices->register(Portal\PortalFeedbackMail::class, 'Client check-ins after closing', 'Deals');
        $features->declare(Reading\DocIq::ASK, 'ai.doc_ask', 'DocIQ: questions answered from one document');
        $features->declare(Compliance\Rules::FEATURE, 'ai.compliance_rule', 'Deal-IQ: a brokerage rule written in plain English, read into a check');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SendDateReminders::class, Console\EscalateDeadlines::class, Console\ScanOpenDeals::class, Console\SendPortalUpdates::class, Console\RunPlaybooks::class]);
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
        // Tools for people's AI assistants (MCP, specs/connect/03): the same deal access rule as the screens.
        $this->app->make(McpToolbox::class)->add(Mcp\MyDeals::class);
        $this->app->make(McpToolbox::class)->add(Mcp\DealDetails::class);
        $this->app->make(McpToolbox::class)->brief('deal_deadlines', Mcp\DealDeadlines::forBrief(...));
        Gate::define('transactions.queue', fn (User $user): bool => Can::manage($user, 'transactions.review'));
        $nav->add(new NavItem('Overview', 'Deals', '/deals', 'briefcase', 25, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $nav->add(new NavItem('Overview', 'Review queue', '/deals/review', 'clipboard-check', 26, ability: 'transactions.queue'));
        $nav->add(new NavItem('Overview', 'Risk', '/deals/risk', 'shield-alert', 27, ability: 'transactions.queue'));
        $nav->add(new NavItem('Settings', 'Transactions', '/settings/transactions', 'briefcase', 76, ability: 'transactions.queue'));
    }
}
