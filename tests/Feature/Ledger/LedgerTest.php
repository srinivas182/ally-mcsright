<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\Events\JournalPosted;
use Modules\Ledger\Events\JournalReversed;
use Modules\Ledger\Exceptions\AlreadyReversed;
use Modules\Ledger\Exceptions\ClosedPeriod;
use Modules\Ledger\Exceptions\ImmutableLedgerRecord;
use Modules\Ledger\Exceptions\InvalidJournal;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerLine;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;
use Tests\Support\TenantIsolation;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->chart = app(ChartOfAccounts::class);
    $this->chart->seed();
    $this->ledger = app(LedgerService::class);
});

/** GCI of $9,000 received: 90% to the agent, 10% company dollar. */
function commissionDraft(string $key, int $gciCents = 900_000): JournalDraft
{
    $chart = app(ChartOfAccounts::class);
    $split = Money::of($gciCents)->allocate(['agent' => '90', 'company' => '10']);

    return JournalDraft::make('commission.calculated', $key)
        ->debit($chart->account('1100'), Money::of($gciCents), 'GCI receivable')
        ->credit($chart->subAccount('2000', 'agent', 42), $split['agent'], 'Agent share')
        ->credit($chart->account('4000'), $split['company'], 'Company dollar')
        ->source('deal', 'DEAL-1');
}

it('seeds the system chart of accounts once', function (): void {
    $this->chart->seed();

    expect(LedgerAccount::query()->where('is_system', true)->count())->toBe(count(ChartOfAccounts::SYSTEM));
});

it('posts a balanced journal and updates balances in natural sign', function (): void {
    Event::fake([JournalPosted::class]);

    $journal = $this->ledger->post(commissionDraft('deal-1'));

    expect($journal->lines()->count())->toBe(3)
        ->and($this->ledger->balance($this->chart->account('1100'))->cents)->toBe(900_000)
        ->and($this->ledger->balance($this->chart->subAccount('2000', 'agent', 42))->cents)->toBe(810_000)
        ->and($this->ledger->balance($this->chart->account('4000'))->cents)->toBe(90_000);

    Event::assertDispatched(JournalPosted::class, fn ($e) => $e->journalUlid === $journal->ulid);
});

it('rejects journals that do not balance or are malformed', function (Closure $build): void {
    $this->ledger->post($build($this->chart));
})->throws(InvalidJournal::class)->with([
    'unbalanced' => [fn ($c) => JournalDraft::make('x', 'k1')->debit($c->account('1000'), Money::of(100))->credit($c->account('3000'), Money::of(99))],
    'single line' => [fn ($c) => JournalDraft::make('x', 'k2')->debit($c->account('1000'), Money::of(100))],
    'zero line' => [fn ($c) => JournalDraft::make('x', 'k3')->debit($c->account('1000'), Money::of(0))->credit($c->account('3000'), Money::of(0))],
    'negative line' => [fn ($c) => JournalDraft::make('x', 'k4')->debit($c->account('1000'), Money::of(-100))->credit($c->account('3000'), Money::of(-100))],
    'mixed currency' => [fn ($c) => JournalDraft::make('x', 'k5')->debit($c->account('1000'), Money::of(100, 'USD'))->credit($c->account('3000'), Money::of(100, 'CAD'))],
]);

it('rejects posting to an archived account', function (): void {
    $account = $this->chart->account('1300');
    $account->status = 'archived';
    $account->save();

    $this->ledger->post(JournalDraft::make('x', 'k')->debit($account, Money::of(100))->credit($this->chart->account('3000'), Money::of(100)));
})->throws(InvalidJournal::class);

it('is idempotent by posting key', function (): void {
    $first = $this->ledger->post(commissionDraft('deal-1'));
    $second = $this->ledger->post(commissionDraft('deal-1'));

    expect($second->id)->toBe($first->id)
        ->and(LedgerJournal::query()->count())->toBe(1)
        ->and(LedgerLine::query()->count())->toBe(3)
        ->and($this->ledger->balance($this->chart->account('1100'))->cents)->toBe(900_000);
});

it('reverses a journal exactly and only once', function (): void {
    Event::fake([JournalReversed::class]);
    $journal = $this->ledger->post(commissionDraft('deal-1'));

    $reversal = $this->ledger->reverse($journal, 'Deal fell through');

    expect($this->ledger->balance($this->chart->account('1100'))->cents)->toBe(0)
        ->and($this->ledger->balance($this->chart->subAccount('2000', 'agent', 42))->cents)->toBe(0)
        ->and($reversal->reversal_of_id)->toBe($journal->id)
        ->and($journal->fresh()?->reversed_by_id)->toBe($reversal->id);
    Event::assertDispatched(JournalReversed::class);

    expect(fn () => $this->ledger->reverse($journal->fresh() ?? $journal, 'again'))->toThrow(AlreadyReversed::class)
        ->and(fn () => $this->ledger->reverse($reversal, 'reverse the reversal'))->toThrow(AlreadyReversed::class);
});

it('refuses to post into a closed period', function (): void {
    $this->ledger->post(commissionDraft('june-1')->effectiveOn(CarbonImmutable::parse('2026-06-15')));
    $period = LedgerPeriod::query()->whereDate('starts_on', '2026-06-01')->firstOrFail();
    $period->status = PeriodStatus::Closed;
    $period->save();

    $this->ledger->post(commissionDraft('june-2')->effectiveOn(CarbonImmutable::parse('2026-06-20')));
})->throws(ClosedPeriod::class);

it('reports balances as of a date', function (): void {
    $this->ledger->post(commissionDraft('may', 100_000)->effectiveOn(CarbonImmutable::parse('2026-05-10')));
    $this->ledger->post(commissionDraft('july', 200_000)->effectiveOn(CarbonImmutable::parse('2026-07-10')));
    $receivable = $this->chart->account('1100');

    expect($this->ledger->balance($receivable, CarbonImmutable::parse('2026-06-30'))->cents)->toBe(100_000)
        ->and($this->ledger->balance($receivable)->cents)->toBe(300_000);
});

it('never lets journals or lines be changed or deleted', function (): void {
    $journal = $this->ledger->post(commissionDraft('deal-1'));
    $line = $journal->lines()->firstOrFail();

    expect(function () use ($line): void {
        $line->debit_cents = 1;
        $line->save();
    })->toThrow(ImmutableLedgerRecord::class)
        ->and(fn () => $line->delete())->toThrow(ImmutableLedgerRecord::class)
        ->and(function () use ($journal): void {
            $journal->description = 'edited';
            $journal->save();
        })->toThrow(ImmutableLedgerRecord::class)
        ->and(fn () => $journal->delete())->toThrow(ImmutableLedgerRecord::class);
});

it('creates one period per month', function (): void {
    $this->ledger->post(commissionDraft('a')->effectiveOn(CarbonImmutable::parse('2026-03-02')));
    $this->ledger->post(commissionDraft('b')->effectiveOn(CarbonImmutable::parse('2026-03-30')));
    $this->ledger->post(commissionDraft('c')->effectiveOn(CarbonImmutable::parse('2026-04-01')));

    expect(LedgerPeriod::query()->count())->toBe(2);
});

it('keeps exact balances across many postings and reconciles', function (): void {
    $expected = 0;
    for ($i = 1; $i <= 200; $i++) {
        $gci = 10_000 + $i * 37;
        $expected += $gci;
        $this->ledger->post(commissionDraft("deal-{$i}", $gci));
    }

    expect($this->ledger->balance($this->chart->account('1100'))->cents)->toBe($expected);
    expect(Artisan::call('ledger:reconcile'))->toBe(0);
});

it('requires a tenant to post', function (): void {
    $draft = commissionDraft('deal-1');
    app(TenantContext::class)->forget();

    $this->ledger->post($draft);
})->throws(MissingTenantContext::class);

it('cannot post to another tenant\'s account', function (): void {
    $foreignAccount = $this->chart->account('1000');
    $other = Tenant::factory()->create();

    app(TenantContext::class)->runAs($other, function () use ($foreignAccount): void {
        app(ChartOfAccounts::class)->seed();
        app(LedgerService::class)->post(
            JournalDraft::make('x', 'steal')->debit($foreignAccount, Money::of(100))->credit(app(ChartOfAccounts::class)->account('3000'), Money::of(100)),
        );
    });
})->throws(InvalidJournal::class, 'does not belong to the current tenant');

it('isolates ledger accounts and journals between tenants', function (): void {
    app(TenantContext::class)->forget();

    TenantIsolation::assertIsolated(LedgerAccount::class, function () {
        app(ChartOfAccounts::class)->seed();

        return app(ChartOfAccounts::class)->account('1000');
    });
});
