<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Ledger\AdjustmentWaiting;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\Close\CloseChecks;
use Modules\Ledger\Close\Snapshots;
use Modules\Ledger\Enums\PeriodStatus;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerJournal;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;
use Modules\Ledger\MonthNeedsAttention;
use Modules\Transactions\Models\Deal;

/*
| FH1 (specs/finance/22): manual adjustments need a second person, months close against a checklist, and
| the statements are kept exactly as they were at close.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia', 'last_name' => 'Owner']);
    $chart = app(ChartOfAccounts::class);
    $chart->seed();
    $ledger = app(LedgerService::class);
    $ledger->post(JournalDraft::make('test', 'sep')->effectiveOn(CarbonImmutable::parse('2026-09-10'))
        ->debit($chart->account('1000'), Money::of(100_000))->credit($chart->account('3000'), Money::of(100_000))->describe('Opening capital'));
    $ledger->post(JournalDraft::make('test', 'oct')->effectiveOn(CarbonImmutable::parse('2026-10-20'))
        ->debit($chart->account('1000'), Money::of(25_000))->credit($chart->account('4000'), Money::of(25_000))->describe('Company dollar'));
});

function fh1Payload(string $key, string $date = '2026-11-15', string $amount = '1250.00'): array
{
    $chart = app(ChartOfAccounts::class);

    return ['description' => 'Correct opening capital', 'effective_date' => $date, 'idempotency_key' => $key, 'lines' => [
        ['account' => $chart->account('1000')->ulid, 'side' => 'debit', 'amount' => $amount, 'memo' => null],
        ['account' => $chart->account('3000')->ulid, 'side' => 'credit', 'amount' => $amount, 'memo' => null],
    ]];
}

function fh1Month(string $start): LedgerPeriod
{
    return LedgerPeriod::query()->whereDate('starts_on', $start)->firstOrFail();
}

it('keeps a manual adjustment out of the books until a second person approves it', function (): void {
    Notification::fake();
    $second = User::factory()->owner()->create(['first_name' => 'Sam', 'last_name' => 'Second']);

    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('form-1'))->assertRedirect('/finance/adjustments');
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('form-1'))->assertRedirect();

    expect(DB::table('ledger_adjustment_requests')->count())->toBe(1)
        ->and(LedgerJournal::query()->where('journal_type', 'manual.adjustment')->count())->toBe(0);
    Notification::assertSentTo($second, AdjustmentWaiting::class);
    Notification::assertNotSentTo($this->owner, AdjustmentWaiting::class);

    $ulid = (string) DB::table('ledger_adjustment_requests')->value('ulid');
    $this->actingAs($this->owner)->get('/finance/adjustments')
        ->assertInertia(fn (Assert $page) => $page->component('finance/adjustments/index')
            ->where('adjustments.0.status', 'waiting')->where('adjustments.0.total_cents', 125_000)->where('can.sole_approver', false));

    // The person who entered it can't approve it while someone else can.
    $this->actingAs($this->owner)->post("/finance/adjustments/{$ulid}/decide", ['decision' => 'approve', 'note' => 'Looks right to me'])->assertSessionHasErrors('adjustment');
    $this->actingAs($second)->post("/finance/adjustments/{$ulid}/decide", ['decision' => 'approve'])->assertRedirect();

    $journal = LedgerJournal::query()->where('journal_type', 'manual.adjustment')->sole();
    expect(DB::table('ledger_adjustment_requests')->value('status'))->toBe('approved')
        ->and((int) DB::table('ledger_adjustment_requests')->value('journal_id'))->toBe($journal->id)
        ->and((bool) DB::table('ledger_adjustment_requests')->value('self_approved'))->toBeFalse()
        ->and($journal->created_by)->toBe($this->owner->id);
    $this->actingAs($second)->post("/finance/adjustments/{$ulid}/decide", ['decision' => 'approve'])->assertSessionHasErrors('adjustment');
});

it('needs a reason to decline, and lets the person who entered it withdraw it', function (): void {
    $second = User::factory()->owner()->create();
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('a'));
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('b'));
    [$a, $b] = DB::table('ledger_adjustment_requests')->orderBy('id')->pluck('ulid')->all();

    $this->actingAs($second)->post("/finance/adjustments/{$a}/decide", ['decision' => 'decline'])->assertSessionHasErrors('note');
    $this->actingAs($second)->post("/finance/adjustments/{$a}/decide", ['decision' => 'decline', 'note' => 'Wrong account'])->assertRedirect();
    $this->actingAs($this->owner)->post("/finance/adjustments/{$b}/decide", ['decision' => 'withdraw'])->assertRedirect();

    expect(DB::table('ledger_adjustment_requests')->orderBy('id')->pluck('status')->all())->toBe(['declined', 'withdrawn'])
        ->and(LedgerJournal::query()->where('journal_type', 'manual.adjustment')->count())->toBe(0);
});

it('lets the only approver approve their own adjustment with a written reason, and marks it', function (): void {
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('solo'));
    $ulid = (string) DB::table('ledger_adjustment_requests')->value('ulid');

    $this->actingAs($this->owner)->get('/finance/adjustments')->assertInertia(fn (Assert $page) => $page->where('can.sole_approver', true));
    $this->actingAs($this->owner)->post("/finance/adjustments/{$ulid}/decide", ['decision' => 'approve'])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post("/finance/adjustments/{$ulid}/decide", ['decision' => 'approve', 'note' => 'Bank letter confirms the capital'])->assertRedirect();

    expect((bool) DB::table('ledger_adjustment_requests')->value('self_approved'))->toBeTrue()
        ->and(LedgerJournal::query()->where('journal_type', 'manual.adjustment')->count())->toBe(1);
});

it('refuses adjustments that do not balance or fall in a closed month', function (): void {
    fh1Month('2026-09-01')->forceFill(['status' => PeriodStatus::Closed])->save();
    $bad = fh1Payload('u');
    $bad['lines'][1]['amount'] = '9.00';

    $this->actingAs($this->owner)->post('/finance/adjustments', $bad)->assertSessionHasErrors('lines');
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('c', '2026-09-30'))->assertSessionHasErrors('effective_date');
    expect(DB::table('ledger_adjustment_requests')->count())->toBe(0);
});

it('closes months in order, blocks a month with an adjustment still waiting, and keeps the statements as closed', function (): void {
    $sep = fh1Month('2026-09-01');
    $oct = fh1Month('2026-10-01');
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('wait', '2026-09-20'));

    $this->actingAs($this->owner)->post("/finance/periods/{$oct->id}/close")->assertSessionHasErrors(['period' => 'Close September 2026 first. Months close in order.']);
    $this->actingAs($this->owner)->post("/finance/periods/{$sep->id}/close")->assertSessionHasErrors('period');
    expect(fh1Month('2026-09-01')->isClosed())->toBeFalse();

    $this->actingAs($this->owner)->get('/finance/periods')->assertInertia(fn (Assert $page) => $page->component('finance/periods/index')
        ->where('periods', fn ($periods) => collect($periods)->firstWhere('id', $sep->id)['findings'][0]['level'] === 'stop'));

    $this->actingAs($this->owner)->post('/finance/adjustments/'.DB::table('ledger_adjustment_requests')->value('ulid').'/decide', ['decision' => 'withdraw']);
    $this->actingAs($this->owner)->post("/finance/periods/{$sep->id}/check")->assertRedirect();
    $this->actingAs($this->owner)->post("/finance/periods/{$sep->id}/close")->assertSessionHasNoErrors();

    $sep = $sep->fresh();
    expect($sep?->isClosed())->toBeTrue()->and($sep?->close_notes)->toBeNull()
        ->and(DB::table('ledger_close_findings')->whereNull('resolved_at')->count())->toBe(0);
    $snap = app(Snapshots::class)->get($sep, 'month');
    expect($snap['payload']['pnl']['revenue_cents'])->toBe(0)
        ->and($snap['payload']['balance']['assets_cents'])->toBe(100_000)
        ->and(array_sum(array_column($snap['payload']['trial_balance'], 'debit_cents')))->toBe(array_sum(array_column($snap['payload']['trial_balance'], 'credit_cents')))
        ->and(app(Snapshots::class)->intact($sep, 'month'))->toBeTrue();

    // Later entries don't change the copy kept at close.
    app(LedgerService::class)->post(JournalDraft::make('test', 'nov')->effectiveOn(CarbonImmutable::parse('2026-11-02'))
        ->debit(app(ChartOfAccounts::class)->account('1000'), Money::of(5_000))->credit(app(ChartOfAccounts::class)->account('4000'), Money::of(5_000)));
    expect(app(Snapshots::class)->get($sep, 'month')['sha256'])->toBe($snap['sha256']);

    $this->actingAs($this->owner)->get("/finance/periods/{$sep->id}/statements.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    DB::table('ledger_period_snapshots')->update(['payload' => str_replace('100000', '900000', (string) DB::table('ledger_period_snapshots')->value('payload'))]);
    expect(app(Snapshots::class)->intact($sep, 'month'))->toBeFalse();
});

it('asks for a written note to close with items to check, and keeps the note', function (): void {
    $sep = fh1Month('2026-09-01');
    DB::table('acct_bank_statements')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'month' => '2026-08', 'ending_balance_cents' => 0,
        'file_sha256' => str_repeat('a', 64), 'status' => 'approved', 'prepared_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->post("/finance/periods/{$sep->id}/close")->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post("/finance/periods/{$sep->id}/close", ['note' => 'Bank statement delayed; reconcile in October'])->assertSessionHasNoErrors();

    expect($sep->fresh()?->close_notes['note'])->toBe('Bank statement delayed; reconcile in October')
        ->and($sep->fresh()?->close_notes['items'][0]['title'])->toContain("bank account isn't reconciled for September 2026");
});

it('closes the year with December, keeping a full-year copy', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00'));
    $chart = app(ChartOfAccounts::class);
    app(LedgerService::class)->post(JournalDraft::make('test', 'dec')->effectiveOn(CarbonImmutable::parse('2026-12-12'))
        ->debit($chart->account('1000'), Money::of(7_500))->credit($chart->account('4000'), Money::of(7_500)));
    foreach (['2026-09-01', '2026-10-01', '2026-12-01'] as $m) {
        $this->actingAs($this->owner)->post('/finance/periods/'.fh1Month($m)->id.'/close')->assertSessionHasNoErrors();
    }
    $dec = fh1Month('2026-12-01');

    expect(app(Snapshots::class)->get($dec, 'year')['payload']['pnl']['revenue_cents'])->toBe(32_500)
        ->and(app(Snapshots::class)->get($dec, 'month')['payload']['pnl']['revenue_cents'])->toBe(7_500);
    $this->actingAs($this->owner)->get('/finance/periods')->assertInertia(fn (Assert $page) => $page->where('years.0', ['year' => 2026, 'closed' => true]));
    $this->actingAs($this->owner)->get("/finance/periods/{$dec->id}/statements.pdf?kind=year")->assertOk();
});

it('checks finished months every night and tells owners once about anything that must be fixed', function (): void {
    Notification::fake();
    $this->actingAs($this->owner)->post('/finance/adjustments', fh1Payload('n', '2026-10-05'));

    $this->artisan('ledger:close-check')->assertSuccessful();
    $this->artisan('ledger:close-check')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, MonthNeedsAttention::class, 1);
    expect(DB::table('ledger_close_findings')->where('check_key', 'waiting_adjustments')->whereNull('resolved_at')->count())->toBe(1)
        ->and(fh1Month('2026-10-01')->checked_at)->not->toBeNull();
});

it('flags payout runs missing from the books and commission booked differently from the plan', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Agent']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($agent)->post('/deals', ['type' => 'listing', 'street' => '5 Pine St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-21'])
        ->assertSessionHasNoErrors();
    $this->travelTo(CarbonImmutable::parse('2026-06-21 15:00'));
    $this->actingAs($this->owner)->post("/finance/receipts/{$deal->ulid}", ['amount' => '15000', 'received_on' => '2026-06-21', 'method' => 'wire', 'reference' => 'FED-1'])->assertSessionHasNoErrors();
    DB::table('pay_runs')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'status' => 'sent', 'pay_on' => '2026-06-25', 'total_cents' => 100,
        'created_by' => $this->owner->id, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $june = fh1Month('2026-06-01');

    $keys = fn (): array => collect(app(CloseChecks::class)->refresh($june)['findings'])->map(fn ($f) => $f['key'].':'.$f['level'])->sort()->values()->all();
    expect($keys())->toBe(['payout_runs_match:stop']);

    // The deal's calculation changed after the commission was booked.
    $calc = DB::table('comp_calculations')->where('id', $deal->fresh()?->calculation_id)->first();
    $result = json_decode((string) $calc->result, true);
    $result['agents'][0]['agent_net_cents'] = 1_100_000;
    DB::table('comp_calculations')->where('id', $calc->id)->update(['result' => json_encode($result)]);
    expect($keys())->toBe(['payout_runs_match:stop', 'splits_match:check']);
    expect(collect(app(CloseChecks::class)->refresh($june)['findings'])->firstWhere('key', 'splits_match')['detail'])
        ->toBe('Booked $12,000.00; the plan now works out $11,000.00. If the calculation was changed after closing, post an adjustment for the difference.');
});
