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
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Connections;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections as DbConnections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Transactions\Connect\DealImporter;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

/* Sprint C5a-1: dotloop — switch in a weekend. Every loop comes over as a deal with its people, dates and files, then
   changes arrive within seconds through signed webhooks. */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('dotloop'), true, ['client_id' => 'dl-id', 'client_secret' => 'dl-secret'], null, User::factory()->make(['id' => 999999]));
});

/** @return array<string, mixed> */
function dlSold(): array
{
    return ['id' => 501, 'transactionType' => 'LISTING_FOR_SALE', 'status' => 'SOLD', 'details' => [
        'Property Address' => ['Street Number' => '12', 'Street Name' => 'Oak St', 'Unit Number' => '4', 'City' => 'Austin', 'State/Prov' => 'TX', 'Zip/Postal Code' => '78701', 'MLS Number' => 'A123'],
        'Financials' => ['Purchase/Sale Price' => '$425,000.00', 'Sale Commission Total' => '12,750'],
        'Contract Dates' => ['Contract Agreement Date' => '09/01/2026', 'Closing Date' => '10/15/2026', 'Inspection Date' => '09/10/2026']]];
}

/** @return array<string, mixed> */
function dlPending(string $closing = '12/20/2026'): array
{
    return ['id' => 502, 'transactionType' => 'PURCHASE_OFFER', 'status' => 'UNDER_CONTRACT', 'details' => [
        'Property Address' => ['Street Number' => '9', 'Street Name' => 'Elm Ave', 'City' => 'Waco', 'State/Prov' => 'TX', 'Zip/Postal Code' => '76701'],
        'Financials' => ['Purchase/Sale Price' => '310000'],
        'Contract Dates' => ['Contract Agreement Date' => '11/01/2026', 'Closing Date' => $closing, 'Option Period Ends' => '11/11/2026']]];
}

/**
 * dotloop's API as a fake: $loops are profile 77's loops; files are one PDF and one Word file per loop.
 *
 * @param  list<array<string, mixed>>  $loops
 */
function dlFake(array $loops): void
{
    Http::fake([
        'auth.dotloop.com/oauth/token' => Http::response(['access_token' => 'dl-at', 'refresh_token' => 'dl-rt', 'expires_in' => 43199]),
        'auth.dotloop.com/oauth/token/revoke*' => Http::response([]),
        'api-gateway.dotloop.com/public/v2/account' => Http::response(['data' => ['id' => 1, 'email' => 'broker@dotloop.test']]),
        'api-gateway.dotloop.com/public/v2/profile' => Http::response(['data' => [['id' => 77, 'name' => 'Acme Realty', 'type' => 'OFFICE']]]),
        'api-gateway.dotloop.com/public/v2/profile/77/loop/*/participant' => fn (HttpRequest $r) => Http::response(['data' => str_contains($r->url(), '/501/') ? [
            ['id' => 1, 'fullName' => 'Jane Agent', 'email' => 'jane@acme.test', 'role' => 'LISTING_AGENT'],
            ['id' => 2, 'fullName' => 'Sam Seller', 'email' => 'sam@x.test', 'role' => 'SELLER', 'Phone' => '512-555-0101'],
            ['id' => 3, 'fullName' => 'Tia Title', 'email' => 'tia@title.test', 'role' => 'ESCROW_TITLE_REP', 'Company Name' => 'Lone Star Title'],
            ['id' => 4, 'fullName' => 'Office Admin', 'email' => 'admin@acme.test', 'role' => 'ADMIN'],
        ] : [['id' => 5, 'fullName' => 'Bea Buyer', 'email' => 'bea@x.test', 'role' => 'BUYER']]]),
        'api-gateway.dotloop.com/public/v2/profile/77/loop/*/folder/*/document/*' => fn (HttpRequest $r) => preg_match('#/document/900[24]$#', $r->url()) === 1
            ? Http::response('PK-not-a-pdf', 200, ['Content-Type' => 'application/octet-stream'])
            : Http::response('%PDF-1.7 file '.basename($r->url()), 200, ['Content-Type' => 'application/pdf']),
        'api-gateway.dotloop.com/public/v2/profile/77/loop/*/folder*' => fn (HttpRequest $r) => Http::response(['data' => [['id' => 31, 'name' => 'Contracts', 'documents' => [
            ['id' => str_contains($r->url(), '/501/') ? 9001 : 9003, 'name' => 'Purchase Agreement.pdf'], ['id' => str_contains($r->url(), '/501/') ? 9002 : 9004, 'name' => 'notes.docx']]]]]),
        'api-gateway.dotloop.com/public/v2/profile/77/loop/502' => Http::response(['data' => dlPending('12/28/2026')]),
        'api-gateway.dotloop.com/public/v2/profile/77/loop*' => Http::response(['data' => $loops, 'meta' => ['total' => count($loops)]]),
        'api-gateway.dotloop.com/public/v2/subscription*' => fn (HttpRequest $r) => Http::response($r->method() === 'POST' ? ['data' => ['id' => 'sub-1']] : []),
    ]);
}

function dlConnect(User $as): Connection
{
    $start = test()->actingAs($as)->post('/apps/dotloop/oauth', [], ['X-Inertia' => 'true']);
    $to = (string) $start->headers->get('X-Inertia-Location');
    expect($to)->toStartWith('https://auth.dotloop.com/oauth/authorize?');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('dl-id');
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', 'dotloop')->sole();
}

function dlHook(Connection $c, array $payload, ?string $signature = null, ?int $at = null): TestResponse
{
    $raw = (string) json_encode($payload);
    $ts = (string) ($at ?? CarbonImmutable::now()->getTimestamp());
    $sig = $signature ?? hash_hmac('sha1', $ts.'.'.$raw, hash_hmac('sha256', 'connect:dotloop:webhooks', (string) config('app.key')));

    return test()->call('POST', 'https://auth.rightally.app/connect/hooks/dotloop/'.test()->tenant->ulid.'/'.$c->ulid, [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DOTLOOP_WEBHOOK_SIGNATURE' => $sig, 'HTTP_X_DOTLOOP_WEBHOOK_TIMESTAMP' => $ts], $raw);
}

it('brings every loop over as a deal with its agent, parties, dates and PDF files, then listens for changes', function (): void {
    dlFake([dlSold(), dlPending(), ['id' => 503, 'transactionType' => 'OTHER', 'status' => 'NEW']]);
    $c = dlConnect($this->owner);

    $sold = Deal::query()->where('street', '12 Oak St #4')->sole();
    expect($sold->only(['type', 'status', 'city', 'state_code', 'postal_code', 'mls_number', 'price_cents', 'gci_cents', 'imported_from']))->toBe([
        'type' => 'listing', 'status' => 'closed', 'city' => 'Austin', 'state_code' => 'TX', 'postal_code' => '78701', 'mls_number' => 'A123',
        'price_cents' => 42_500_000, 'gci_cents' => 1_275_000, 'imported_from' => 'dotloop'])
        ->and($sold->agent_user_id)->toBe($this->agent->id)
        ->and($sold->closing_date?->toDateString())->toBe('2026-10-15')->and($sold->closed_on?->toDateString())->toBe('2026-10-15')
        ->and($sold->locked_at)->not->toBeNull()->and($sold->calculation_id)->toBeNull();

    $db = DB::connection(DbConnections::cell());
    expect($db->table('tx_parties')->where('deal_id', $sold->id)->orderBy('role')->get(['role', 'name', 'company', 'phone'])->map(fn ($p) => (array) $p)->all())->toBe([
        ['role' => 'seller', 'name' => 'Sam Seller', 'company' => null, 'phone' => '512-555-0101'],
        ['role' => 'title', 'name' => 'Tia Title', 'company' => 'Lone Star Title', 'phone' => null],
    ])->and($db->table('tx_dates')->where('deal_id', $sold->id)->pluck('due_on', 'label')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['Inspection Date' => '2026-09-10']);

    // Only real PDFs are kept, in the deal's unsorted documents, and never read by AI on their own.
    expect(Document::query()->where('deal_id', $sold->id)->get(['filename', 'mime', 'via', 'item_id'])->toArray())->toBe([
        ['filename' => 'Purchase Agreement.pdf', 'mime' => 'application/pdf', 'via' => 'import', 'item_id' => null]])
        ->and($db->table('tx_document_readings')->count())->toBe(0);

    $open = Deal::query()->where('street', '9 Elm Ave')->sole();
    expect($open->only(['type', 'status']))->toBe(['type' => 'buyer', 'status' => 'under_contract'])
        ->and($open->agent_user_id)->toBe($this->owner->id) // no agent on the loop: the person who connected, until it moves
        ->and($open->locked_at)->toBeNull()
        ->and(Deal::query()->count())->toBe(2); // the "other" loop is not a property deal

    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/subscription')
        && $r['targetType'] === 'PROFILE' && $r['targetId'] === 77 && $r['eventTypes'] === ['LOOP_CREATED', 'LOOP_UPDATED', 'LOOP_MERGED']
        && $r['url'] === 'https://auth.rightally.app/connect/hooks/dotloop/'.$this->tenant->ulid.'/'.$c->ulid && $r['externalId'] === $c->ulid);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/document/9001') && $r->header('Accept')[0] === 'application/pdf'
        && $r->header('Authorization')[0] === 'Bearer dl-at');
    expect($c->fresh()?->settings['subscriptions'])->toBe(['sub-1']);
});

it('is safe to repeat, reads only what changed, and never touches a deal since closed in RightAlly', function (): void {
    dlFake([dlSold(), dlPending()]);
    $c = dlConnect($this->owner);
    $open = Deal::query()->where('street', '9 Elm Ave')->sole();
    $open->forceFill(['status' => 'closed', 'imported_from' => null])->save(); // closed and paid here since

    Http::swap(new Factory);
    $changed = dlPending('01/05/2027');
    $changed['details']['Financials']['Purchase/Sale Price'] = '999000';
    dlFake([dlSold(), $changed]);
    app(SyncRunner::class)->run($c->fresh());

    expect(Deal::query()->count())->toBe(2)->and(Document::query()->count())->toBe(2)
        ->and($open->fresh()?->price_cents)->toBe(31_000_000);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains(urldecode($r->url()), 'filter=updated_min=2026-11-15'));
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/document/'));
    Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/subscription'));

    // Once a week everything is read again (deals move to agents who have joined since).
    $this->travel(8)->days();
    app(SyncRunner::class)->run($c->fresh()); // the pass that notices the week is up
    Http::swap(new Factory);
    dlFake([dlSold(), $changed]);
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/profile/77/loop?') && ! str_contains($r->url(), 'filter='));
});

it('updates a deal within seconds from a signed webhook, and refuses unsigned or stale ones', function (): void {
    dlFake([dlPending()]);
    $c = dlConnect($this->owner);
    $payload = ['eventId' => 'e1', 'eventType' => 'LOOP_UPDATED', 'profileId' => '77', 'subscriptionId' => 'sub-1', 'event' => ['id' => '502']];

    dlHook($c, $payload, signature: 'bad')->assertStatus(401);
    dlHook($c, $payload, at: CarbonImmutable::now()->subDays(2)->getTimestamp())->assertStatus(401);
    expect(Deal::query()->sole()->closing_date?->toDateString())->toBe('2026-12-20');

    dlHook($c, $payload)->assertNoContent();
    expect(Deal::query()->sole()->closing_date?->toDateString())->toBe('2026-12-28')
        ->and(SyncRun::query()->where('connection_id', $c->id)->latest('id')->first()?->note)->toBe('Live update: LOOP_UPDATED');
});

it('gives an agent everything in their own dotloop, and links buyers to existing contacts', function (): void {
    $bea = app(ManageContacts::class)->create(['first_name' => 'Bea', 'last_name' => 'Buyer', 'email' => 'bea@x.test', 'source' => 'manual'], $this->agent);
    dlFake([dlPending()]);
    $this->actingAs($this->agent)->get('/apps/dotloop')->assertOk();
    dlConnect($this->agent);

    $deal = Deal::query()->sole();
    expect($deal->agent_user_id)->toBe($this->agent->id)->and($deal->contact_id)->toBe($bea->id)
        ->and(DB::connection(DbConnections::cell())->table('tx_parties')->where('deal_id', $deal->id)->value('contact_id'))->toBe($bea->id);

    $this->actingAs($this->agent)->get('/deals/'.$deal->ulid)->assertInertia(fn (Assert $page) => $page->component('deals/show')->where('deal.imported_from', 'dotloop'));
});

it('switches off dotloop\'s webhooks and the sign-in when disconnected', function (): void {
    dlFake([]);
    $c = dlConnect($this->owner);
    app(Connections::class)->disconnect($c, $this->owner);

    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscription/sub-1'));
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'oauth/token/revoke') && $r->header('Authorization')[0] === 'Basic '.base64_encode('dl-id:dl-secret'));
});

it('reads the date and money formats transaction apps use', function (): void {
    expect(DealImporter::date('03/01/2026'))->toBe('2026-03-01')->and(DealImporter::date('2026-03-01'))->toBe('2026-03-01')
        ->and(DealImporter::date('3/1/2026'))->toBe('2026-03-01')->and(DealImporter::date('2026-03-01T15:00:00Z'))->toBe('2026-03-01')
        ->and(DealImporter::date('soon'))->toBeNull()->and(DealImporter::date(''))->toBeNull()
        ->and(DealImporter::cents('$425,000.00'))->toBe(42_500_000)->and(DealImporter::cents(1200.5))->toBe(120_050)->and(DealImporter::cents('n/a'))->toBe(0);
});
