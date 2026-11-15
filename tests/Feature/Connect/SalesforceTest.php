<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Models\Contact;

/* Sprint C2d-2: Salesforce and Propertybase — one click with RightAlly's connected app; contacts and open leads come
   over, then only what changed. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('salesforce'), true, ['client_id' => 'sf-key', 'client_secret' => 'sf-secret'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('propertybase'), true, [], null, $hq);
});

function sfConnect(User $as, string $app = 'salesforce'): void
{
    $start = test()->actingAs($as)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    $to = (string) $start->headers->get('X-Inertia-Location');
    expect($to)->toStartWith('https://login.salesforce.com/services/oauth2/authorize?');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('sf-key')->and($q['code_challenge_method'])->toBe('S256')->and($q['scope'])->toContain('refresh_token');
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();
}

it('connects in one click and brings contacts and open leads over with their owner', function (): void {
    Http::fake([
        'login.salesforce.com/services/oauth2/token' => Http::response(['access_token' => 'sf-at', 'refresh_token' => 'sf-rt', 'instance_url' => 'https://acme.my.salesforce.com']),
        'login.salesforce.com/services/oauth2/userinfo' => Http::response(['email' => 'owner@acme.test']),
        'acme.my.salesforce.com/services/data/v62.0/query/01gX-2000' => Http::response(['records' => [['attributes' => ['type' => 'Contact'], 'Id' => '003B', 'FirstName' => 'Bo',
            'LastName' => 'Ng', 'Email' => 'bo@x.test']]]),
        'acme.my.salesforce.com/services/data/v62.0/query*' => function (HttpRequest $r) {
            $q = (string) ($r->data()['q'] ?? urldecode((string) parse_url($r->url(), PHP_URL_QUERY)));

            return match (true) {
                str_contains($q, 'FROM User') => Http::response(['records' => [['Id' => '005A', 'Email' => 'jane@acme.test']]]),
                str_contains($q, 'FROM Contact') => Http::response(['records' => [['attributes' => ['type' => 'Contact'], 'Id' => '003A', 'FirstName' => 'Ana', 'LastName' => 'Diaz',
                    'Email' => 'ana@x.test', 'MobilePhone' => '512-555-0101', 'LeadSource' => 'Web', 'OwnerId' => '005A']], 'nextRecordsUrl' => '/services/data/v62.0/query/01gX-2000']),
                str_contains($q, 'FROM Lead') => Http::response(['records' => [['attributes' => ['type' => 'Lead'], 'Id' => '00QA', 'FirstName' => 'Lee', 'LastName' => 'Park',
                    'Email' => 'lee@x.test', 'Status' => 'Working', 'OwnerId' => '005A']]]),
                default => Http::response(['records' => []]),
            };
        },
    ]);
    sfConnect($this->owner);

    $c = Connection::query()->sole();
    expect($c->credentials['instance_url'])->toBe('https://acme.my.salesforce.com')->and($c->owner_user_id)->toBeNull()
        ->and(Contact::query()->orderBy('primary_email')->pluck('primary_email')->all())->toBe(['ana@x.test', 'bo@x.test', 'lee@x.test'])
        ->and(Contact::query()->where('primary_email', 'ana@x.test')->sole()->owner_user_id)->toBe($this->agent->id)
        ->and(Contact::query()->where('primary_email', 'lee@x.test')->sole()->tags()->pluck('name')->sort()->values()->all())->toBe(['Salesforce lead', 'Salesforce status: Working']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'acme.my.salesforce.com') && $r->header('Authorization')[0] === 'Bearer sf-at');

    Http::swap(new Factory);
    Http::fake(['acme.my.salesforce.com/*' => Http::response(['records' => []])]);
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains(urldecode($r->url()), 'LastModifiedDate >'));
});

it('lets Propertybase use the Salesforce app\'s keys, and is for the brokerage only', function (): void {
    Http::fake(['login.salesforce.com/services/oauth2/token' => Http::response(['access_token' => 'a', 'instance_url' => 'https://pb.my.salesforce.com']),
        'login.salesforce.com/services/oauth2/userinfo' => Http::response(['email' => 'o@acme.test']), 'pb.my.salesforce.com/*' => Http::response(['records' => []])]);
    sfConnect($this->owner, 'propertybase');
    expect(Connection::query()->sole()->app_key)->toBe('propertybase');

    $this->actingAs($this->agent)->get('/apps/salesforce')->assertInertia(fn (Assert $p) => $p->where('app.scope', 'brokerage'));
});
