<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections as DbConnections;
use Modules\Transactions\Connect\Brokermint;
use Modules\Transactions\Connect\OpenToClose;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/* Sprint C5b: Open to Close and Brokermint (deals, same rules as every transaction app) and DocuSign (completed
   envelopes filed on the right deal). */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    $hq = User::factory()->make(['id' => 999999]);
    foreach (['open_to_close', 'brokermint'] as $key) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, $hq);
    }
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('docusign'), true, ['client_id' => 'ds-key', 'client_secret' => 'ds-secret'], null, $hq);
});

/** @param  array<string, string>  $fields */
function c5bConnect(User $as, string $app, array $fields): TestResponse
{
    return test()->actingAs($as)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', $fields);
}

it('brings Open to Close properties over as deals, whether fields come flat or as field values', function (): void {
    Http::fake([
        'api.opentoclose.com/v1/properties/77/contacts*' => Http::response(['data' => [['contact_id' => 5, 'role' => 'Buyer Agent'], ['contact_id' => 6, 'role' => 'Buyer'],
            ['contact_id' => 7, 'role' => 'Title Company']]]),
        'api.opentoclose.com/v1/contacts/5*' => Http::response(['data' => ['first_name' => 'Jane', 'last_name' => 'Agent', 'email' => 'jane@acme.test']]),
        'api.opentoclose.com/v1/contacts/6*' => Http::response(['data' => ['first_name' => 'Bo', 'last_name' => 'Ng', 'email' => 'bo@x.test', 'phone' => '512-555-0101']]),
        'api.opentoclose.com/v1/contacts/7*' => Http::response(['data' => ['name' => 'Lone Star Title', 'company' => 'Lone Star Title']]),
        'api.opentoclose.com/v1/properties*' => fn (HttpRequest $r) => preg_match('/[?&]limit=1(&|$)/', $r->url()) === 1 && ! str_contains($r->url(), 'api_token=good')
            ? Http::response(['error' => 'no'], 401)
            : Http::response(['data' => [['id' => 77, 'updated_at' => '2026-11-14T10:00:00Z', 'field_values' => [
                ['key' => 'address', 'value' => '9 Elm Ave'], ['key' => 'city', 'value' => 'Waco'], ['key' => 'state', 'value' => 'TX'], ['key' => 'zip_code', 'value' => '76701'],
                ['key' => 'contract_client_type', 'value' => 'Buyer'], ['key' => 'contract_status', 'value' => 'Pending'], ['key' => 'purchase_amount', 'value' => '310000'],
                ['key' => 'contract_date', 'value' => '2026-11-01'], ['key' => 'closing_date', 'value' => '2026-12-20'], ['key' => 'option_period_deadline', 'value' => '2026-11-11'],
            ]]]]),
    ]);
    c5bConnect($this->owner, 'open_to_close', ['api_token' => 'bad'])->assertSessionHasErrors('api_token');
    c5bConnect($this->owner, 'open_to_close', ['api_token' => 'good'])->assertSessionHasNoErrors();

    $deal = Deal::query()->sole();
    expect($deal->only(['street', 'city', 'state_code', 'type', 'status', 'price_cents', 'imported_from']))->toBe(['street' => '9 Elm Ave', 'city' => 'Waco', 'state_code' => 'TX',
        'type' => 'buyer', 'status' => 'under_contract', 'price_cents' => 31_000_000, 'imported_from' => 'Open to Close'])
        ->and($deal->agent_user_id)->toBe($this->agent->id)
        ->and(DB::connection(DbConnections::cell())->table('tx_parties')->where('deal_id', $deal->id)->orderBy('role')->pluck('name', 'role')->all())->toBe(['buyer' => 'Bo Ng', 'title' => 'Lone Star Title'])
        ->and(DB::connection(DbConnections::cell())->table('tx_dates')->where('deal_id', $deal->id)->pluck('label')->all())->toBe(['Option period deadline']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'api_token=good') && str_contains($r->url(), 'sort=-updated_at'));
});

it('reads Open to Close statuses and sides in plain words', function (): void {
    $d = fn (array $p) => OpenToClose::deal(['id' => 1, 'address' => '1 A St', 'state' => 'TX'] + $p, []);
    expect($d(['contract_status' => 'Closed', 'contract_client_type' => 'Seller'])['status'])->toBe('closed')
        ->and($d(['contract_client_type' => 'Seller'])['type'])->toBe('listing')
        ->and($d(['contract_status' => 'Cancelled'])['status'])->toBe('fell_through')
        ->and($d(['contract_client_type' => 'Landlord'])['type'])->toBe('lease')
        ->and($d(['contract_status' => 'Active'])['status'])->toBe('pre_contract');
});

it('brings Brokermint transactions over, reading dates sent as milliseconds, and skips unchanged ones next time', function (): void {
    $closing = CarbonImmutable::parse('2026-11-10', 'UTC')->getTimestampMs();
    $fake = fn (int $updatedMs) => Http::fake([
        'my.brokermint.com/api/v2/users*' => Http::response([['id' => 41, 'email' => 'JANE@acme.test']]),
        'my.brokermint.com/api/v2/transactions/900/contacts*' => Http::response([['role' => 'Listing Agent', 'email' => 'jane@acme.test', 'first_name' => 'Jane'],
            ['role' => 'Seller', 'first_name' => 'Sue', 'last_name' => 'Ray', 'email' => 'sue@x.test']]),
        'my.brokermint.com/api/v2/transactions*' => Http::response([['id' => 900, 'address' => '15 Main St', 'city' => 'Jackson', 'state' => 'MS', 'zip' => '39201',
            'status' => 'Closed', 'representing' => 'seller', 'price' => 210000, 'total_gross_commission' => 6300, 'closing_date' => $closing, 'acceptance_date' => '2026-10-01',
            'updated_at' => $updatedMs]]),
    ]);
    $fake(CarbonImmutable::parse('2026-11-14')->getTimestampMs());
    c5bConnect($this->owner, 'brokermint', ['api_key' => 'bm-key'])->assertSessionHasNoErrors();

    $deal = Deal::query()->sole();
    expect($deal->only(['street', 'state_code', 'type', 'status', 'price_cents', 'gci_cents']))->toBe(['street' => '15 Main St', 'state_code' => 'MS', 'type' => 'listing',
        'status' => 'closed', 'price_cents' => 21_000_000, 'gci_cents' => 630_000])
        ->and($deal->closed_on?->toDateString())->toBe('2026-11-10')->and($deal->agent_user_id)->toBe($this->agent->id);

    $c = Connection::query()->where('app_key', 'brokermint')->sole();
    Http::swap(new Factory);
    $fake(CarbonImmutable::parse('2026-11-14')->getTimestampMs()); // not changed since the last pass
    app(SyncRunner::class)->run($c->fresh());
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/contacts'));
    expect(Brokermint::deal(['id' => 1, 'status' => 'Pending', 'representing' => 'buyer'], [])['status'])->toBe('under_contract');
});

it('files a completed DocuSign envelope on the deal its subject or custom field names, and keeps unmatched ones for later', function (): void {
    $pecan = app(Deals::class)->create(['type' => 'buyer', 'street' => '44 Pecan Dr', 'state_code' => 'TX'], $this->agent, $this->owner);
    $elm = app(Deals::class)->create(['type' => 'listing', 'street' => '7 Elm St', 'state_code' => 'TX'], $this->agent, $this->owner);
    Http::fake([
        'account.docusign.com/oauth/token' => Http::response(['access_token' => 'ds-at', 'refresh_token' => 'ds-rt', 'expires_in' => 28800]),
        'account.docusign.com/oauth/userinfo' => Http::response(['email' => 'owner@acme.test', 'accounts' => [['account_id' => 'A-1', 'is_default' => true, 'base_uri' => 'https://na3.docusign.net']]]),
        'na3.docusign.net/restapi/v2.1/accounts/A-1/envelopes/*/documents/combined*' => fn (HttpRequest $r) => Http::response('%PDF-1.7 signed '.$r->url(), 200, ['Content-Type' => 'application/pdf']),
        'na3.docusign.net/restapi/v2.1/accounts/A-1/envelopes/E3*' => Http::response(['envelopeId' => 'E3', 'emailSubject' => 'Please DocuSign: 12 Oak St amendment']),
        'na3.docusign.net/restapi/v2.1/accounts/A-1/envelopes*' => Http::response(['envelopes' => [
            ['envelopeId' => 'E1', 'emailSubject' => 'Please DocuSign: 44 Pecan Drive purchase contract', 'status' => 'completed', 'statusChangedDateTime' => '2026-11-14T09:00:00Z'],
            ['envelopeId' => 'E2', 'emailSubject' => 'Seller disclosure', 'status' => 'completed', 'statusChangedDateTime' => '2026-11-14T09:30:00Z',
                'customFields' => ['textCustomFields' => [['name' => 'RightAlly deal', 'value' => $elm->ulid]]]],
            ['envelopeId' => 'E3', 'emailSubject' => 'Please DocuSign: 12 Oak St amendment', 'status' => 'completed', 'statusChangedDateTime' => '2026-11-14T10:00:00Z'],
        ]]),
    ]);
    $start = $this->actingAs($this->owner)->post('/apps/docusign/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    expect($q['scope'])->toBe('signature extended');
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    $this->actingAs($this->owner)->get($home)->assertRedirect();

    expect(Document::query()->where('deal_id', $pecan->id)->pluck('filename')->all())->toBe(['44 Pecan Drive purchase contract (signed).pdf'])
        ->and(Document::query()->where('deal_id', $elm->id)->pluck('filename')->all())->toBe(['Seller disclosure (signed).pdf']);
    $c = Connection::query()->where('app_key', 'docusign')->sole();
    expect($c->settings['account_id'])->toBe('A-1')->and(array_keys($c->settings['unfiled']))->toBe(['E3'])
        ->and($c->cursor)->toBe(CarbonImmutable::now()->subMinutes(5)->toIso8601String());

    // The deal for the third envelope is added later: the next run files it.
    $oak = app(Deals::class)->create(['type' => 'buyer', 'street' => '12 Oak St', 'state_code' => 'TX'], $this->agent, $this->owner);
    app(SyncRunner::class)->run($c->fresh());
    expect(Document::query()->where('deal_id', $oak->id)->count())->toBe(1)->and($c->fresh()?->settings['unfiled'])->toBe([])
        ->and(Document::query()->where('deal_id', $pecan->id)->count())->toBe(1); // never filed twice
});
