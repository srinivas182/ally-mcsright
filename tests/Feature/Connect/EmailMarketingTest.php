<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Jobs\HandleWebhook;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Consent;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;

/* Sprint C7a: Mailchimp and Constant Contact — consented contacts out with their tags, unsubscribes back as email
   opt-outs (specs/connect/15). */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->agent = User::factory()->create();
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('mailchimp'), true, ['client_id' => 'mc-id', 'client_secret' => 'mc-secret'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('constant_contact'), true, ['client_id' => 'cc-id', 'client_secret' => 'cc-secret'], null, $hq);
    $contacts = app(ManageContacts::class);
    $consent = app(Consent::class);
    $this->amy = $contacts->create(['first_name' => 'Amy', 'last_name' => 'Agreed', 'email' => 'Amy@x.test', 'origin' => 'company'], $this->owner);
    $consent->setEmail($this->amy, true, 'web_form');
    app(Tags::class)->attach($this->amy->fresh(), 'Buyer');
    $this->ned = $contacts->create(['first_name' => 'Ned', 'last_name' => 'Neutral', 'email' => 'ned@x.test', 'origin' => 'company'], $this->owner);
    $this->oli = $contacts->create(['first_name' => 'Oli', 'last_name' => 'Out', 'email' => 'oli@x.test', 'origin' => 'company'], $this->owner);
    $consent->setEmail($this->oli, false, 'agent');
    $this->pat = $contacts->create(['first_name' => 'Pat', 'last_name' => 'Personal', 'email' => 'pat@x.test', 'origin' => 'personal'], $this->agent);
    $consent->setEmail($this->pat, true, 'web_form');
});

function emailConnect(User $as, string $app): Connection
{
    $start = test()->actingAs($as)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', $app)->sole();
}

it('sends Mailchimp only the brokerage\'s contacts who agreed to email, with their tags, once', function (): void {
    Http::fake([
        'login.mailchimp.com/oauth2/token' => Http::response(['access_token' => 'mc-at', 'expires_in' => 0, 'scope' => null]),
        'login.mailchimp.com/oauth2/metadata' => Http::response(['dc' => 'us21', 'api_endpoint' => 'https://us21.api.mailchimp.com', 'accountname' => 'Acme Realty', 'login' => ['email' => 'owner@acme.test']]),
        'us21.api.mailchimp.com/3.0/lists?*' => Http::response(['lists' => [['id' => 'aud1', 'name' => 'Acme newsletter', 'stats' => ['member_count' => 12]]]]),
        'us21.api.mailchimp.com/3.0/lists/aud1/webhooks' => Http::response(['id' => 'wh9']),
        'us21.api.mailchimp.com/3.0/lists/aud1/members/*' => Http::response(['id' => 'm']),
    ]);
    $c = emailConnect($this->owner, 'mailchimp');
    expect((int) ($c->credentials['expires_at'] ?? 0))->toBeGreaterThan(now()->addYears(5)->getTimestamp()); // never expires

    app(SyncRunner::class)->run($c->fresh());
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/members/'));
    $this->actingAs($this->owner)->get('/settings/email-marketing')->assertInertia(fn (Assert $p) => $p->component('settings/email-marketing')
        ->where('connections.0.audiences.0.name', 'Acme newsletter')->where('connections.0.who', 'consented'));
    $this->actingAs($this->owner)->post('/settings/email-marketing/'.$c->ulid, ['audience' => 'aud1', 'who' => 'not_opted_out'])->assertSessionHasErrors('permission');
    $this->actingAs($this->owner)->post('/settings/email-marketing/'.$c->ulid, ['audience' => 'aud1', 'who' => 'consented'])->assertSessionHasNoErrors();

    app(SyncRunner::class)->run($c->fresh());
    $amyHash = md5('amy@x.test');
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PUT' && $r->url() === "https://us21.api.mailchimp.com/3.0/lists/aud1/members/{$amyHash}"
        && $r['email_address'] === 'amy@x.test' && $r['status_if_new'] === 'subscribed' && $r['merge_fields'] === ['FNAME' => 'Amy', 'LNAME' => 'Agreed']
        && $r->header('Authorization')[0] === 'Bearer mc-at');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === "https://us21.api.mailchimp.com/3.0/lists/aud1/members/{$amyHash}/tags" && $r['tags'] === [['name' => 'Buyer', 'status' => 'active']]);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/lists/aud1/webhooks') && $r['events']['unsubscribe'] === true && str_contains((string) $r['url'], '/connect/hooks/mailchimp/'));
    foreach (['ned@x.test', 'oli@x.test', 'pat@x.test'] as $never) {
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), md5($never)));
    }
    $puts = count(Http::recorded(fn (HttpRequest $r): bool => $r->method() === 'PUT'));
    app(SyncRunner::class)->run($c->fresh()); // nothing changed: nothing sent again
    expect(count(Http::recorded(fn (HttpRequest $r): bool => $r->method() === 'PUT')))->toBe($puts)
        ->and($c->fresh()?->settings['in_audience'])->toBe(1);

    // Amy opts out here: she is unsubscribed in Mailchimp too.
    $this->travel(1)->minutes();
    app(Consent::class)->setEmail($this->amy->fresh(), false, 'agent');
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), "/members/{$amyHash}") && $r['status'] === 'unsubscribed');
});

it('turns a Mailchimp unsubscribe into an email opt-out here, from the audience\'s webhook', function (): void {
    Http::fake(['login.mailchimp.com/oauth2/token' => Http::response(['access_token' => 'mc-at', 'expires_in' => 0])]);
    $c = emailConnect($this->owner, 'mailchimp');
    $c->forceFill(['settings' => ['audience' => 'aud1']])->save();
    $url = 'https://auth.rightally.app/connect/hooks/mailchimp/'.$this->tenant->ulid.'/'.$c->ulid;
    $this->get($url)->assertOk(); // Mailchimp's check
    $body = http_build_query(['type' => 'unsubscribe', 'fired_at' => '2026-06-01 15:00:00', 'data' => ['email' => 'AMY@x.test', 'list_id' => 'aud1', 'action' => 'unsub']]);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertNoContent();
    expect(ConsentEvent::query()->where('contact_id', $this->amy->id)->where('channel', 'email')->latest('id')->first()?->only(['action', 'source', 'source_ref']))
        ->toBe(['action' => 'opt_out', 'source' => 'connected_app', 'source_ref' => 'Mailchimp']);

    app()->call([new HandleWebhook($c->id, ['type' => 'unsubscribe', 'data' => ['email' => 'ned@x.test', 'list_id' => 'other']]), 'handle']); // another audience: ignored
    expect(ConsentEvent::query()->where('contact_id', $this->ned->id)->count())->toBe(0);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'nonsense=1')->assertUnauthorized();
});

it('sends Constant Contact the consented contacts and reads unsubscribes back every run', function (): void {
    Http::fake([
        'authz.constantcontact.com/oauth2/default/v1/token' => Http::response(['access_token' => 'cc-at', 'refresh_token' => 'cc-rt', 'expires_in' => 86400]),
        'api.cc.email/v3/contact_lists*' => Http::response(['lists' => [['list_id' => '4a7c-list', 'name' => 'Past clients', 'membership_count' => 40]]]),
        'api.cc.email/v3/contacts/sign_up_form' => Http::response(['contact_id' => 'c1', 'action' => 'created']),
        'api.cc.email/v3/contacts?*' => Http::response(['contacts' => [['contact_id' => 'c2', 'email_address' => ['address' => 'ned@x.test', 'permission_to_send' => 'unsubscribed']]]]),
    ]);
    $c = emailConnect($this->owner, 'constant_contact');
    $c->forceFill(['settings' => ['audience' => '4a7c-list', 'who' => 'consented']])->save();
    app(SyncRunner::class)->run($c->fresh());
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.cc.email/v3/contacts/sign_up_form' && $r['email_address'] === 'amy@x.test'
        && $r['list_memberships'] === ['4a7c-list'] && $r->header('Authorization')[0] === 'Bearer cc-at');
    Http::assertSentCount(3); // token, sign-up for Amy, the unsubscribes read
    Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://api.cc.email/v3/contacts?') && $r['status'] === 'unsubscribed');
    expect(ConsentEvent::query()->where('contact_id', $this->ned->id)->where('channel', 'email')->value('action'))->toBe('opt_out');
});
