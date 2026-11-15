<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Connect\AppRegistry;
use Modules\Connect\Connections;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Connect\ConnectorKit;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/* Sprint C2a-2: Follow Up Boss live two-way sync — signed webhooks in, new contacts and notes out, no ping-pong. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('follow_up_boss'), true,
        ['x_system' => 'RightAlly', 'x_system_key' => 'sys-key', 'client_id' => 'fub-id', 'client_secret' => 'fub-secret'], null, User::factory()->make(['id' => 999999]));
});

/** Connects (empty account) and returns the connection; $hooks is what Follow Up Boss answers to webhook registration. */
function fubLive(User $as, ?Closure $hooks = null): Connection
{
    Http::fake([
        'app.followupboss.com/oauth/token' => Http::response(['access_token' => 'fub-at', 'refresh_token' => 'fub-rt', 'expires_in' => 3600]),
        'api.followupboss.com/v1/me' => Http::response(['email' => 'x@fub.test']),
        'api.followupboss.com/v1/users*' => Http::response(['users' => [['id' => 7, 'email' => 'jane@acme.test']]]),
        'api.followupboss.com/v1/people*' => Http::response(['people' => [], '_metadata' => []]),
        'api.followupboss.com/v1/notes*' => Http::response(['notes' => []]),
        'api.followupboss.com/v1/webhooks*' => $hooks ?? fn (HttpRequest $r) => Http::response(['id' => 100 + count(Http::recorded()), 'status' => 'Active']),
    ]);
    $start = test()->actingAs($as)->post('/apps/follow_up_boss/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home);

    return Connection::query()->where('app_key', 'follow_up_boss')->sole();
}

/** @param  array<string, mixed>  $payload */
function fubHook(Connection $c, array $payload, ?string $signature = null): TestResponse
{
    $raw = (string) json_encode($payload);
    $sig = $signature ?? hash_hmac('sha256', base64_encode($raw), 'sys-key');

    return test()->call('POST', 'https://auth.rightally.app/connect/hooks/follow_up_boss/'.test()->tenant->ulid.'/'.$c->ulid, [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_FUB_SIGNATURE' => $sig], $raw);
}

it('switches on live updates after the import, at the connection\'s own address', function (): void {
    $c = fubLive($this->owner);
    $url = 'https://auth.rightally.app/connect/hooks/follow_up_boss/'.$this->tenant->ulid.'/'.$c->ulid;
    foreach (['peopleCreated', 'peopleUpdated', 'peopleTagsCreated', 'peopleStageUpdated', 'notesCreated'] as $event) {
        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && $r->url() === 'https://api.followupboss.com/v1/webhooks'
            && $r['event'] === $event && $r['url'] === $url && $r->header('X-System')[0] === 'RightAlly');
    }
    expect($c->settings['live'])->toBeTrue()->and($c->settings['webhooks'])->toHaveCount(5)->and($c->settings['interval_minutes'])->toBe(60);
});

it('checks for changes every 15 minutes when the person is not the Follow Up Boss account owner', function (): void {
    $c = fubLive($this->agent, fn () => Http::response(['errorMessage' => 'Only the owner'], 403));
    expect($c->status)->toBe('connected')->and($c->settings['live'])->toBeFalse()->and($c->settings['interval_minutes'])->toBe(15);
});

it('takes in signed people updates within seconds: new names and the new stage', function (): void {
    $c = fubLive($this->owner);
    $ana = app(ManageContacts::class)->create(['first_name' => 'Ana', 'last_name' => 'Doe', 'email' => 'ana@x.test'], $this->owner);
    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/people*' => Http::response(['people' => [
        ['id' => 1, 'firstName' => 'Anna', 'lastName' => 'Doe', 'emails' => [['value' => 'ana@x.test']], 'stage' => 'Lead'],
    ]])]);
    fubHook($c, ['event' => 'peopleCreated', 'resourceIds' => [1]])->assertNoContent();
    expect($ana->fresh()?->first_name)->toBe('Ana'); // linked (same email), not overwritten

    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/people*' => Http::response(['people' => [
        ['id' => 1, 'firstName' => 'Anna', 'lastName' => 'Doe', 'emails' => [['value' => 'ana@x.test']], 'stage' => 'Active Client'],
    ]])]);
    fubHook($c, ['event' => 'peopleStageUpdated', 'resourceIds' => [1], 'data' => ['stage' => 'Active Client']])->assertNoContent();
    expect($ana->fresh()?->tags()->pluck('name')->all())->toBe(['FUB stage: Active Client']) // one stage at a time
        ->and($ana->fresh()?->first_name)->toBe('Anna'); // then kept in step
    Http::assertSent(fn (HttpRequest $r): bool => str_contains(urldecode($r->url()), 'id=1'));
});

it('refuses webhooks without a valid signature, and tells Follow Up Boss to stop for a removed connection', function (): void {
    $c = fubLive($this->owner);
    Http::swap(new Factory);
    Http::fake();
    fubHook($c, ['event' => 'peopleCreated', 'resourceIds' => [1]], 'forged')->assertStatus(401);
    $this->call('POST', 'https://auth.rightally.app/connect/hooks/follow_up_boss/'.$this->tenant->ulid.'/'.$c->ulid, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertStatus(401);
    Http::assertNothingSent();

    $ulid = $c->ulid;
    $c->delete();
    $raw = (string) json_encode(['event' => 'peopleCreated', 'resourceIds' => [1]]);
    $this->call('POST', 'https://auth.rightally.app/connect/hooks/follow_up_boss/'.$this->tenant->ulid.'/'.$ulid, [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_FUB_SIGNATURE' => hash_hmac('sha256', base64_encode($raw), 'sys-key')], $raw)->assertStatus(410);
});

it('takes in new notes, and never sends them back', function (): void {
    $c = fubLive($this->owner);
    $ana = app(ManageContacts::class)->create(['first_name' => 'Ana', 'email' => 'ana@x.test'], $this->owner);
    app(ConnectorKit::class)->remember($c->data(), 'contact', '1', $ana->id);
    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/notes/55' => Http::response(['id' => 55, 'personId' => 1, 'body' => 'Pre-approved at 600k']), '*' => Http::response(['id' => 9])]);
    fubHook($c, ['event' => 'notesCreated', 'resourceIds' => [55]])->assertNoContent();

    expect(Activity::query()->where('contact_id', $ana->id)->where('type', 'note')->value('body'))->toBe('From Follow Up Boss: Pre-approved at 600k');
    Http::assertNotSent(fn (HttpRequest $r): bool => $r->method() === 'POST');
});

it('sends new RightAlly contacts and notes to Follow Up Boss, with their agent', function (): void {
    $c = fubLive($this->owner);
    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/people' => Http::response(['id' => 501]), 'api.followupboss.com/v1/notes' => Http::response(['id' => 901])]);
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@x.test', 'phone' => '312-555-0199', 'owner_user_id' => $this->agent->id], $this->owner);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.followupboss.com/v1/people' && $r['firstName'] === 'Sam' && $r['source'] === 'RightAlly'
        && $r['emails'] === [['value' => 'sam@x.test']] && $r['phones'] === [['value' => '+13125550199']] && $r['assignedUserId'] === 7);

    app(Timeline::class)->log($sam, ['type' => 'note', 'body' => 'Wants a yard'], $this->owner);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.followupboss.com/v1/notes' && $r['personId'] === 501 && $r['body'] === 'Wants a yard');
    expect(Http::recorded(fn (HttpRequest $r): bool => $r->url() === 'https://api.followupboss.com/v1/people'))->toHaveCount(1); // not added twice
});

it('keeps an agent\'s personal contacts out of the brokerage\'s Follow Up Boss', function (): void {
    fubLive($this->owner);
    Http::swap(new Factory);
    Http::fake();
    app(ManageContacts::class)->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->agent); // personal
    Http::assertNothingSent();
});

it('checks recently changed people when live updates are not allowed', function (): void {
    $c = fubLive($this->agent, fn () => Http::response([], 403));
    $c->forceFill(['cursor' => json_encode(['phase' => 'done', 'since' => '2026-11-14T10:00:00+00:00'])])->save();
    Http::swap(new Factory);
    Http::fake([
        'api.followupboss.com/v1/people*' => Http::response(['people' => [
            ['id' => 2, 'firstName' => 'New', 'lastName' => 'Person', 'emails' => [['value' => 'new@x.test']], 'updated' => '2026-11-14T11:00:00Z'],
            ['id' => 3, 'firstName' => 'Old', 'lastName' => 'Person', 'emails' => [['value' => 'old@x.test']], 'updated' => '2026-11-14T09:00:00Z'],
        ], '_metadata' => ['next' => 'p2']]),
        'api.followupboss.com/v1/notes*' => Http::response(['notes' => [['id' => 70, 'personId' => 2, 'body' => 'Called back']]]),
    ]);
    app(SyncRunner::class)->run($c->fresh());

    expect(Contact::query()->pluck('primary_email')->all())->toBe(['new@x.test'])
        ->and(Activity::query()->where('type', 'note')->value('body'))->toBe('From Follow Up Boss: Called back');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'sort=-updated'));
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'next=p2')); // stopped at the last check
    expect(CarbonImmutable::parse(json_decode((string) $c->fresh()?->cursor, true)['since'])->isToday())->toBeTrue();
});

it('switches off Follow Up Boss\'s webhooks when disconnected', function (): void {
    $c = fubLive($this->owner);
    $ids = $c->settings['webhooks'];
    Http::swap(new Factory);
    Http::fake();
    app(Connections::class)->disconnect($c, $this->owner);
    foreach ($ids as $id) {
        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && $r->url() === 'https://api.followupboss.com/v1/webhooks/'.$id);
    }
});
