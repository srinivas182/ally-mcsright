<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Money\Money;
use Modules\Transactions\Models\Deal;

require_once __DIR__.'/contract-helpers.php';

/*
 * The page contract sweep: every page in the app, rendered for the right user, must receive every piece of
 * data its component needs (see contract-helpers.php).
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
});

it('sends every brokerage page the data its component needs', function (string $who, string $url, string $component): void {
    // Pages that ask for the password first are checked as a user who has just confirmed it.
    assertPageContract($this->actingAs($who === 'agent' ? $this->agent : $this->owner)->withSession(['auth.password_confirmed_at' => time()])->get($url), $component);
})->with([
    'dashboard' => ['owner', '/dashboard', 'dashboard'],
    'agent home' => ['agent', '/agent', 'agent/home'],
    'audit log' => ['owner', '/audit', 'audit/index'],
    'users' => ['owner', '/users', 'users/index'],
    'roles' => ['owner', '/settings/roles', 'settings/roles'],
    'organisation' => ['owner', '/settings/organisation', 'settings/organisation'],
    'profile' => ['owner', '/settings/profile', 'settings/profile'],
    'security (after password confirmation)' => ['owner', '/settings/security', 'settings/security'],
    'appearance' => ['owner', '/settings/appearance', 'settings/appearance'],
    'agent plans' => ['owner', '/settings/agent-plans', 'settings/agent-plans'],
    'brokerage billing' => ['owner', '/settings/billing', 'settings/billing'],
    'partner earnings' => ['owner', '/settings/partner', 'settings/partner'],
    'agent plans to buy' => ['agent', '/plans', 'billing/plans'],
    'compensation plans' => ['owner', '/compensation/plans', 'compensation/plans'],
    'fees' => ['owner', '/compensation/fees', 'compensation/fees'],
    'credits' => ['owner', '/compensation/credits', 'compensation/credits'],
    'simulator' => ['owner', '/compensation/simulator', 'compensation/simulator'],
    'profitability' => ['owner', '/compensation/profitability', 'compensation/profitability'],
    'revenue share admin' => ['owner', '/compensation/revenue-share', 'compensation/revenue-share-admin'],
    'my plan' => ['agent', '/my-plan', 'compensation/my-plan'],
    'my revenue share (new brokerage, no ledger yet)' => ['agent', '/revenue-share', 'compensation/revenue-share'],
    'contacts' => ['agent', '/contacts', 'crm/contacts/index'],
    'ask your database' => ['agent', '/contacts/ask', 'crm/contacts/ask'],
    'pipeline board' => ['agent', '/contacts/board', 'crm/contacts/board'],
    'duplicates' => ['agent', '/contacts/duplicates', 'crm/contacts/duplicates'],
    'lead pond' => ['agent', '/contacts/pond', 'crm/contacts/pond'],
    'work my list' => ['agent', '/contacts/work', 'crm/contacts/work'],
    'follow-up plans' => ['agent', '/follow-up-plans', 'crm/plans/index'],
    'tasks' => ['agent', '/tasks', 'crm/tasks/index'],
    "today's 10" => ['agent', '/today', 'crm/today'],
    'chart of accounts' => ['owner', '/finance/accounts', 'finance/accounts/index'],
    'journals' => ['owner', '/finance/journals', 'finance/journals/index'],
    'periods' => ['owner', '/finance/periods', 'finance/periods/index'],
    'books' => ['owner', '/finance/books', 'finance/books'],
    'adjustment' => ['owner', '/finance/adjustments/create', 'finance/adjustments/create'],
    'apps' => ['owner', '/apps', 'apps/index'],
    'switch to RightAlly' => ['owner', '/switch', 'switch/index'],
    'prospector' => ['owner', '/prospector', 'crm/prospector'],
    'database' => ['owner', '/insights/database', 'insights/database'],
    'recruiting' => ['owner', '/recruiting', 'recruiting/index'],
    'recruiting: agent database' => ['owner', '/recruiting/agents', 'recruiting/agents'],
    'recruiting: campaigns' => ['owner', '/recruiting/campaigns', 'recruiting/campaigns'],
    'recruiting: careers page' => ['owner', '/recruiting/careers', 'recruiting/careers'],
    'recruiting: my words' => ['agent', '/recruiting/my-story', 'recruiting/my-story'],
    'recruiting: refer an agent' => ['agent', '/recruiting/refer', 'recruiting/refer'],
    'recruiting: keeping agents' => ['owner', '/recruiting/retention', 'recruiting/retention'],
    'recruiting: results' => ['owner', '/recruiting/results', 'recruiting/results'],
    'pricing' => ['agent', '/pricing', 'pricing/index'],
    'pricing: buyer estimate' => ['agent', '/pricing/buyer', 'pricing/buyer'],
    'list-iq' => ['agent', '/list-iq', 'listiq/index'],
    'apps (agent)' => ['agent', '/apps', 'apps/index'],
    'an app: webhooks' => ['owner', '/apps/webhooks', 'apps/show'],
    'an app: lead forwarding email' => ['owner', '/apps/lead_email', 'apps/show'],
    'an app: coming soon' => ['agent', '/apps/cinc', 'apps/show'],
    'deals: bring over files' => ['owner', '/deals/bring-over-files', 'deals/bring-over-files'],
    'ads: list' => ['agent', '/ads', 'ads/index'],
    'grow: funnels' => ['agent', '/funnels', 'grow/funnels'],
    'grow: postcards' => ['agent', '/postcards', 'grow/postcards'],
    'listings: buyer demand' => ['agent', '/listings/demand', 'listings/demand'],
    'settings: fair housing' => ['owner', '/settings/fair-housing', 'settings/fair-housing'],
    'settings: email marketing' => ['owner', '/settings/email-marketing', 'settings/email-marketing'],
    'settings: ghl campaigns' => ['owner', '/settings/ghl-campaigns', 'settings/ghl-campaigns'],
    'ads: builder' => ['agent', '/ads/new', 'ads/new'],
    'AI assistants (owner)' => ['owner', '/apps/ai_assistants', 'apps/show'],
    'AI assistants (agent)' => ['agent', '/apps/ai_assistants', 'apps/show'],
    'AI assistant tile: Claude' => ['agent', '/apps/ai_claude', 'apps/show'],
    'AI assistant tile: Copilot' => ['owner', '/apps/ai_copilot', 'apps/show'],
    'settings: features and seats' => ['owner', '/settings/features', 'settings/features'],
    'settings: outside services' => ['owner', '/settings/services', 'settings/services'],
    'settings: approvals' => ['owner', '/settings/approvals', 'settings/approvals'],
    'settings: support access' => ['owner', '/settings/support-access', 'settings/support-access'],
    'settings: privacy requests' => ['owner', '/settings/privacy', 'settings/privacy'],
    'texting consent' => ['agent', '/contacts/texting-consent', 'crm/texting-consent'],
    'settings: brand voice' => ['owner', '/settings/brand-voice', 'settings/brand-voice'],
    'my assistants' => ['agent', '/my-assistants', 'settings/my-assistants'],
]);

it('sends record pages (a deal, a contact, a journal, an account statement) the data they need', function (): void {
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '1 Main', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    assertPageContract($this->actingAs($this->owner)->get('/deals/'.Deal::query()->latest('id')->firstOrFail()->ulid), 'deals/show');

    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'email' => 'nia@example.test', 'source' => 'open_house']);
    assertPageContract($this->actingAs($this->agent)->get('/contacts/'.Contact::query()->latest('id')->firstOrFail()->ulid), 'crm/contacts/show');

    $accounts = app(ChartOfAccounts::class);
    $accounts->seed();
    app(LedgerService::class)->post(JournalDraft::make('test', 'contract')->describe('Contract test')
        ->debit($accounts->account('1000'), Money::of(100))->credit($accounts->account('4000'), Money::of(100)));
    assertPageContract($this->actingAs($this->owner)->get('/finance/journals/'.LedgerJournal::query()->latest('id')->firstOrFail()->getRouteKey()), 'finance/journals/show');
    assertPageContract($this->actingAs($this->owner)->get('/finance/accounts/'.$accounts->account('1000')->getRouteKey().'/statement'), 'finance/accounts/statement');
});

it('sends every HQ console page the data its component needs', function (string $url, string $component): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    if (str_contains($url, '{tenant}')) {
        $url = str_replace('{tenant}', (string) $this->tenant->getRouteKey(), $url);
    }
    assertPageContract($this->actingAs($staff)->get('http://app.rightally.io'.$url), $component);
})->with([
    'tenants' => ['/hq/tenants', 'hq/tenants/index'],
    'new tenant' => ['/hq/tenants/create', 'hq/tenants/create'],
    'tenant' => ['/hq/tenants/{tenant}', 'hq/tenants/show'],
    'AI console' => ['/hq/ai', 'hq/ai/index'],
    'pricing' => ['/hq/billing', 'hq/billing'],
    'health checks' => ['/hq/health-checks', 'hq/health-checks'],
    'go-live' => ['/hq/go-live', 'hq/go-live'],
    'payments' => ['/hq/payments', 'hq/payments'],
    'payout rails' => ['/hq/payout-rails', 'hq/payout-rails'],
    'texts and calls' => ['/hq/messaging', 'hq/messaging'],
    'app demand' => ['/hq/apps', 'hq/apps'],
    'connect setup' => ['/hq/connect', 'hq/connect'],
    'feature switches' => ['/hq/switches', 'hq/switches'],
    'feature switches: one brokerage' => ['/hq/switches?tenant={tenant}', 'hq/switches'],
    'outside services' => ['/hq/services', 'settings/services'],
    'security controls' => ['/hq/controls', 'hq/controls'],
    'RightAlly website' => ['/hq/website', 'hq/website/index'],
    'RightAlly website: new page' => ['/hq/website/pages/new', 'hq/website/page'],
    'RightAlly website: new post' => ['/hq/website/posts/new', 'hq/website/post'],
]);

it('sends the sign-in pages the data they need', function (string $url, string $component): void {
    assertPageContract($this->get($url), $component);
})->with([
    'login' => ['/login', 'auth/login'],
    'forgot password' => ['/forgot-password', 'auth/forgot-password'],
]);
