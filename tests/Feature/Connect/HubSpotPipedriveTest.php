<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/* Sprint C2d-1: HubSpot and Pipedrive — paste a token; contacts, owners and notes come over, then only changes. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    foreach (['hubspot', 'pipedrive'] as $key) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, User::factory()->make(['id' => 999999]));
    }
});

function c2dConnect(User $as, string $app, string $key = 'tok-1'): TestResponse
{
    return test()->actingAs($as)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', ['api_key' => $key]);
}

it('brings HubSpot contacts over with owner, lead status, lifecycle and notes, then searches only changes', function (): void {
    Http::fake([
        'api.hubapi.com/crm/v3/owners*' => Http::response(['results' => [['id' => '55', 'email' => 'jane@acme.test']]]),
        'api.hubapi.com/crm/v3/objects/contacts?*' => Http::response(['results' => [['id' => '101', 'properties' => ['firstname' => 'Ana', 'lastname' => 'Diaz',
            'email' => 'ana@x.test', 'mobilephone' => '512-555-0101', 'hs_lead_status' => 'IN_PROGRESS', 'lifecyclestage' => 'lead', 'hubspot_owner_id' => '55',
            'hs_analytics_source' => 'ORGANIC_SEARCH']]]]),
        'api.hubapi.com/crm/v3/objects/notes?*' => Http::response(['results' => [['id' => '900', 'properties' => ['hs_note_body' => '<p>Loves the lake</p>',
            'hs_timestamp' => '2026-02-01T10:00:00Z'], 'associations' => ['contacts' => ['results' => [['id' => '101']]]]]]]),
    ]);
    c2dConnect($this->owner, 'hubspot')->assertSessionHasNoErrors();

    $ana = Contact::query()->sole();
    expect($ana->owner_user_id)->toBe($this->agent->id)->and($ana->source_detail)->toBe('HubSpot: ORGANIC_SEARCH')
        ->and($ana->tags()->pluck('name')->sort()->values()->all())->toBe(['HubSpot lead status: IN_PROGRESS', 'HubSpot lifecycle: lead'])
        ->and(Activity::query()->where('type', 'note')->pluck('body')->all())->toBe(['From HubSpot: Loves the lake']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'objects/contacts') && $r->header('Authorization')[0] === 'Bearer tok-1');

    $c = Connection::query()->sole();
    Http::swap(new Factory);
    Http::fake(['api.hubapi.com/crm/v3/objects/*/search' => Http::response(['results' => []])]);
    app(SyncRunner::class)->run($c->fresh()); // marks the first pass checked
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'objects/contacts/search') && $r['filterGroups'][0]['filters'][0]['propertyName'] === 'lastmodifieddate');
});

it('refuses a HubSpot token that does not work', function (): void {
    Http::fake(['api.hubapi.com/*' => Http::response([], 401)]);
    c2dConnect($this->agent, 'hubspot', 'bad')->assertSessionHasErrors('api_key');
});

it('brings Pipedrive people over with owner and notes, then people updated since the last run', function (): void {
    Http::fake([
        'api.pipedrive.com/api/v1/users/me' => Http::response(['data' => ['company_name' => 'Acme Realty']]),
        'api.pipedrive.com/api/v1/users' => Http::response(['data' => [['id' => 3, 'email' => 'jane@acme.test']]]),
        'api.pipedrive.com/api/v2/persons*' => Http::response(['data' => [['id' => 7, 'name' => 'Sam Lee', 'emails' => [['value' => 'sam@x.test', 'primary' => true]],
            'phones' => [['value' => '512-555-0199', 'primary' => true]], 'owner_id' => 3]], 'additional_data' => ['next_cursor' => null]]),
        'api.pipedrive.com/api/v1/notes*' => Http::response(['data' => [['id' => 70, 'person_id' => 7, 'content' => 'Wants a duplex', 'add_time' => '2026-03-01 09:00:00']],
            'additional_data' => ['pagination' => ['more_items_in_collection' => false]]]),
    ]);
    c2dConnect($this->owner, 'pipedrive')->assertSessionHasNoErrors();

    $sam = Contact::query()->sole();
    expect(Connection::query()->sole()->label)->toBe('Pipedrive (Acme Realty)')
        ->and([$sam->first_name, $sam->last_name, $sam->owner_user_id])->toBe(['Sam', 'Lee', $this->agent->id])
        ->and(Activity::query()->where('type', 'note')->pluck('body')->all())->toBe(['From Pipedrive: Wants a duplex']);
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('x-api-token', 'tok-1'));

    Http::swap(new Factory);
    Http::fake(['api.pipedrive.com/*' => Http::response(['data' => [], 'additional_data' => []])]);
    app(SyncRunner::class)->run(Connection::query()->sole());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'api/v2/persons') && str_contains($r->url(), 'updated_since='));
    expect(Contact::query()->count())->toBe(1);
});

it('marks which remaining CRMs need partner access or come next', function (): void {
    foreach (['cinc', 'brivity', 'liondesk', 'rechat', 'cloze', 'salesforce', 'propertybase'] as $key) {
        expect(app(AppCatalog::class)->find($key)?->setup['partner'])->toBeString();
    }
});
