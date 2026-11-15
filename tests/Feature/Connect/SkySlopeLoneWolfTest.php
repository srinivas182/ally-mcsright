<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
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
use Modules\Transactions\Connect\BringOverFiles;
use Modules\Transactions\Connect\LoneWolf;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/* Sprint C5a-2: SkySlope and Lone Wolf — switch in a weekend with the brokerage's own key or a read-only user. */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('skyslope'), true, ['client_id' => 'ss-client', 'client_secret' => 'ss-secret'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('lone_wolf'), true, ['client_id' => 'lw-client'], null, $hq);
});

/** @param  array<string, string>  $fields */
function txKeyConnect(User $as, string $app, array $fields): TestResponse
{
    return test()->actingAs($as)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', $fields);
}

/** @return array<string, mixed> */
function ssSale(): array
{
    return ['saleGuid' => 'S1', 'listingGuid' => 'L1', 'agentGuid' => 'U-JANE', 'status' => 'pending', 'salePrice' => 450000, 'listingPrice' => 465000,
        'contractAcceptedDate' => '2026-11-02T00:00:00', 'escrowClosingDate' => '2026-12-12T00:00:00',
        'property' => ['streetNumber' => '44', 'streetAddress' => 'Pecan Dr', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78745'],
        'commission' => ['officeGrossCommission' => 13500], 'buyers' => [['firstName' => 'Bo', 'lastName' => 'Ng', 'email' => 'bo@x.test', 'phoneNumber' => '512-555-0199']],
        'sellers' => [['firstName' => 'Sue', 'lastName' => 'Ray']], 'titleContact' => ['firstName' => 'Tia', 'lastName' => 'Title', 'company' => 'Lone Star Title']];
}

function ssFake(array $sales, array $listings, array $changes = []): void
{
    Http::fake([
        'api.skyslope.com/auth/login' => fn (HttpRequest $r) => $r['clientID'] === 'ss-client' && str_starts_with($r->header('Authorization')[0], 'SS good-key:')
            ? Http::response(['Session' => 'sess-1', 'Expiration' => '2026-11-15T12:00:00Z']) : Http::response(['error' => 'no'], 401),
        'api.skyslope.com/api/users*' => Http::response(['links' => [], 'value' => ['users' => [['userGuid' => 'U-JANE', 'email' => 'jane@acme.test']]]]),
        'api.skyslope.com/api/files/sales*' => fn (HttpRequest $r) => Http::response(['value' => ['sales' => (int) ($r->data()['PageNumber'] ?? 1) === 1 ? $sales : []]]),
        'api.skyslope.com/api/files/listings*' => fn (HttpRequest $r) => Http::response(['value' => ['listings' => (int) ($r->data()['PageNumber'] ?? 1) === 1 ? $listings : []]]),
        'api.skyslope.com/api/files*' => Http::response(['links' => [], 'value' => $changes]),
    ]);
}

it('signs in to SkySlope with RightAlly\'s client pair signed by the brokerage\'s secret, and refuses a wrong key', function (): void {
    ssFake([], []);
    txKeyConnect($this->owner, 'skyslope', ['access_key' => 'bad-key', 'access_secret' => 'x'])->assertSessionHasErrors('access_key');
    txKeyConnect($this->owner, 'skyslope', ['access_key' => 'good-key', 'access_secret' => 'brokerage-secret'])->assertSessionHasNoErrors();

    Http::assertSent(function (HttpRequest $r): bool {
        if (! str_ends_with($r->url(), '/auth/login') || ! str_starts_with($r->header('Authorization')[0], 'SS good-key:')) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', 'ss-client:ss-secret:'.$r->header('Timestamp')[0], 'brokerage-secret', true));

        return $r->header('Authorization')[0] === 'SS good-key:'.$expected && $r->header('Timestamp')[0] === '2026-11-15T10:00:00Z';
    });
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/api/files/sales') && $r->header('Session')[0] === 'sess-1');
});

it('brings SkySlope sales and open listings over as deals, a listing and its sale as one deal', function (): void {
    $active = ['listingGuid' => 'L2', 'agentGuid' => 'U-JANE', 'status' => 'active', 'listingPrice' => 299000,
        'property' => ['streetNumber' => '7', 'streetAddress' => 'Elm St', 'unit' => 'B', 'city' => 'Waco', 'state' => 'TX', 'zip' => '76701']];
    $soldListing = ['listingGuid' => 'L1', 'status' => 'pending', 'property' => ['streetNumber' => '44', 'streetAddress' => 'Pecan Dr', 'state' => 'TX']];
    ssFake([ssSale()], [$active, $soldListing]);
    txKeyConnect($this->owner, 'skyslope', ['access_key' => 'good-key', 'access_secret' => 's']);

    expect(Deal::query()->orderBy('street')->get(['street', 'type', 'status', 'agent_user_id', 'price_cents', 'gci_cents', 'imported_from'])->toArray())->toBe([
        ['street' => '44 Pecan Dr', 'type' => 'listing', 'status' => 'under_contract', 'agent_user_id' => $this->agent->id, 'price_cents' => 45_000_000, 'gci_cents' => 1_350_000, 'imported_from' => 'SkySlope'],
        ['street' => '7 Elm St #B', 'type' => 'listing', 'status' => 'pre_contract', 'agent_user_id' => $this->agent->id, 'price_cents' => 29_900_000, 'gci_cents' => 0, 'imported_from' => 'SkySlope'],
    ]);
    $pecan = Deal::query()->where('street', '44 Pecan Dr')->sole();
    expect($pecan->closing_date?->toDateString())->toBe('2026-12-12')->and($pecan->contract_date?->toDateString())->toBe('2026-11-02')
        ->and(DB::connection(DbConnections::cell())->table('tx_parties')->where('deal_id', $pecan->id)->orderBy('role')->pluck('name', 'role')->all())
        ->toBe(['buyer' => 'Bo Ng', 'seller' => 'Sue Ray', 'title' => 'Tia Title']);

    // Then only changes, from the bulk export: the active listing sold.
    $c = Connection::query()->where('app_key', 'skyslope')->sole();
    Http::swap(new Factory);
    $sale = ['saleGuid' => 'S2', 'listingGuid' => 'L2', 'agentGuid' => 'U-JANE', 'status' => 'closed', 'salePrice' => '301000', 'actualClosingDate' => '2026-11-10T00:00:00',
        'property' => $active['property']];
    ssFake([], [], [$sale]);
    app(SyncRunner::class)->run($c->fresh());

    $elm = Deal::query()->where('street', '7 Elm St #B')->sole();
    expect(Deal::query()->count())->toBe(2)->and($elm->status)->toBe('closed')->and($elm->price_cents)->toBe(30_100_000)
        ->and($elm->closed_on?->toDateString())->toBe('2026-11-10')->and($elm->locked_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/api/files?') && str_contains(urldecode($r->url()), 'modifiedAfter=2026-11-15T10:00:00'));
});

/** @return array<string, mixed> */
function lwDeal(): array
{
    return ['id' => 'D-1', 'addressStreetNumber' => '15', 'addressStreetName' => 'Main St', 'addressCity' => 'Mobile', 'addressProvinceCode' => 'AL',
        'addressPostalCode' => '36602', 'mlsNumber' => 'M-9', 'closePrice' => 210000.0, 'offerDate' => '2026-10-01', 'firmDate' => '2026-10-08', 'closeDate' => '2026-11-01',
        'finalizeDate' => '2026-11-03', 'statusCode' => 'F', 'classification' => ['name' => 'Residential Sale - Listing', 'endCount' => 1],
        'tiers' => [['id' => 'T-1']], 'externalAgents' => [['firstName' => 'Oz', 'lastName' => 'Other', 'companyName' => 'Bay Realty', 'emailAddresses' => [['address' => 'oz@bay.test']]]]];
}

function lwFake(array $deals): void
{
    Http::fake([
        'authentication.api.lwolf.com/v1/login' => fn (HttpRequest $r) => $r['password'] === 'right' && $r['clientId'] === 'lw-client'
            ? Http::response(['token' => 'lw-jwt', 'expiresIn' => 86400]) : Http::response(['message' => 'no'], 401),
        'roster.api.lwolf.com/v1/members*' => Http::response([['id' => 'M-JANE', 'firstName' => 'Jane', 'primaryEmailAddress' => 'Jane@acme.test']]),
        'deals.api.lwolf.com/v1/deals/*/client-contacts' => Http::response([['type' => 'Seller', 'firstName' => 'Al', 'lastName' => 'Vend', 'phoneNumbers' => [['number' => '251-555-0100']]]]),
        'deals.api.lwolf.com/v1/deals/*/business-contacts' => Http::response([['contactType' => 'Lawyer', 'firstName' => 'Lu', 'lastName' => 'Law', 'companyName' => 'Law LLC']]),
        'deals.api.lwolf.com/v1/tiers/*/commissions' => Http::response([['agentId' => 'M-JANE', 'amount' => 6300]]),
        'deals.api.lwolf.com/v1/deals*' => Http::response($deals),
    ]);
}

it('brings Lone Wolf deals over with a read-only user, matching agents through the roster', function (): void {
    lwFake([lwDeal()]);
    txKeyConnect($this->owner, 'lone_wolf', ['email' => 'rightally@acme.test', 'password' => 'wrong'])->assertSessionHasErrors('password');
    txKeyConnect($this->owner, 'lone_wolf', ['email' => 'rightally@acme.test', 'password' => 'right'])->assertSessionHasNoErrors();

    $deal = Deal::query()->sole();
    expect($deal->only(['street', 'city', 'state_code', 'type', 'status', 'mls_number', 'price_cents', 'imported_from']))->toBe([
        'street' => '15 Main St', 'city' => 'Mobile', 'state_code' => 'AL', 'type' => 'listing', 'status' => 'closed', 'mls_number' => 'M-9', 'price_cents' => 21_000_000,
        'imported_from' => 'Lone Wolf'])
        ->and($deal->agent_user_id)->toBe($this->agent->id)
        ->and(DB::connection(DbConnections::cell())->table('tx_parties')->where('deal_id', $deal->id)->orderBy('role')->get(['role', 'name', 'company', 'phone'])->map(fn ($p) => (array) $p)->all())->toBe([
            ['role' => 'attorney', 'name' => 'Lu Law', 'company' => 'Law LLC', 'phone' => null],
            ['role' => 'co_op_agent', 'name' => 'Oz Other', 'company' => 'Bay Realty', 'phone' => null],
            ['role' => 'seller', 'name' => 'Al Vend', 'company' => null, 'phone' => '251-555-0100'],
        ]);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'deals.api.lwolf.com/v1/deals?') && $r->header('Authorization')[0] === 'Bearer lw-jwt');

    // Then only deals changed since the last pass.
    $c = Connection::query()->where('app_key', 'lone_wolf')->sole();
    Http::swap(new Factory);
    lwFake([]);
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains(urldecode($r->url()), '$filter=modifiedTimestamp gt 2026-11-15T10:00:00'));
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'roster.api'));
});

it('reads a Lone Wolf deal\'s state from its dates when the status code says nothing', function (): void {
    $base = ['id' => 'X', 'addressStreetName' => 'A St', 'addressProvinceCode' => 'MS'];
    expect(LoneWolf::deal($base, [], [], null)['status'])->toBe('pre_contract')
        ->and(LoneWolf::deal($base + ['offerDate' => '2026-11-01'], [], [], null)['status'])->toBe('under_contract')
        ->and(LoneWolf::deal($base + ['closeDate' => '2026-11-01', 'closePrice' => 100000], [], [], null)['status'])->toBe('closed')
        ->and(LoneWolf::deal($base + ['closeDate' => '2026-12-01', 'closePrice' => 100000, 'offerDate' => '2026-11-01'], [], [], null)['status'])->toBe('under_contract')
        ->and(LoneWolf::deal($base + ['statusCode' => 'Collapsed', 'offerDate' => '2026-11-01'], [], [], null)['status'])->toBe('fell_through')
        ->and(LoneWolf::deal($base + ['classification' => ['name' => 'Lease']], [], [], null)['type'])->toBe('lease')
        ->and(LoneWolf::deal($base + ['classification' => ['name' => 'Sale', 'endCount' => 2]], [], [], null)['type'])->toBe('dual');
});

it('files a SkySlope or Lone Wolf export into the deals its folders name, and lists the rest', function (): void {
    Storage::fake('local');
    ssFake([ssSale()], []);
    txKeyConnect($this->owner, 'skyslope', ['access_key' => 'good-key', 'access_secret' => 's']);
    $zipPath = tempnam(sys_get_temp_dir(), 'export');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('Export/Sales/44 Pecan Drive, Austin TX 78745/Contract.pdf', '%PDF-1.7 contract');
    $zip->addFromString('Export/Sales/44 Pecan Drive, Austin TX 78745/Contract copy.pdf', '%PDF-1.7 contract'); // same file twice: kept once
    $zip->addFromString('Export/Sales/44 Pecan Drive, Austin TX 78745/notes.txt', 'not a document');
    $zip->addFromString('Export/Sales/44 Pecan Drive, Austin TX 78745/fake.pdf', 'MZ not really a pdf');
    $zip->addFromString('Export/Sales/9 Unknown Rd/Lease.pdf', '%PDF-1.7 lease');
    $zip->close();

    $this->actingAs($this->agent)->get('/deals/bring-over-files')->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/bring-over-files', ['file' => new UploadedFile($zipPath, 'skyslope-export.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();

    $deal = Deal::query()->sole();
    expect(Document::query()->where('deal_id', $deal->id)->pluck('filename')->all())->toBe(['Contract.pdf'])
        ->and(Storage::disk('local')->allFiles('tx/'.$this->tenant->id.'/imports'))->toBe([]); // the ZIP is deleted once read
    $this->actingAs($this->owner)->get('/deals/bring-over-files')->assertInertia(fn ($page) => $page->component('deals/bring-over-files')
        ->where('imports.0.status', 'done')->where('imports.0.filed', 1)->where('imports.0.deals', 1)->where('imports.0.files', 4)
        ->where('imports.0.unmatched', ['Export/Sales/9 Unknown Rd']));
});

it('matches export folders to deal addresses without guessing', function (): void {
    $deals = [1 => BringOverFiles::normalise('44 Pecan Dr'), 2 => BringOverFiles::normalise('7 Elm Street'), 3 => BringOverFiles::normalise('7 Elm St')];
    expect(BringOverFiles::normalise('44 Pecan Drive, #4'))->toBe('44 pecan dr 4')
        ->and(BringOverFiles::match(['Sales', '44 PECAN DRIVE - Smith'], $deals))->toBe(1)
        ->and(BringOverFiles::match(['144 Pecan Dr'], $deals))->toBeNull() // not the same house number
        ->and(BringOverFiles::match(['7 Elm St'], $deals))->toBeNull() // two deals at that address: not guessed
        ->and(BringOverFiles::match(['Misc'], $deals))->toBeNull();
});
