<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;
use Modules\Payouts\TrustOverdue;
use Modules\Payouts\TrustReconciliation;
use Modules\Transactions\EarnestMoney;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    $this->agent = User::factory()->create();
    $this->trust = app(TrustReconciliation::class);
    $money = app(EarnestMoney::class);
    [$this->dealA, $this->dealB] = [trustRecDeal('1 River Rd'), trustRecDeal('2 Hill St')];
    $a = $money->expect($this->dealA, 1_000_000, 'trust', null, null, $this->owner);
    $money->receive($a, CarbonImmutable::parse('2026-06-05'), 'check', null, $this->owner);
    $money->release($a, false, 'Closing (applied to purchase)', CarbonImmutable::parse('2026-06-28'), $this->owner);
    $b = $money->expect($this->dealB, 500_000, 'trust', null, null, $this->owner);
    $money->receive($b, CarbonImmutable::parse('2026-06-25'), 'wire', null, $this->owner);
});

function trustRecDeal(string $street): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'buyer', 'street' => $street, 'city' => 'Austin', 'state_code' => 'TX', 'price' => '400000']);

    return Deal::query()->latest('id')->firstOrFail();
}

const TRUST_JUNE_CSV = "Date,Description,Amount\n06/06/2026,DEPOSIT,10000.00\n06/26/2026,WIRE IN,5000.00\n";

it('matches the bank statement and agrees bank, book and clients to the cent, approved by a second person and locked', function (): void {
    $this->trust->import('2026-06', TRUST_JUNE_CSV, 1_500_000, $this->owner);
    $s = $this->trust->summary('2026-06');

    expect($s)->toMatchArray(['bank_cents' => 1_500_000, 'adjusted_bank_cents' => 500_000, 'book_cents' => 500_000, 'clients_cents' => 500_000, 'balanced' => true])
        ->and($s['outstanding'])->toHaveCount(1)->and($s['outstanding'][0]['amount'])->toBe(-1_000_000)
        ->and($s['unexplained'])->toBe([])->and($s['clients'])->toBe([['deal' => '2 Hill St', 'cents' => 500_000, 'negative' => false, 'stale' => false]]);

    expect(fn () => $this->trust->approve('2026-06', $this->owner))->toThrow(ValidationException::class, 'different person');
    $this->actingAs($this->second)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/trust/approve', ['month' => '2026-06'])->assertSessionHasNoErrors();
    expect(DB::table('trust_statements')->value('status'))->toBe('approved')
        ->and(fn () => $this->trust->import('2026-06', TRUST_JUNE_CSV, 1_500_000, $this->owner))->toThrow(ValidationException::class, 'locked');

    $this->actingAs($this->owner)->get('/finance/trust?month=2026-06&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->agent)->get('/finance/trust?month=2026-06')->assertForbidden();
});

it('blocks approval while a bank line is unexplained, and allows a manual match outside the window', function (): void {
    $this->trust->import('2026-06', TRUST_JUNE_CSV."06/30/2026,SERVICE FEE,-25.00\n", 1_497_500, $this->owner);
    $s = $this->trust->summary('2026-06');
    expect($s['balanced'])->toBeFalse()->and($s['unexplained'][0])->toMatchArray(['description' => 'SERVICE FEE', 'cents' => -2_500])
        ->and(fn () => $this->trust->approve('2026-06', $this->second))->toThrow(ValidationException::class, 'do not agree');

    // A deposit the bank posted 9 days after the ledger: not matched automatically, matched by hand.
    $this->trust->import('2026-06', "Date,Description,Amount\n06/14/2026,DEPOSIT,10000.00\n06/26/2026,WIRE IN,5000.00\n", 1_500_000, $this->owner);
    $line = DB::table('trust_statement_lines')->where('amount_cents', 1_000_000)->value('id');
    expect($this->trust->summary('2026-06')['balanced'])->toBeFalse();
    $ledgerLine = collect($this->trust->unmatchedLedger(CarbonImmutable::parse('2026-06-30')))->firstWhere('amount', 1_000_000)['id'];
    $this->actingAs($this->owner)->post('/finance/trust/match', ['statement_line' => $line, 'ledger_line' => $ledgerLine])->assertSessionHasNoErrors();
    expect($this->trust->summary('2026-06')['balanced'])->toBeTrue();
});

it('refuses approval when a deal is overdrawn, and flags money held after closing', function (): void {
    $a = app(ChartOfAccounts::class);
    app(LedgerService::class)->post(JournalDraft::make('test', 'overdraw')->effectiveOn(CarbonImmutable::parse('2026-06-29'))->describe('Paid out too much')
        ->debit($a->subAccount('2300', 'deal', $this->dealA->id, 'x'), Money::of(100_000))->credit($a->account('1010'), Money::of(100_000)));
    $this->trust->import('2026-06', TRUST_JUNE_CSV, 1_500_000, $this->owner);
    $s = $this->trust->summary('2026-06');
    expect($s['negative_clients'])->toBe(1)->and(collect($s['clients'])->firstWhere('deal', '1 River Rd')['cents'])->toBe(-100_000)
        ->and(fn () => $this->trust->approve('2026-06', $this->second))->toThrow(ValidationException::class, 'negative');

    DB::table('tx_deals')->where('id', $this->dealB->id)->update(['status' => 'closed', 'closed_on' => '2026-06-10']);
    expect(collect($this->trust->summary('2026-06')['clients'])->firstWhere('deal', '2 Hill St')['stale'])->toBeTrue();
});

it('reads bank files with separate debit and credit columns', function (): void {
    $this->actingAs($this->owner)->post('/finance/trust/import', ['month' => '2026-06', 'ending' => '15000',
        'file' => UploadedFile::fake()->createWithContent('june.csv', "Posting Date,Memo,Debit,Credit\n2026-06-06,DEPOSIT,,10000.00\n2026-06-26,WIRE IN,,\"5,000.00\"\n")])->assertRedirect('/finance/trust?month=2026-06');
    $this->actingAs($this->owner)->get('/finance/trust?month=2026-06')->assertInertia(fn (Assert $page) => $page->component('finance/trust')->where('summary.balanced', true));
});

it('reminds owners on the 10th when last month is not reconciled', function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-10 08:00'));
    $this->artisan('payouts:trust-reminder')->assertSuccessful();
    Notification::assertSentTo($this->owner, TrustOverdue::class);

    Notification::fake();
    $this->trust->import('2026-06', TRUST_JUNE_CSV, 1_500_000, $this->owner);
    $this->trust->approve('2026-06', $this->second);
    $this->artisan('payouts:trust-reminder');
    Notification::assertNothingSent();
});
