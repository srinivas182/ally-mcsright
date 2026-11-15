<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Connect\Models\WebhookDelivery;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\Connector;
use Modules\Core\Connect\Outbox;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/* Sprint C1c: HQ app registry — HQ switches each app on or off, saves its keys, and sees its setup steps. */

final class RegistryFakeConnector implements Connector
{
    public function verify(array $credentials, array $settings): string
    {
        return 'ok';
    }

    public function sync(ConnectionData $connection): array
    {
        return ['created' => 1, 'updated' => 0, 'skipped' => 0, 'cursor' => null, 'note' => null];
    }
}

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->hq = 'http://app.rightally.io';
    app(AppCatalog::class)->add(new App('partner_crm', 'Partner CRM', 'crm', 'A CRM that needs a partner key.', scope: 'both', auth: 'api_key', syncs: ['Contacts'],
        fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'text', 'secret' => true]], driver: RegistryFakeConnector::class,
        setup: ['needs' => 'keys', 'fields' => [['key' => 'partner_key', 'label' => 'RightAlly partner key']], 'steps' => ['Apply to the partner programme.', 'Paste the partner key.'],
            'partner' => 'Partner CRM gives API access to approved partners only.']));
    app()->instance('connect.dns', fn (string $h): array => ['93.184.216.34']);
});

function hqSaveApp(string $app, array $in): TestResponse
{
    return test()->actingAs(test()->staff)->withSession(['auth.password_confirmed_at' => time()])->post(test()->hq.'/hq/connect/apps/'.$app, $in);
}

it('keeps apps off until HQ switches them on and finishes their setup', function (): void {
    $this->actingAs($this->owner)->get('/apps')->assertInertia(fn (Assert $p) => $p
        ->where('apps', fn ($apps): bool => collect($apps)->firstWhere('key', 'partner_crm')['status'] === 'soon'
            && collect($apps)->firstWhere('key', 'webhooks')['status'] === 'available'));

    hqSaveApp('partner_crm', ['enabled' => true])->assertSessionHasNoErrors();
    expect(app(AppRegistry::class)->ready(app(AppCatalog::class)->find('partner_crm')))->toBeFalse(); // on, but no partner key yet
    $this->actingAs($this->staff)->get($this->hq.'/hq/connect')->assertInertia(fn (Assert $p) => $p
        ->where('apps', fn ($apps): bool => collect($apps)->firstWhere('key', 'partner_crm')['enabled'] === true
            && collect($apps)->firstWhere('key', 'partner_crm')['setup_complete'] === false
            && collect($apps)->firstWhere('key', 'partner_crm')['partner'] === 'Partner CRM gives API access to approved partners only.'));

    hqSaveApp('partner_crm', ['enabled' => true, 'keys' => ['partner_key' => 'pk_live_123456']])->assertSessionHasNoErrors();
    expect(app(AppRegistry::class)->ready(app(AppCatalog::class)->find('partner_crm')))->toBeTrue()
        ->and(app(AppRegistry::class)->secrets(app(AppCatalog::class)->find('partner_crm')))->toBe(['partner_key' => 'pk_live_123456'])
        ->and(DB::connection(Connections::control())->table('hq_connect_apps')->value('credentials'))->not->toContain('pk_live');
    $page = $this->actingAs($this->staff)->get($this->hq.'/hq/connect');
    expect((string) $page->getContent())->not->toContain('pk_live_123456');
    $this->actingAs($this->owner)->get('/apps/partner_crm')->assertInertia(fn (Assert $p) => $p->where('app.status', 'available')->where('app.ready', true));
});

it('cannot switch on an app that is not built yet', function (): void {
    hqSaveApp('sierra', ['enabled' => true])->assertSessionHasErrors('enabled');
});

it('pauses every connection when HQ switches an app off, and resumes when it is back on', function (): void {
    hqSaveApp('partner_crm', ['enabled' => true, 'keys' => ['partner_key' => 'pk_live_123456']]);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/partner_crm/connect', ['api_key' => 'k'])->assertSessionHasNoErrors();
    $c = Connection::query()->where('app_key', 'partner_crm')->sole();
    $runs = SyncRun::query()->count();

    hqSaveApp('partner_crm', ['enabled' => false]);
    expect(app(SyncRunner::class)->run($c))->toBeNull()->and(SyncRun::query()->count())->toBe($runs);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/partner_crm/connect', ['api_key' => 'k'])->assertSessionHasErrors('app');
    $this->actingAs($this->owner)->get('/apps/partner_crm')->assertInertia(fn (Assert $p) => $p->where('app.status', 'soon')->has('connections', 1));

    hqSaveApp('partner_crm', ['enabled' => true]); // the saved key is kept
    expect(app(SyncRunner::class)->run($c))->not->toBeNull();
});

it('stops webhook deliveries when HQ switches webhooks off', function (): void {
    Http::fake();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/apps/webhooks/connect', ['url' => 'https://hooks.example.com/x', 'events' => ['contact.created']])->assertSessionHasNoErrors();
    hqSaveApp('webhooks', ['enabled' => false]);
    app(Outbox::class)->emit('contact.created', ['contact' => ['id' => 'x']]);
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('lists every app with its steps for HQ, and is HQ-only and password-confirmed', function (): void {
    $this->actingAs($this->staff)->get($this->hq.'/hq/connect')->assertInertia(fn (Assert $p) => $p
        ->where('apps', fn ($apps): bool => collect($apps)->count() >= 75
            && collect($apps)->firstWhere('key', 'google_calendar')['return_address'] === 'https://auth.rightally.app/connect/oauth/callback'
            && collect($apps)->firstWhere('key', 'gohighlevel')['built'] === false));
    $this->actingAs($this->staff)->post($this->hq.'/hq/connect/apps/webhooks', ['enabled' => false])->assertRedirect();
    expect(DB::connection(Connections::control())->table('hq_connect_apps')->count())->toBe(0);
});
