<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Ledger\Accounting\BankFeeds\MxFeed;
use Modules\Ledger\Accounting\BankFeeds\TellerFeed;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
| BK1 (specs/finance/20, B15): the live, read-only bank feed (Plaid, MX or Teller) brings the operating account's
| transactions in, and a month's reconciliation can be filled from it instead of a downloaded statement.
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-02 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $this->chart = app(ChartOfAccounts::class);
    $this->chart->seed();
    $this->ledger = app(LedgerService::class);
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

function bkPlaid(string $mode = 'test'): void
{
    $keys = Crypt::encryptString('{"client_id":"cid","secret":"sec"}');
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'bank_feed', 'provider' => 'plaid', 'mode' => $mode,
        'test_keys' => $keys, 'live_keys' => $keys, 'test_contacts' => '', 'created_at' => now(), 'updated_at' => now()]);
}

it('connects the operating account through Plaid, read-only, and brings transactions in', function (): void {
    $this->actingAs($this->owner)->get('/finance/bank')->assertInertia(fn (Assert $p) => $p->where('feed.available', false));
    bkPlaid();
    Http::fake([
        'sandbox.plaid.com/link/token/create' => Http::response(['link_token' => 'link-sandbox-1']),
        'sandbox.plaid.com/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-1', 'item_id' => 'item-1']),
        'sandbox.plaid.com/accounts/get' => Http::response(['accounts' => [['account_id' => 'acc-op', 'name' => 'Operating', 'official_name' => 'Business Checking', 'mask' => '4321', 'type' => 'depository'],
            ['account_id' => 'acc-card', 'name' => 'Card', 'mask' => '9999', 'type' => 'credit']]]),
        'sandbox.plaid.com/transactions/sync' => Http::response(['added' => [
            ['transaction_id' => 't1', 'account_id' => 'acc-op', 'date' => '2026-05-01', 'amount' => -10000.00, 'name' => 'DEPOSIT', 'pending' => false],
            ['transaction_id' => 't2', 'account_id' => 'acc-op', 'date' => '2026-05-12', 'amount' => 1200.00, 'name' => 'CHECK 1001', 'pending' => false],
            ['transaction_id' => 't3', 'account_id' => 'acc-op', 'date' => '2026-05-31', 'amount' => 25.00, 'name' => 'MONTHLY SERVICE FEE', 'pending' => false],
            ['transaction_id' => 't4', 'account_id' => 'acc-op', 'date' => '2026-06-01', 'amount' => -500.00, 'name' => 'DEPOSIT', 'pending' => false],
            ['transaction_id' => 't5', 'account_id' => 'acc-op', 'date' => '2026-06-02', 'amount' => 9.99, 'name' => 'PENDING CARD', 'pending' => true],
            ['transaction_id' => 'x1', 'account_id' => 'acc-card', 'date' => '2026-05-05', 'amount' => 50.00, 'name' => 'OTHER ACCOUNT', 'pending' => false]],
            'modified' => [], 'removed' => [], 'next_cursor' => 'cur-1', 'has_more' => false]),
        'sandbox.plaid.com/accounts/balance/get' => Http::response(['accounts' => [['account_id' => 'acc-op', 'balances' => ['current' => 9275.00]]]]),
    ]);

    $this->actingAs($this->agent)->postJson('/finance/bank/feed/start')->assertForbidden();
    $this->actingAs($this->owner)->postJson('/finance/bank/feed/start')->assertOk()->assertJson(['provider' => 'plaid', 'link_token' => 'link-sandbox-1']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/link/token/create') && $r['products'] === ['transactions'] && str_starts_with($r['user']['client_user_id'], 'ra-'));

    $this->actingAs($this->owner)->post('/finance/bank/feed/finish', ['public_token' => 'public-1'])->assertRedirect(); // asks for the password first
    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/finance/bank/feed/finish', ['public_token' => 'public-1'])->assertSessionHasNoErrors();
    $row = DB::connection(Connections::cell())->table('acct_bank_feeds')->sole();
    expect($row->access)->not->toContain('access-sandbox-1')->and(json_decode(Crypt::decryptString($row->access), true)['access_token'])->toBe('access-sandbox-1');
    $this->actingAs($this->owner)->get('/finance/bank')->assertInertia(fn (Assert $p) => $p->where('feed.status', 'choose')->has('feed.accounts', 1)
        ->where('feed.accounts.0.name', 'Business Checking'));

    $this->actingAs($this->owner)->post('/finance/bank/feed/account', ['account' => 'acc-card'])->assertSessionHasErrors('account'); // not a bank account
    $this->actingAs($this->owner)->post('/finance/bank/feed/account', ['account' => 'acc-op'])->assertSessionHasNoErrors();
    $txns = DB::connection(Connections::cell())->table('acct_bank_feed_txns')->orderBy('posted_on')->pluck('amount_cents', 'external_id')->all();
    expect($txns)->toBe(['t1' => 1_000_000, 't2' => -120_000, 't3' => -2_500, 't4' => 50_000, 't5' => -999]);
    $this->actingAs($this->owner)->get('/finance/bank')->assertInertia(fn (Assert $p) => $p->where('feed.status', 'active')->where('feed.account', 'Business Checking ····4321'));
});

it('fills a month from the feed with the worked-out ending balance, and matches it to the books', function (): void {
    bkPlaid();
    Http::fake([
        'sandbox.plaid.com/item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-1', 'item_id' => 'item-1']),
        'sandbox.plaid.com/accounts/get' => Http::response(['accounts' => [['account_id' => 'acc-op', 'name' => 'Operating', 'mask' => '4321', 'type' => 'depository']]]),
        'sandbox.plaid.com/transactions/sync' => Http::response(['added' => [
            ['transaction_id' => 't1', 'account_id' => 'acc-op', 'date' => '2026-05-01', 'amount' => -10000.00, 'name' => 'DEPOSIT', 'pending' => false],
            ['transaction_id' => 't2', 'account_id' => 'acc-op', 'date' => '2026-05-12', 'amount' => 1200.00, 'name' => 'CHECK 1001', 'pending' => false],
            ['transaction_id' => 't3', 'account_id' => 'acc-op', 'date' => '2026-05-31', 'amount' => 25.00, 'name' => 'MONTHLY SERVICE FEE', 'pending' => false],
            ['transaction_id' => 't4', 'account_id' => 'acc-op', 'date' => '2026-06-01', 'amount' => -500.00, 'name' => 'DEPOSIT', 'pending' => false]],
            'modified' => [], 'removed' => [], 'next_cursor' => 'cur-1', 'has_more' => false]),
        'sandbox.plaid.com/accounts/balance/get' => Http::response(['accounts' => [['account_id' => 'acc-op', 'balances' => ['current' => 9275.00]]]]),
    ]);
    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/finance/bank/feed/finish', ['public_token' => 'public-1']);
    $this->actingAs($this->owner)->post('/finance/bank/feed/account', ['account' => 'acc-op']);
    $this->ledger->post(JournalDraft::make('test', 'capital')->effectiveOn(CarbonImmutable::parse('2026-05-01'))->describe('Owner capital')
        ->debit($this->chart->account('1000'), Money::of(1_000_000))->credit($this->chart->account('3000'), Money::of(1_000_000)));
    $this->ledger->post(JournalDraft::make('test', 'rent')->effectiveOn(CarbonImmutable::parse('2026-05-10'))->describe('Rent')
        ->debit($this->chart->account('6000'), Money::of(120_000))->credit($this->chart->account('1000'), Money::of(120_000)));

    $this->actingAs($this->owner)->post('/finance/bank/from-feed', ['month' => '2026-06'])->assertSessionHasErrors('month'); // not over yet
    $this->actingAs($this->owner)->post('/finance/bank/from-feed', ['month' => '2026-05'])->assertRedirect('/finance/bank?month=2026-05');
    // Today's $9,275 less the $500 deposit after May = $8,775 at the end of May.
    $this->actingAs($this->owner)->get('/finance/bank?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('summary.bank_cents', 877_500)->where('summary.matched', 2)
        ->where('summary.unmatched_bank.0.description', 'MONTHLY SERVICE FEE'));
    expect(DB::connection(Connections::cell())->table('acct_bank_lines')->count())->toBe(3);

    // A balance typed from the paper statement wins.
    $this->actingAs($this->owner)->post('/finance/bank/from-feed', ['month' => '2026-05', 'ending_balance' => '8,700.00']);
    $this->actingAs($this->owner)->get('/finance/bank?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('summary.bank_cents', 870_000));

    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/finance/bank/feed/disconnect')->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('acct_bank_feeds')->count())->toBe(0)->and(DB::connection(Connections::cell())->table('acct_bank_feed_txns')->count())->toBe(0);
});

it('reads MX and Teller in their own words', function (): void {
    Http::fake([
        'int-api.mx.com/users/USR-1/accounts?*' => Http::response(['accounts' => [['guid' => 'ACT-1', 'name' => 'Checking', 'account_number' => '00001234', 'type' => 'CHECKING'],
            ['guid' => 'ACT-2', 'name' => 'Card', 'type' => 'CREDIT_CARD']]]),
        'int-api.mx.com/users/USR-1/accounts/ACT-1/transactions*' => Http::response(['transactions' => [['guid' => 'TRN-1', 'amount' => 12.5, 'type' => 'DEBIT', 'posted_at' => '2026-05-03T10:00:00Z',
            'description' => 'Coffee', 'status' => 'POSTED'], ['guid' => 'TRN-2', 'amount' => 100, 'type' => 'CREDIT', 'posted_at' => '2026-05-04T10:00:00Z', 'description' => 'Deposit', 'status' => 'POSTED']],
            'pagination' => ['total_pages' => 1]]),
        'api.teller.io/accounts' => Http::response([['id' => 'acc_1', 'name' => 'Checking', 'last_four' => '7777', 'type' => 'depository']]),
        'api.teller.io/accounts/acc_1/transactions*' => Http::response([['id' => 'txn_1', 'amount' => '-42.10', 'date' => '2026-05-06', 'description' => 'Utility', 'status' => 'posted']]),
        'api.teller.io/accounts/acc_1/balances' => Http::response(['ledger' => '1500.25']),
    ]);
    $mx = new MxFeed(['client_id' => 'c', 'api_key' => 'k'], true);
    expect($mx->accounts(['user_guid' => 'USR-1']))->toBe([['id' => 'ACT-1', 'name' => 'Checking', 'mask' => '1234']])
        ->and(array_column($mx->transactions(['user_guid' => 'USR-1'], 'ACT-1', null)['added'], 'cents'))->toBe([-1250, 10_000]);
    $teller = new TellerFeed(['application_id' => 'app_1', 'certificate' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----"], true);
    expect($teller->accounts(['access_token' => 'token_abc']))->toBe([['id' => 'acc_1', 'name' => 'Checking', 'mask' => '7777']])
        ->and($teller->transactions(['access_token' => 'token_abc'], 'acc_1', null)['added'][0]['cents'])->toBe(-4210)
        ->and($teller->balance(['access_token' => 'token_abc'], 'acc_1'))->toBe(150_025);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'api.teller.io') && $r->hasHeader('Authorization', 'Basic '.base64_encode('token_abc:')));
});
