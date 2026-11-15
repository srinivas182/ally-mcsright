<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Connect\AppRegistry;
use Modules\Connect\Jobs\SyncConnection;
use Modules\Connect\Models\Connection;
use Modules\Connect\Models\SyncRun;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/* Sprint C2a: Follow Up Boss — connect in one click, and people, tags, stages, agents and notes come over. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test', 'first_name' => 'Jane']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('follow_up_boss'), true,
        ['x_system' => 'RightAlly', 'x_system_key' => 'sys-key', 'client_id' => 'fub-id', 'client_secret' => 'fub-secret'], null, User::factory()->make(['id' => 999999]));
});

/** @param  array<string, mixed>  $api  extra fakes for Follow Up Boss's API */
function fubConnect(User $as, array $api = []): TestResponse
{
    Http::fake($api + [
        'app.followupboss.com/oauth/token' => Http::response(['access_token' => 'fub-at', 'refresh_token' => 'fub-rt', 'expires_in' => 3600]),
        'api.followupboss.com/v1/me' => Http::response(['email' => 'owner@fub.test']),
        'api.followupboss.com/v1/users*' => Http::response(['users' => []]),
        'api.followupboss.com/v1/people*' => Http::response(['people' => [], '_metadata' => []]),
        'api.followupboss.com/v1/notes*' => Http::response(['notes' => []]),
        'api.followupboss.com/v1/webhooks*' => Http::response(['id' => 11, 'status' => 'Active']),
    ]);
    $start = test()->actingAs($as)->post('/apps/follow_up_boss/oauth', [], ['X-Inertia' => 'true']);
    $start->assertStatus(409);
    $to = (string) $start->headers->get('X-Inertia-Location');
    expect($to)->toStartWith('https://app.followupboss.com/oauth/authorize?');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    expect($q['response_type'])->toBe('auth_code')->and($q['client_id'])->toBe('fub-id')->and($q)->not->toHaveKey('code_challenge');

    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    expect($home)->toStartWith('https://acme.rightally.app/apps/oauth/complete?');

    return test()->actingAs($as)->get($home);
}

function fubPerson(int $id, string $first, string $email, array $extra = []): array
{
    $base = ['id' => $id, 'firstName' => $first, 'lastName' => 'Doe', 'emails' => [['value' => $email, 'isPrimary' => 1]], 'phones' => [], 'tags' => [], 'stage' => 'Lead'];

    return $extra + $base;
}

it('connects in one click, with the client secret sent only from the server', function (): void {
    fubConnect($this->owner)->assertRedirect('/apps/follow_up_boss');
    $c = Connection::query()->sole();
    expect($c->only(['app_key', 'owner_user_id', 'label', 'status']))->toBe(['app_key' => 'follow_up_boss', 'owner_user_id' => null, 'label' => 'owner@fub.test', 'status' => 'connected']);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://app.followupboss.com/oauth/token'
        && $r->header('Authorization')[0] === 'Basic '.base64_encode('fub-id:fub-secret') && $r['code'] === 'abc' && $r['state'] !== '' && ! isset($r['client_secret']));
    Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://api.followupboss.com/v1/people')
        && $r->header('X-System')[0] === 'RightAlly' && $r->header('X-System-Key')[0] === 'sys-key' && $r->header('Authorization')[0] === 'Bearer fub-at');
});

it('brings people over with their agent, tags and stage, and their notes, without duplicates', function (): void {
    $existing = app(ManageContacts::class)->create(['first_name' => 'Sam', 'last_name' => 'Known', 'email' => 'sam@x.test'], $this->owner);
    fubConnect($this->owner, [
        'api.followupboss.com/v1/users*' => Http::response(['users' => [['id' => 7, 'email' => 'JANE@acme.test']]]),
        'api.followupboss.com/v1/people*' => Http::sequence()
            ->push(['people' => [fubPerson(1, 'Ana', 'ana@x.test', ['assignedUserId' => 7, 'tags' => ['Buyer', 'Hot'], 'stage' => 'Active Client', 'source' => 'Zillow',
                'phones' => [['value' => '(312) 555-0101', 'isPrimary' => 1]]]), fubPerson(2, 'Sam', 'sam@x.test')], '_metadata' => ['next' => 'page2']])
            ->push(['people' => [fubPerson(3, '', '', ['lastName' => ''])], '_metadata' => ['next' => null]]),
        'api.followupboss.com/v1/notes*' => Http::response(['notes' => [['id' => 50, 'personId' => 1, 'subject' => 'Call', 'body' => '<p>Wants a 3-bed</p>', 'created' => '2026-01-02T10:00:00Z'],
            ['id' => 51, 'personId' => 999, 'body' => 'Unknown person']]]),
    ]);

    $ana = Contact::query()->where('primary_email', 'ana@x.test')->sole();
    expect(Contact::query()->count())->toBe(2)
        ->and($ana->owner_user_id)->toBe($this->agent->id)
        ->and($ana->origin->value)->toBe('company')
        ->and($ana->source->value)->toBe('import')
        ->and($ana->source_detail)->toBe('Follow Up Boss: Zillow')
        ->and($ana->primary_phone)->toBe('+13125550101')
        ->and($ana->tags()->pluck('name')->sort()->values()->all())->toBe(['Buyer', 'FUB stage: Active Client', 'Hot'])
        ->and($existing->fresh()?->tags()->pluck('name')->all())->toBe(['FUB stage: Lead']);
    $note = Activity::query()->where('contact_id', $ana->id)->where('type', 'note')->sole();
    expect($note->body)->toBe("From Follow Up Boss: Call\nWants a 3-bed")->and($note->occurred_at->toDateString())->toBe('2026-01-02');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/v1/people') && str_contains($r->url(), 'next=page2'));

    // Running again changes nothing.
    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/users*' => Http::response(['users' => []]),
        'api.followupboss.com/v1/people*' => Http::response(['people' => [fubPerson(1, 'Ana', 'ana@x.test'), fubPerson(2, 'Sam', 'sam@x.test')], '_metadata' => []]),
        'api.followupboss.com/v1/notes*' => Http::response(['notes' => [['id' => 50, 'personId' => 1, 'body' => 'Wants a 3-bed']]])]);
    $c = Connection::query()->sole();
    $c->forceFill(['cursor' => null])->save();
    app(SyncRunner::class)->run($c->fresh());
    expect(Contact::query()->count())->toBe(2)->and(Activity::query()->where('type', 'note')->count())->toBe(1)
        ->and(SyncRun::query()->latest('id')->value('status'))->toBe('ok');
});

it('brings an agent\'s own Follow Up Boss people in as their personal contacts', function (): void {
    fubConnect($this->agent, ['api.followupboss.com/v1/people*' => Http::response(['people' => [fubPerson(1, 'Ana', 'ana@x.test')], '_metadata' => []])]);
    $ana = Contact::query()->sole();
    expect($ana->owner_user_id)->toBe($this->agent->id)->and($ana->origin->value)->toBe('personal')
        ->and(Connection::query()->sole()->owner_user_id)->toBe($this->agent->id);
});

it('leaves alone a brokerage contact the agent cannot see', function (): void {
    $theirs = app(ManageContacts::class)->create(['first_name' => 'Ana', 'email' => 'ana@x.test', 'owner_user_id' => $this->owner->id], $this->owner);
    fubConnect($this->agent, ['api.followupboss.com/v1/people*' => Http::response(['people' => [fubPerson(1, 'Ana', 'ana@x.test')], '_metadata' => []])]);
    expect(Contact::query()->count())->toBe(1)->and($theirs->fresh()?->tags()->count())->toBe(0);
});

it('carries on later when Follow Up Boss asks to slow down, and asks to reconnect when the sign-in is revoked', function (): void {
    Queue::fake(); // run the steps by hand
    fubConnect($this->owner, ['api.followupboss.com/v1/people*' => Http::response([], 429)]);
    $c = Connection::query()->sole();
    app(SyncRunner::class)->run($c);
    $c->refresh();
    Queue::assertPushed(SyncConnection::class);
    expect(SyncRun::query()->sole()->note)->toBe('Follow Up Boss asked us to slow down; carrying on shortly.');
    expect(json_decode((string) $c->cursor, true))->toBe(['phase' => 'people'])->and($c->status)->toBe('connected');

    Http::swap(new Factory);
    Http::fake(['api.followupboss.com/v1/users*' => Http::response([], 401)]);
    $c->forceFill(['cursor' => null])->save();
    app(SyncRunner::class)->run($c->fresh());
    expect($c->fresh()?->status)->toBe('needs_reconnect');
});

it('cannot be connected while HQ has Follow Up Boss switched off', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('follow_up_boss'), false, [], null, User::factory()->make(['id' => 999999]));
    $this->actingAs($this->owner)->post('/apps/follow_up_boss/oauth')->assertSessionHasErrors('app');
});
