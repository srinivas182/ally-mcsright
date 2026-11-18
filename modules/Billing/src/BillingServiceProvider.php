<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Contracts\CreditMeter;
use Modules\Billing\Http\RequireFeature;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Credits\CreditSpend;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Usage\WebsiteMaps;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Catalogue::class);
        $this->app->scoped(HealthCheck\HealthChecks::class);
        $this->app->scoped(GoLive\GoLive::class);
        $this->app->scoped(GoLive\SwitchCentre::class);
        $this->app->scoped(Signup\Signups::class);
        $this->app->scoped(Pricing::class);
        $this->app->scoped(Entitlements::class);
        $this->app->singleton(PaymentSettings::class);
        $this->app->scoped(AgentPayments::class);
        $this->app->scoped(BrokeragePayments::class);
        $this->app->scoped(PartnerEarnings::class);
        $this->app->bind(Gateway\PaymentGateway::class, Gateway\StripeGateway::class);
        // RightAlly Credits (specs/billing/01): the AI gateway charges the brokerage's ledger.
        $this->app->scoped(Credits\RateCard::class);
        $this->app->scoped(Credits\CreditLedger::class);
        $this->app->scoped(Credits\CreditPurchases::class);
        $this->app->bind(CreditMeter::class, Credits\LedgerCreditMeter::class);
        // Allowances (specs/billing/03): texts and call minutes counted per brokerage, website map views gated.
        $this->app->scoped(Usage\UsageMeter::class);
        $this->app->extend(SmsSender::class, fn (SmsSender $sender, $app): SmsSender => $sender instanceof Usage\MeteredSmsSender ? $sender
            : new Usage\MeteredSmsSender($sender, $app->make(TenantContext::class)));
        $this->app->extend(VoiceCaller::class, fn (VoiceCaller $caller, $app): VoiceCaller => $caller instanceof Usage\MeteredVoiceCaller ? $caller
            : new Usage\MeteredVoiceCaller($caller, $app->make(TenantContext::class)));
        $this->app->bind(WebsiteMaps::class, Usage\AllowanceWebsiteMaps::class);
        $this->app->bind(CreditSpend::class, Credits\LedgerCreditSpend::class);
    }

    public function boot(): void
    {
        Brief\CreditsBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing');
        $this->app->make(Router::class)->aliasMiddleware('feature', RequireFeature::class);

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Agent plans', '/settings/agent-plans', 'badge-dollar-sign', 70, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('Settings', 'Billing', '/settings/billing', 'credit-card', 72, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('Settings', 'RightAlly credits', '/settings/credits', 'coins', 71, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Settings', 'Partner earnings', '/settings/partner', 'trending-up', 74, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('Platform', 'Pricing', '/hq/billing', 'badge-dollar-sign', 35, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Platform', 'Payments', '/hq/payments', 'credit-card', 40, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Platform', 'RightAlly credits', '/hq/credits', 'coins', 37, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('HQ', 'Health checks', '/hq/health-checks', 'stethoscope', 12, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('HQ', 'Go-live', '/hq/go-live', 'rocket', 13, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('HQ', 'Referral programme', '/hq/affiliates', 'heart-handshake', 14, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // RF1
        $nav->add(new NavItem('Settings', 'Refer a brokerage', '/settings/refer', 'heart-handshake', 75, kinds: [UserKind::Owner])); // RF1
        $nav->add(new NavItem('Settings', 'Switch to RightAlly', '/switch', 'rocket', 65, kinds: [UserKind::Owner, UserKind::Staff]));
        // Agents only see Plans when their brokerage uses the agent-pays model.
        $this->app->make(PermissionRegistry::class)->register('Billing', [
            'billing.credits.manage' => ['Buy RightAlly credits and see the brokerage\'s credit use'],
        ]);
        Gate::define('billing.plans.view', fn (User $user): bool => $user->kind === UserKind::Agent
            && $this->app->make(Entitlements::class)->model() === 'agent_pays');
        $nav->add(new NavItem('Overview', 'Plans', '/plans', 'gift', 90, ability: 'billing.plans.view'));

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ApplyPriceChanges::class, Console\EndGrace::class, Console\BillSubsidies::class, Console\PartnerPayouts::class, Console\GrantMonthlyCredits::class, Console\WatchCreditMargins::class, Console\ExpirePurchasedCredits::class, Console\RemindPausedMaps::class, Console\PruneHealthChecks::class, Console\GoLiveWeekly::class, Console\SettleAffiliates::class]);
        }
    }
}
