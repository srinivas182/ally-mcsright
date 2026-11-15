<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Jobs\DeliverWebhook;
use Modules\Connect\Jobs\SyncConnection;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Connect\Notifications\ConnectionNeedsAttention;
use Modules\Connect\SyncRunner;
use Modules\Connect\Webhooks\Signature;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Support\Connections;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;

/* Sprint C1a: Connect — the Apps page, the connection engine, outgoing webhooks, app requests. */

final class FakeCrmConnector implements Connector
{
    /** @var list<string> */
    public static array $script = [];

    public function verify(array $credentials, array $settings): string
    {
        if (($credentials['api_key'] ?? '') !== 'good-key') {
            throw ValidationException::withMessages(['api_key' => 'That key was not accepted.']);
        }

        return 'acme@fakecrm.test';
    }

    public function sync(ConnectionData $connection): array
    {
        $next = array_shift(self::$script) ?? 'ok';
        if ($next === 'reconnect') {
            throw new NeedsReconnect('The Fake CRM sign-in was revoked.');
        }
        if ($next === 'fail') {
            throw new RuntimeException('Fake CRM is down (503).');
        }

        return ['created' => 3, 'updated' => 1, 'skipped' => 0, 'cursor' => 'page-2', 'note' => null];
    }
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Ann']);
    app()->instance('connect.dns', fn (string $host): array => match ($host) {
        'hooks.example.com' => ['93.184.216.34'],
        'internal.example.com' => ['10.0.0.5'],
        default => [],
    });
    FakeCrmConnector::$script = [];
    app(AppCatalog::class)->add(new App('fake_crm', 'Fake CRM', 'crm', 'A CRM for tests.', scope: 'both', auth: 'api_key', syncs: ['Contacts'],
        fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'text', 'secret' => true]], driver: FakeCrmConnector::class));
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('fake_crm'), true, [], null, $this->owner);
});

function connectAs(User $user, string $app, array $input): TestResponse
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', $input);
}

function hookEndpoint(array $events = ['lead.created']): Connection
{
    connectAs(test()->owner, 'webhooks', ['url' => 'https://hooks.example.com/rightally', 'events' => $events])->assertSessionHasNoErrors();

    return Connection::query()->where('app_key', 'webhooks')->latest('id')->firstOrFail();
}

it('lists the apps by category, with what each person may connect', function (): void {
    $this->actingAs($this->owner)->get('/apps')->assertInertia(fn (Assert $p) => $p->component('apps/index')
        ->where('apps', fn ($apps): bool => collect($apps)->firstWhere('key', 'webhooks')['can_connect'] === true
            && collect($apps)->firstWhere('key', 'follow_up_boss')['status'] === 'soon'
            && collect($apps)->pluck('category')->unique()->count() >= 8));
    $this->actingAs($this->agent)->get('/apps')->assertInertia(fn (Assert $p) => $p
        ->where('apps', fn ($apps): bool => collect($apps)->firstWhere('key', 'webhooks')['can_connect'] === false
            && collect($apps)->firstWhere('key', 'fake_crm')['can_connect'] === true));
    connectAs($this->agent, 'webhooks', ['url' => 'https://hooks.example.com/x', 'events' => ['lead.created']])->assertSessionHasErrors('app');
});

it('only accepts public https webhook addresses', function (string $url, string $error): void {
    connectAs($this->owner, 'webhooks', ['url' => $url, 'events' => ['lead.created']])->assertSessionHasErrors(['url' => $error]);
})->with([
    'plain http' => ['http://hooks.example.com/x', 'Use an https:// address.'],
    'an IP address' => ['https://127.0.0.1/x', 'Use a public web address, not an IP address or an internal name.'],
    'localhost' => ['https://localhost/x', 'Use a public web address, not an IP address or an internal name.'],
    'resolves to a private network' => ['https://internal.example.com/x', 'That address points to a private network.'],
    'unknown host' => ['https://nowhere.example.com/x', 'That address could not be found.'],
    'a password in it' => ['https://u:p@hooks.example.com/x', 'Remove the user name and password from the address.'],
    'an odd port' => ['https://hooks.example.com:6379/x', 'Use the standard HTTPS port.'],
]);

it('sends signed lead events to webhooks, shows the secret once, and retries failures', function (): void {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('ok', 200)->push('nope', 500)]);
    $endpoint = hookEndpoint(['lead.created']);
    $secret = (string) session('connect.secret.secret');
    expect($secret)->toStartWith('whsec_')->and($endpoint->credentials['secret'])->toBe($secret)
        ->and($endpoint->toArray())->not->toHaveKey('credentials');
    $this->actingAs($this->owner)->get('/apps/webhooks')->assertInertia(fn (Assert $p) => $p->where('connections.0.url', 'https://hooks.example.com/rightally')
        ->where('connections.0.events', ['lead.created'])->missing('connections.0.credentials'));

    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->agent->id]], $this->owner);
    $key = (string) app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner)['key'];
    $this->postJson('/api/leads', ['name' => 'Maria Lopez', 'email' => 'maria@example.test', 'intent' => 'buy', 'zip' => '78704'], ['Authorization' => 'Bearer '.$key])
        ->assertSuccessful();

    Http::assertSent(function (HttpRequest $r) use ($secret): bool {
        $body = json_decode($r->body(), true);

        return $r->url() === 'https://hooks.example.com/rightally' && $r->header('X-RightAlly-Event')[0] === 'lead.created'
            && Signature::verify($secret, $r->body(), $r->header('X-RightAlly-Signature')[0], CarbonImmutable::now()->getTimestamp())
            && $body['data']['contact']['email'] === 'maria@example.test' && $body['data']['agent']['name'] === 'Ann '.$this->agent->last_name
            && $body['data']['lead']['source'] === 'Zapier';
    });
    $delivered = WebhookDelivery::query()->where('event', 'lead.created')->sole();
    expect($delivered->status)->toBe('delivered')->and($delivered->response_code)->toBe(200);
    expect(WebhookDelivery::query()->where('event', 'contact.created')->count())->toBe(0); // not subscribed

    // A failing receiver: tried again later (the queue retries), then marked failed after the last try.
    $this->actingAs($this->owner)->post('/connections/'.$endpoint->ulid.'/ping')->assertSessionHasNoErrors();
    $ping = WebhookDelivery::query()->where('event', 'ping')->sole();
    expect($ping->status)->toBe('pending')->and($ping->attempts)->toBe(1)->and($ping->last_error)->toBe('The endpoint answered 500.');
    Http::fake(['hooks.example.com/*' => Http::response('down', 503)]);
    foreach (range(2, 6) as $try) {
        (new DeliverWebhook($ping->id))->handle();
    }
    expect($ping->refresh()->status)->toBe('failed')->and($ping->attempts)->toBe(6)
        ->and($endpoint->refresh()->failures)->toBe(1);
});

it('never sends an agent\'s personal contacts through the brokerage\'s webhooks', function (): void {
    Http::fake();
    hookEndpoint(['contact.created']);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'email' => 'nia@example.test', 'source' => 'open_house'])->assertSessionHasNoErrors();
    expect(WebhookDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('syncs a connected app, logs each run, and alerts when it stops working', function (): void {
    connectAs($this->owner, 'fake_crm', ['api_key' => 'bad'])->assertSessionHasErrors(['api_key' => 'That key was not accepted.']);
    connectAs($this->owner, 'fake_crm', ['api_key' => 'good-key'])->assertSessionHasNoErrors();
    $c = Connection::query()->where('app_key', 'fake_crm')->sole();
    expect($c->label)->toBe('acme@fakecrm.test')->and($c->owner_user_id)->toBeNull()->and($c->cursor)->toBe('page-2')
        ->and(SyncRun::query()->sole()->only(['status', 'created', 'updated']))->toBe(['status' => 'ok', 'created' => 3, 'updated' => 1]);
    expect(DB::connection(Connections::cell())->table('connect_connections')->value('credentials'))->not->toContain('good-key'); // encrypted at rest

    FakeCrmConnector::$script = ['fail', 'fail', 'fail', 'fail'];
    foreach (range(1, 2) as $i) {
        (new SyncConnection($c->id))->handle(app(SyncRunner::class));
    }
    expect($c->refresh()->status)->toBe('connected')->and($c->failures)->toBe(2);
    Notification::assertNothingSent();
    (new SyncConnection($c->id))->handle(app(SyncRunner::class));
    expect($c->refresh()->status)->toBe('error')->and($c->last_error)->toBe('Fake CRM is down (503).');
    Notification::assertSentTo($this->owner, ConnectionNeedsAttention::class);
    (new SyncConnection($c->id))->handle(app(SyncRunner::class));
    Notification::assertSentToTimes($this->owner, ConnectionNeedsAttention::class, 1); // at most once a day

    FakeCrmConnector::$script = ['ok'];
    $this->actingAs($this->owner)->post('/connections/'.$c->ulid.'/sync')->assertSessionHasNoErrors();
    expect($c->refresh()->status)->toBe('connected')->and($c->failures)->toBe(0);

    FakeCrmConnector::$script = ['reconnect'];
    $this->actingAs($this->owner)->post('/connections/'.$c->ulid.'/sync');
    expect($c->refresh()->status)->toBe('needs_reconnect');
    $this->actingAs($this->owner)->post('/connections/'.$c->ulid.'/sync')->assertSessionHasErrors('sync');
});

it('keeps each agent\'s own connections private, and lets owners pause and disconnect the brokerage\'s', function (): void {
    connectAs($this->agent, 'fake_crm', ['api_key' => 'good-key'])->assertSessionHasNoErrors();
    $mine = Connection::query()->where('owner_user_id', $this->agent->id)->sole();
    $this->actingAs($this->owner)->get('/apps/fake_crm')->assertInertia(fn (Assert $p) => $p->where('connections', []));
    $this->actingAs($this->owner)->delete('/connections/'.$mine->ulid)->assertNotFound();

    connectAs($this->owner, 'fake_crm', ['api_key' => 'good-key'])->assertSessionHasNoErrors();
    $brokerage = Connection::query()->whereNull('owner_user_id')->sole();
    $this->actingAs($this->agent)->post('/connections/'.$brokerage->ulid.'/sync')->assertNotFound();
    $this->actingAs($this->agent)->get('/apps/fake_crm')->assertInertia(fn (Assert $p) => $p->has('connections', 1)->where('connections.0.mine', true));

    $this->actingAs($this->owner)->post('/connections/'.$brokerage->ulid.'/pause', ['paused' => true]);
    expect($brokerage->refresh()->status)->toBe('paused');
    $this->actingAs($this->owner)->delete('/connections/'.$brokerage->ulid)->assertSessionHasNoErrors();
    expect(Connection::query()->whereKey($brokerage->id)->exists())->toBeFalse();
});

it('asks for the password before storing sign-in details', function (): void {
    $this->actingAs($this->owner)->post('/apps/fake_crm/connect', ['api_key' => 'good-key'])->assertRedirect();
    expect(Connection::query()->count())->toBe(0);
});

it('records which coming apps brokerages want, for HQ', function (): void {
    $this->actingAs($this->owner)->post('/apps/follow_up_boss/request')->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post('/apps/follow_up_boss/request')->assertSessionHasNoErrors();
    expect(DB::connection(Connections::control())->table('hq_app_requests')->where('app_key', 'follow_up_boss')->value('requests'))->toBe(2);
    $this->actingAs($this->agent)->get('/apps/follow_up_boss')->assertInertia(fn (Assert $p) => $p->where('app.requested', true)->where('app.status', 'soon'));
});

it('shows forwarding addresses and the lead API on the built-in lead apps', function (): void {
    app(LeadSources::class)->create(['name' => 'Zillow', 'kind' => 'email', 'source' => 'zillow'], $this->owner);
    $this->actingAs($this->owner)->get('/apps/lead_email')->assertInertia(fn (Assert $p) => $p->where('help.emails.0.name', 'Zillow')
        ->where('help.emails.0.address', fn (string $a): bool => str_starts_with($a, 'leads-')));
    $this->actingAs($this->agent)->get('/apps/lead_email')->assertInertia(fn (Assert $p) => $p->where('help', []));
});
