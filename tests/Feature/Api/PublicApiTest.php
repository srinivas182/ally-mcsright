<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Modules\Api\Keys;
use Modules\Api\PartnerApps;
use Modules\Connect\Models\Connection;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Task;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    app()->instance('connect.dns', fn (string $host): array => $host === 'hooks.example.com' ? ['93.184.216.34'] : []);
});

function annKey(array $scopes): string
{
    return app(Keys::class)->create(test()->ann, 'Test', $scopes, null)['key'];
}

it('acts as the key\'s person, sees only their contacts, and pages with a cursor', function (): void {
    foreach (['Sam', 'Kim', 'Lee'] as $i => $n) {
        $this->travel(1)->minutes();
        app(ManageContacts::class)->create(['first_name' => $n, 'email' => strtolower($n).'@x.test'], $this->ann);
    }
    app(ManageContacts::class)->create(['first_name' => 'Bobs', 'email' => 'bobs@x.test'], $this->bob);
    $h = ['Authorization' => 'Bearer '.annKey(['contacts:read'])];

    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->getJson('/api/v1/me', $h)->assertOk()->assertJsonPath('data.user.name', 'Ann '.$this->ann->last_name)->assertHeader('RightAlly-Version', '2026-11-01');
    $page1 = $this->getJson('/api/v1/contacts?limit=2', $h)->assertOk();
    expect(array_column($page1->json('data'), 'first_name'))->toBe(['Lee', 'Kim'])->and($page1->json('next_cursor'))->not->toBeNull();
    $page2 = $this->getJson('/api/v1/contacts?limit=2&cursor='.$page1->json('next_cursor'), $h);
    expect(array_column($page2->json('data'), 'first_name'))->toBe(['Sam'])->and($page2->json('next_cursor'))->toBeNull();

    $bobs = Contact::query()->where('first_name', 'Bobs')->sole();
    $this->getJson("/api/v1/contacts/{$bobs->ulid}", $h)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->postJson('/api/v1/contacts', ['first_name' => 'New'], $h)->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
    $this->getJson('/api/v1/deals', $h)->assertForbidden();
});

it('writes contacts, notes and tasks as the person, once per idempotency key', function (): void {
    $h = ['Authorization' => 'Bearer '.annKey(['contacts:read', 'contacts:write', 'tasks:read']), 'Idempotency-Key' => 'abc-1'];
    $this->postJson('/api/v1/contacts', ['email' => 'bad'], $h)->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    $made = $this->postJson('/api/v1/contacts', ['first_name' => 'Pat', 'email' => 'pat@x.test', 'city' => 'Austin', 'state' => 'TX'], $h + ['Idempotency-Key' => 'abc-2'])->assertCreated();
    $this->postJson('/api/v1/contacts', ['first_name' => 'Pat', 'email' => 'pat@x.test'], $h + ['Idempotency-Key' => 'abc-2'])->assertCreated()->assertHeader('Idempotent-Replay', 'true');
    expect(Contact::query()->where('first_name', 'Pat')->count())->toBe(1)->and($made->json('data.city'))->toBe('Austin');

    $id = $made->json('data.id');
    $this->patchJson("/api/v1/contacts/{$id}", ['lifecycle' => 'sphere'], $h)->assertOk()->assertJsonPath('data.lifecycle', 'sphere');
    $this->postJson("/api/v1/contacts/{$id}/notes", ['body' => 'Met at the open house.'], $h + ['Idempotency-Key' => 'n-1'])->assertCreated();
    $this->postJson("/api/v1/contacts/{$id}/tasks", ['title' => 'Call Pat', 'due_at' => '2026-06-02 10:00', 'type' => 'call'], $h + ['Idempotency-Key' => 't-1'])->assertCreated();
    $this->getJson('/api/v1/tasks', $h)->assertOk()->assertJsonPath('data.0.title', 'Call Pat');
    expect(Task::query()->sole()->assigned_to)->toBe($this->ann->id)
        ->and(DB::table('audit_events')->where('action', 'api.contact.created')->exists())->toBeTrue();
});

it('shows deals without commission, splits or bank details, and never offers deletes', function (): void {
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    auth()->logout();
    $h = ['Authorization' => 'Bearer '.annKey(['deals:read', 'contacts:read'])];
    $deal = $this->getJson('/api/v1/deals', $h)->assertOk()->json('data.0');
    expect($deal['street'])->toBe('12 Oak St')->and($deal['price'])->toEqual(480000)->and(array_keys($deal))->not->toContain('gci_cents')->not->toContain('pay_overrides');
    $bobKey = app(Keys::class)->create($this->bob, 'B', ['deals:read'], null)['key'];
    $this->getJson('/api/v1/deals/'.Deal::query()->sole()->ulid, ['Authorization' => 'Bearer '.$bobKey])->assertNotFound();
    $this->deleteJson('/api/v1/contacts/'.Str::ulid(), [], $h)->assertStatus(405);
});

it('limits keys: other brokerages, revoked, expired, and 120 calls a minute', function (): void {
    $key = annKey(['contacts:read']);
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->getJson('http://other.rightally.app/api/v1/me', ['Authorization' => 'Bearer '.$key])->assertUnauthorized();
    app(TenantContext::class)->set($this->tenant);

    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$key])->assertOk();
    }
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$key])->assertStatus(429)->assertHeader('Retry-After');

    $ulid = DB::connection(Connections::cell())->table('api_keys')->value('ulid');
    app(Keys::class)->revoke((string) $ulid, $this->ann);
    $this->travel(2)->minutes();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$key])->assertUnauthorized();

    $expiring = app(Keys::class)->create($this->ann, 'Short', ['contacts:read'], '2026-06-03')['key'];
    $this->travelTo(CarbonImmutable::parse('2026-06-05'));
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$expiring])->assertUnauthorized();
});

it('subscribes webhooks for owners and staff only, signed and removable by the same key', function (): void {
    $this->expect(fn () => app(Keys::class)->create($this->ann, 'Hooks', ['webhooks:manage'], null))->toThrow(ValidationException::class);
    $key = app(Keys::class)->create($this->owner, 'Hooks', ['webhooks:manage'], null)['key'];
    $h = ['Authorization' => 'Bearer '.$key];
    $this->postJson('/api/v1/webhooks', ['url' => 'https://evil.local/x', 'events' => ['lead.created']], $h)->assertStatus(422);
    $made = $this->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com/rightally', 'events' => ['lead.created', 'task.completed']], $h)->assertCreated();
    expect($made->json('data.secret'))->toStartWith('whsec_')->and(Connection::query()->where('app_key', 'webhooks')->count())->toBe(1);
    $this->getJson('/api/v1/webhooks', $h)->assertOk()->assertJsonPath('data.0.events', ['lead.created', 'task.completed']);
    $this->deleteJson('/api/v1/webhooks/'.$made->json('data.id'), [], $h)->assertNoContent();
});

it('lets partner apps in only where the owner allowed them, with API scopes', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    $hq = User::factory()->hqStaff()->make(['id' => 999999]);
    $made = PartnerApps::register('Ledgerly', 'Ledgerly Inc.', 'https://ledgerly.example', 'Bookkeeping for brokerages.', ['contacts:read'],
        ['https://ledgerly.example/callback'], true, $hq);
    $client = Client::query()->findOrFail($made['client_id']);
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->ann);

    Passport::actingAs($this->ann, ['api:contacts:read'], 'api', $client);
    $this->getJson('/api/v1/contacts')->assertUnauthorized(); // not allowed by the brokerage yet

    app(PartnerApps::class)->setAllowed($made['client_id'], true, $this->owner);
    Passport::actingAs($this->ann, ['api:contacts:read'], 'api', $client);
    $this->getJson('/api/v1/contacts')->assertOk()->assertJsonPath('data.0.first_name', 'Sam');
    Passport::actingAs($this->ann, ['mcp:use'], 'api', $client);
    $this->getJson('/api/v1/contacts')->assertUnauthorized(); // an AI assistant token is not an API token

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/api')
        ->assertInertia(fn ($p) => $p->component('api/developer')->where('apps.0.name', 'Ledgerly')->where('apps.0.allowed', true));
});

it('publishes an OpenAPI description', function (): void {
    $doc = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
    expect($doc['openapi'])->toBe('3.1.0')->and(array_keys($doc['paths']))->toContain('/contacts', '/deals/{id}', '/webhooks')
        ->and(json_encode($doc))->not->toContain('"delete":{"summary":"Delete');
});
