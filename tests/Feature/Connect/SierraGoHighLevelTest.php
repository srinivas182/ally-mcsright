<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/* Sprint C2c: Sierra Interactive and GoHighLevel — paste your key; everything comes over, then only what changed.
   Real Geeks, BoomTown, Top Producer and Wise Agent show what partner access HQ must apply for. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    foreach (['sierra', 'gohighlevel'] as $key) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, User::factory()->make(['id' => 999999]));
    }
});

function c2cConnect(User $as, string $app, array $in): TestResponse
{
    return test()->actingAs($as)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', $in);
}

it('brings Sierra leads over with agent, tags, status and notes, then only changed leads', function (): void {
    Http::fake([
        'api.sierrainteractivedev.com/leads/find*' => Http::response(['success' => true, 'data' => ['totalPages' => 1, 'leads' => [
            ['id' => 501, 'firstName' => 'Ana', 'lastName' => 'Diaz', 'email' => 'ana@x.test', 'phone' => '512-555-0101', 'leadStatus' => 'Active', 'source' => 'Zillow',
                'tags' => ['Buyer'], 'assignedTo' => ['agentUserId' => 9, 'agentUserEmail' => 'jane@acme.test']],
        ]]]),
        'api.sierrainteractivedev.com/notes/501*' => Http::response(['success' => true, 'data' => [
            ['id' => 1, 'leadId' => 501, 'contents' => 'Wants a pool', 'dateCreated' => '2026-05-01T10:00:00Z', 'isSystemItem' => false],
            ['id' => 2, 'leadId' => 501, 'contents' => 'Lead viewed a listing', 'isSystemItem' => true],
        ]]),
    ]);
    c2cConnect($this->owner, 'sierra', ['api_key' => 'sk-1'])->assertSessionHasNoErrors();

    $ana = Contact::query()->sole();
    expect($ana->owner_user_id)->toBe($this->agent->id)->and($ana->source_detail)->toBe('Sierra: Zillow')
        ->and($ana->tags()->pluck('name')->sort()->values()->all())->toBe(['Buyer', 'Sierra status: Active'])
        ->and(Activity::query()->where('type', 'note')->pluck('body')->all())->toBe(['From Sierra: Wants a pool']);
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('Sierra-ApiKey', 'sk-1') && str_contains($r->url(), 'includeTags=true'));

    Http::swap(new Factory);
    Http::fake(['api.sierrainteractivedev.com/*' => Http::response(['success' => true, 'data' => ['totalPages' => 0, 'leads' => []]])]);
    app(SyncRunner::class)->run(Connection::query()->sole());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'leadUpdateDateFrom='));
});

it('asks for both the GoHighLevel token and Location ID, and checks them', function (): void {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'Invalid JWT'], 401)]);
    c2cConnect($this->agent, 'gohighlevel', ['api_key' => 'pit-1', 'location_id' => 'x'])->assertSessionHasErrors('location_id');
    c2cConnect($this->agent, 'gohighlevel', ['api_key' => 'pit-1', 'location_id' => 've9EPM428h8vShlRW1KT'])->assertSessionHasErrors('api_key');
    expect(Connection::query()->count())->toBe(0);
});

it('brings GoHighLevel contacts over page by page with notes, then only recent changes', function (): void {
    $page = fn (array $ids) => array_map(fn (int $i): array => ['id' => 'c'.$i, 'firstName' => 'P'.$i, 'email' => 'p'.$i.'@x.test', 'tags' => ['ghl'], 'source' => 'Facebook',
        'assignedTo' => 'u1', 'dateUpdated' => '2026-01-01T00:00:00Z', 'searchAfter' => [$i, 'c'.$i]], $ids);
    Http::fake([
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => [['id' => 'u1', 'email' => 'jane@acme.test']]]),
        'services.leadconnectorhq.com/contacts/search' => function (HttpRequest $r) use ($page) {
            return isset($r->data()['searchAfter']) ? Http::response(['contacts' => $page([101]), 'total' => 101]) : Http::response(['contacts' => $page(range(1, 100)), 'total' => 101]);
        },
        'services.leadconnectorhq.com/contacts/c1/notes' => Http::response(['notes' => [['id' => 'n1', 'body' => '<p>Met at open house</p>', 'dateAdded' => '2026-01-02T00:00:00Z']]]),
        'services.leadconnectorhq.com/contacts/*/notes' => Http::response(['notes' => []]),
    ]);
    c2cConnect($this->owner, 'gohighlevel', ['api_key' => 'pit-1', 'location_id' => 've9EPM428h8vShlRW1KT'])->assertSessionHasNoErrors();

    expect(Contact::query()->count())->toBe(101)
        ->and(Contact::query()->where('primary_email', 'p1@x.test')->sole()->owner_user_id)->toBe($this->agent->id)
        ->and(Activity::query()->where('type', 'note')->pluck('body')->all())->toBe(['From GoHighLevel: Met at open house']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'contacts/search') && $r->hasHeader('Version', 'v3') && $r['locationId'] === 've9EPM428h8vShlRW1KT'
        && ($r->data()['searchAfter'] ?? null) === [100, 'c100']);

    Http::swap(new Factory);
    Http::fake(['services.leadconnectorhq.com/contacts/search' => Http::response(['contacts' => $page([1])]), 'services.leadconnectorhq.com/*' => Http::response(['notes' => []])]);
    app(SyncRunner::class)->run(Connection::query()->sole());
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'contacts/search') && $r['sort'] === [['field' => 'dateUpdated', 'direction' => 'desc']]);
    expect(Contact::query()->count())->toBe(101);
});

it('shows HQ which roadmap CRMs need partner access', function (): void {
    expect(app(AppCatalog::class)->find('wise_agent')?->setup['partner'])->toContain('only to partner integrations')
        ->and(app(AppCatalog::class)->find('top_producer')?->setup['partner'])->toContain('no public API');
    $this->actingAs($this->agent)->get('/apps/gohighlevel')->assertInertia(fn (Assert $p) => $p->where('app.guide.steps.0', 'In your GoHighLevel sub-account, open Settings → Private Integrations and create one.'));
});
