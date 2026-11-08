<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;

const FINANCE_ABILITIES = ['finance.ledger.view', 'finance.ledger.adjust', 'finance.ledger.export', 'finance.periods.close'];

function grantFinance(): void
{
    foreach (FINANCE_ABILITIES as $ability) {
        Gate::define($ability, fn (): bool => true);
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
    $this->tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $this->host = 'http://acme.rightally.app';
    [$this->user, $this->agent] = app(TenantContext::class)->runAs($this->tenant, fn () => [
        User::factory()->owner()->create(), User::factory()->create(),
    ]);

    app(TenantContext::class)->runAs($this->tenant, function (): void {
        $chart = app(ChartOfAccounts::class);
        $chart->seed();
        $ledger = app(LedgerService::class);
        $ledger->post(JournalDraft::make('test', 'oct')->effectiveOn(CarbonImmutable::parse('2026-10-20'))
            ->debit($chart->account('1000'), Money::of(100_000), '=SUM(A1)')
            ->credit($chart->account('3000'), Money::of(100_000))->describe('Opening capital'));
        $ledger->post(JournalDraft::make('test', 'nov')->effectiveOn(CarbonImmutable::parse('2026-11-05'))
            ->debit($chart->account('1000'), Money::of(25_000))
            ->credit($chart->account('4000'), Money::of(25_000))->describe('Company dollar'));
    });
});

it('denies every finance screen to anyone but the brokerage owner', function (string $path): void {
    $this->actingAs($this->agent)->get($this->host.$path)->assertForbidden();
    $this->actingAs($this->user)->get($this->host.$path)->assertOk();
})->with(['/finance/accounts', '/finance/journals', '/finance/periods', '/finance/adjustments/create']);

it('is not available on the HQ host or central hosts', function (): void {
    grantFinance();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');

    $hqStaff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->actingAs($hqStaff)->get('http://app.rightally.io/finance/accounts')->assertNotFound();
    $this->get('http://localhost/finance/accounts')->assertNotFound();
});

it('lists accounts with balances in natural sign', function (): void {
    grantFinance();

    $this->actingAs($this->user)->get($this->host.'/finance/accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/accounts/index')
            ->where('accounts.0.code', '1000')
            ->where('accounts.0.balance.cents', 125_000));
});

it('shows a statement with opening, running and closing balances', function (): void {
    grantFinance();
    $cash = app(TenantContext::class)->runAs($this->tenant, fn () => app(ChartOfAccounts::class)->account('1000'));

    $this->actingAs($this->user)->get($this->host."/finance/accounts/{$cash->ulid}/statement?from=2026-11-01&to=2026-11-30")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/accounts/statement')
            ->where('opening.cents', 100_000)
            ->where('lines.0.amount.cents', 25_000)
            ->where('lines.0.balance.cents', 125_000)
            ->where('closing.cents', 125_000));
});

it('exports the statement as CSV with formula injection neutralised', function (): void {
    grantFinance();
    $cash = app(TenantContext::class)->runAs($this->tenant, fn () => app(ChartOfAccounts::class)->account('1000'));

    $csv = $this->actingAs($this->user)->get($this->host."/finance/accounts/{$cash->ulid}/statement.csv?from=2026-10-01&to=2026-11-30")
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('1000.00')
        ->toContain('1250.00')
        ->toContain("'=SUM(A1)")
        ->not->toContain(',=SUM(A1)');
});

it('adds custom accounts and only archives them at zero balance', function (): void {
    grantFinance();

    $this->actingAs($this->user)->post($this->host.'/finance/accounts', ['code' => '61', 'name' => 'Bad', 'type' => 'expense'])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->user)->post($this->host.'/finance/accounts', ['code' => '6100-mktg', 'name' => 'Marketing', 'type' => 'expense'])
        ->assertSessionHasNoErrors();

    [$custom, $system] = app(TenantContext::class)->runAs($this->tenant, fn () => [
        LedgerAccount::query()->where('code', '6100-MKTG')->firstOrFail(),
        LedgerAccount::query()->where('code', '1000')->firstOrFail(),
    ]);

    $this->actingAs($this->user)->post($this->host."/finance/accounts/{$system->ulid}/archive")->assertSessionHasErrors('account');
    $this->actingAs($this->user)->post($this->host."/finance/accounts/{$custom->ulid}/archive")->assertSessionHasNoErrors();
    expect($custom->fresh()?->status)->toBe('archived');
});

it('posts a balanced manual adjustment once per form submission', function (): void {
    grantFinance();
    [$cash, $equity] = app(TenantContext::class)->runAs($this->tenant, fn () => [
        app(ChartOfAccounts::class)->account('1000')->ulid, app(ChartOfAccounts::class)->account('3000')->ulid,
    ]);
    $payload = [
        'description' => 'Correct opening capital',
        'effective_date' => '2026-11-15',
        'idempotency_key' => 'form-1',
        'lines' => [
            ['account' => $cash, 'side' => 'debit', 'amount' => '1,250.00', 'memo' => null],
            ['account' => $equity, 'side' => 'credit', 'amount' => '1250.00', 'memo' => null],
        ],
    ];

    $this->actingAs($this->user)->post($this->host.'/finance/adjustments', $payload)->assertSessionHasErrors('lines.0.amount');

    $payload['lines'][0]['amount'] = '1250.00';
    $this->actingAs($this->user)->post($this->host.'/finance/adjustments', $payload)->assertRedirect();
    $this->actingAs($this->user)->post($this->host.'/finance/adjustments', $payload)->assertRedirect();

    $count = app(TenantContext::class)->runAs($this->tenant, fn () => LedgerJournal::query()->where('journal_type', 'manual.adjustment')->count());
    expect($count)->toBe(1);
});

it('rejects unbalanced adjustments and adjustments in closed months', function (): void {
    grantFinance();
    [$cash, $equity] = app(TenantContext::class)->runAs($this->tenant, function () {
        LedgerPeriod::query()->whereDate('starts_on', '2026-10-01')->update(['status' => PeriodStatus::Closed]);

        return [app(ChartOfAccounts::class)->account('1000')->ulid, app(ChartOfAccounts::class)->account('3000')->ulid];
    });
    $lines = fn (string $a, string $b) => [
        ['account' => $cash, 'side' => 'debit', 'amount' => $a],
        ['account' => $equity, 'side' => 'credit', 'amount' => $b],
    ];

    $this->actingAs($this->user)->post($this->host.'/finance/adjustments', [
        'description' => 'Unbalanced', 'effective_date' => '2026-11-15', 'idempotency_key' => 'u', 'lines' => $lines('10.00', '9.00'),
    ])->assertSessionHasErrors('lines');

    $this->actingAs($this->user)->post($this->host.'/finance/adjustments', [
        'description' => 'Backdated', 'effective_date' => '2026-10-31', 'idempotency_key' => 'c', 'lines' => $lines('10.00', '10.00'),
    ])->assertSessionHasErrors('effective_date');
});

it('reverses a journal from the detail screen with a reason', function (): void {
    grantFinance();
    $journal = app(TenantContext::class)->runAs($this->tenant, fn () => LedgerJournal::query()->where('posting_key', 'nov')->firstOrFail());

    $this->actingAs($this->user)->get($this->host."/finance/journals/{$journal->ulid}")
        ->assertInertia(fn (Assert $page) => $page->component('finance/journals/show')->where('can.reverse', true));

    $this->actingAs($this->user)->post($this->host."/finance/journals/{$journal->ulid}/reverse", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->user)->post($this->host."/finance/journals/{$journal->ulid}/reverse", ['reason' => 'Posted twice'])->assertRedirect();

    expect($journal->fresh()?->reversed_by_id)->not->toBeNull();
});

it('closes only finished months', function (): void {
    grantFinance();
    [$october, $november] = app(TenantContext::class)->runAs($this->tenant, fn () => [
        LedgerPeriod::query()->whereDate('starts_on', '2026-10-01')->firstOrFail(),
        LedgerPeriod::query()->whereDate('starts_on', '2026-11-01')->firstOrFail(),
    ]);

    $this->actingAs($this->user)->post($this->host."/finance/periods/{$november->id}/close")->assertSessionHasErrors('period');
    $this->actingAs($this->user)->post($this->host."/finance/periods/{$october->id}/close")->assertSessionHasNoErrors();

    expect($october->fresh()?->status)->toBe(PeriodStatus::Closed);
});

it('never shows another brokerage\'s ledger records', function (): void {
    grantFinance();
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $otherOwner = app(TenantContext::class)->runAs($other, fn () => User::factory()->owner()->create());
    $foreign = app(TenantContext::class)->runAs($this->tenant, fn () => LedgerJournal::query()->firstOrFail());

    $this->actingAs($otherOwner)->get("http://other.rightally.app/finance/journals/{$foreign->ulid}")->assertNotFound();
    $this->actingAs($otherOwner)->get('http://other.rightally.app/finance/journals')
        ->assertInertia(fn (Assert $page) => $page->has('journals.data', 0));

    // Carrying a session from one brokerage to another signs the user out.
    $this->actingAs($this->user)->get('http://other.rightally.app/finance/journals')->assertRedirect('/login');
});
