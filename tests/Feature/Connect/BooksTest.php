<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

/* Sprint C6: QuickBooks Online and Xero — RightAlly's journals posted to the brokerage's books, in order, once,
   with accounts mapped once. */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Jane']);
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('quickbooks'), true, ['client_id' => 'qb-id', 'client_secret' => 'qb-secret'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('xero'), true, ['client_id' => 'xe-id', 'client_secret' => 'xe-secret'], null, $hq);
    app(ChartOfAccounts::class)->seed();
});

/** A commission journal: receivable 10,000 = Jane's payable 8,000 + company dollar 2,000. */
function booksJournal(string $key, string $date = '2026-11-15'): void
{
    $a = app(ChartOfAccounts::class);
    app(LedgerService::class)->post(JournalDraft::make('commission_recognised', $key)->effectiveOn(CarbonImmutable::parse($date))->describe('Commission recognised for 44 Pecan Dr')
        ->debit($a->account('1100'), Money::of(1_000_000))
        ->credit($a->subAccount('2000', 'user', test()->agent->id, 'Commission payable — Jane'), Money::of(800_000))
        ->credit($a->account('4000'), Money::of(200_000)));
}

function booksConnect(User $as, string $app, string $realm = ''): Connection
{
    $start = test()->actingAs($as)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']).($realm !== '' ? '&realmId='.$realm : ''))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', $app)->sole();
}

it('posts journals to QuickBooks once the accounts are mapped, in order and only once', function (): void {
    booksJournal('before', '2026-11-01'); // before the connection: never posted
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['access_token' => 'qb-at', 'refresh_token' => 'qb-rt', 'expires_in' => 3600]),
        'accounts.platform.intuit.com/*' => Http::response(['email' => 'owner@acme.test']),
        'quickbooks.api.intuit.com/v3/company/9130/query*' => Http::response(['QueryResponse' => ['Account' => [
            ['Id' => '35', 'Name' => 'Commissions Receivable', 'AccountType' => 'Other Current Asset'],
            ['Id' => '40', 'Name' => 'Agent Commissions Payable', 'AccountType' => 'Other Current Liability'],
            ['Id' => '50', 'Name' => 'Company Dollar', 'AccountType' => 'Income']]]]),
        'quickbooks.api.intuit.com/v3/company/9130/journalentry*' => fn (HttpRequest $r) => Http::response(['JournalEntry' => ['Id' => 'JE-'.count(Http::recorded())]]),
    ]);
    $c = booksConnect($this->owner, 'quickbooks', '9130');
    expect($c->credentials['realm_id'])->toBe('9130');

    booksJournal('deal-1');
    app(SyncRunner::class)->run($c->fresh());
    expect($c->fresh()?->settings['waiting']['reason'])->toBe('map'); // nothing posted until mapped
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'journalentry'));

    $this->actingAs($this->owner)->get('/finance/books')->assertInertia(fn (Assert $p) => $p->component('finance/books')
        ->where('books.0.post_from', '2026-11-15')->where('books.0.accounts.1.id', '40'));
    $this->actingAs($this->owner)->post('/finance/books/'.$c->ulid, ['post_from' => '2026-11-15', 'map' => ['1100' => '35', '2000' => '40', '4000' => '50']])->assertSessionHasNoErrors();
    app(SyncRunner::class)->run($c->fresh());
    app(SyncRunner::class)->run($c->fresh()); // again: nothing twice

    $posts = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'journalentry'));
    expect($posts)->toHaveCount(1);
    $body = $posts->first()[0]->data();
    expect($body['TxnDate'])->toBe('2026-11-15')->and(array_map(fn ($l) => [$l['JournalEntryLineDetail']['PostingType'], $l['JournalEntryLineDetail']['AccountRef']['value'], $l['Amount']], $body['Line']))
        ->toEqual([['Debit', '35', 10000], ['Credit', '40', 8000], ['Credit', '50', 2000]])
        ->and($body['Line'][1]['Description'])->toBe('Commission payable — Jane')
        ->and($posts->first()[0]->url())->toContain('minorversion=75')->toContain('requestid=');
});

it('shows why QuickBooks refused a journal and tries it again next run', function (): void {
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['access_token' => 'qb-at', 'refresh_token' => 'qb-rt', 'expires_in' => 3600]),
        'accounts.platform.intuit.com/*' => Http::response(['email' => 'owner@acme.test']),
        'quickbooks.api.intuit.com/*' => Http::response(['Fault' => ['Error' => [['Message' => 'Closed period', 'Detail' => 'The books are closed for October.']]]], 400),
    ]);
    $c = booksConnect($this->owner, 'quickbooks', '9130');
    $c->forceFill(['settings' => array_merge($c->settings ?? [], ['map' => ['1100' => '35', '2000' => '40', '4000' => '50']])])->save();
    booksJournal('deal-2');
    app(SyncRunner::class)->run($c->fresh());

    expect($c->fresh()?->settings['waiting'])->toMatchArray(['reason' => 'refused', 'message' => 'Closed period The books are closed for October.'])
        ->and((int) $c->fresh()?->cursor)->toBe(0);
});

it('posts to Xero as manual journals with the organisation and an idempotency key', function (): void {
    Http::fake([
        'identity.xero.com/connect/token' => Http::response(['access_token' => 'xe-at', 'refresh_token' => 'xe-rt', 'expires_in' => 1800]),
        'identity.xero.com/connect/userinfo' => Http::response(['email' => 'owner@acme.test']),
        'api.xero.com/connections' => Http::response([['tenantId' => 'T-1', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Acme Realty']]),
        'api.xero.com/api.xro/2.0/ManualJournals' => Http::response(['ManualJournals' => [['ManualJournalID' => 'MJ-1']]]),
        'api.xero.com/api.xro/2.0/Accounts*' => Http::response(['Accounts' => [['Code' => '610', 'Name' => 'Accounts Receivable', 'Type' => 'CURRENT']]]),
    ]);
    $start = $this->actingAs($this->owner)->post('/apps/xero/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    expect($q['scope'])->toContain('accounting.manualjournals')->toContain('accounting.settings')->not->toContain('accounting.transactions');
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    $this->actingAs($this->owner)->get($home);
    $c = Connection::query()->where('app_key', 'xero')->sole();
    $c->forceFill(['settings' => array_merge($c->settings ?? [], ['map' => ['1100' => '610', '2000' => '800', '4000' => '200']])])->save();

    Http::swap(new Factory);
    Http::fake([
        'api.xero.com/connections' => Http::response([['tenantId' => 'T-1', 'tenantType' => 'ORGANISATION', 'tenantName' => 'Acme Realty']]),
        'api.xero.com/api.xro/2.0/ManualJournals' => Http::response(['ManualJournals' => [['ManualJournalID' => 'MJ-1']]]),
    ]);
    booksJournal('deal-3');
    app(SyncRunner::class)->run($c->fresh());

    Http::assertSent(function (HttpRequest $r): bool {
        if (! str_ends_with($r->url(), '/ManualJournals')) {
            return false;
        }
        $lines = $r['ManualJournals'][0]['JournalLines'];

        return $r->header('xero-tenant-id')[0] === 'T-1' && $r->header('Idempotency-Key')[0] !== ''
            && array_map(fn ($l) => [$l['AccountCode'], $l['LineAmount']], $lines) == [['610', 10000], ['800', -8000], ['200', -2000]];
    });
    expect($c->fresh()?->settings['xero_org'])->toBe('Acme Realty');
});
