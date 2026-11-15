<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;

/* Sprint C7b: phone apps — Aircall, Dialpad and RingCentral calls (and RingCentral texts) logged on the matching
   contacts, once each; unknown numbers create nothing; a call to a new lead is the agent's response; recording
   links only when switched on. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $this->ann = User::factory()->create(['email' => 'ann@acme.test', 'first_name' => 'Ann']);
    $hq = User::factory()->make(['id' => 999999]);
    foreach (['aircall', 'dialpad'] as $k) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($k), true, [], null, $hq);
    }
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('ringcentral'), true, ['client_id' => 'rc-id', 'client_secret' => 'rc-secret'], null, $hq);
    $this->jane = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '(512) 555-0199', 'origin' => 'company', 'owner_user_id' => $this->ann->id], $this->owner);
    $this->lead = Lead::query()->create(['contact_id' => $this->jane->id, 'source' => 'website', 'channel' => 'website', 'status' => 'assigned', 'assigned_user_id' => $this->ann->id,
        'assigned_at' => now()->subHour(), 'new_contact' => true]);
});

function phoneConnect(object $t, string $app, array $in): Connection
{
    $t->actingAs($t->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', $in)->assertSessionHasNoErrors();

    return Connection::query()->where('app_key', $app)->sole();
}

it('logs Aircall calls on the matching contact once, as the agent\'s response, with no new contacts for unknown numbers', function (): void {
    Http::fake([
        'api.aircall.io/v1/company' => Http::response(['company' => ['name' => 'Acme Realty']]),
        'api.aircall.io/v1/calls*' => Http::response(['calls' => [
            ['id' => 101, 'direction' => 'outbound', 'status' => 'done', 'raw_digits' => '+1 512-555-0199', 'started_at' => now()->subMinutes(30)->getTimestamp(), 'answered_at' => now()->subMinutes(29)->getTimestamp(),
                'duration' => 185, 'user' => ['email' => 'ann@acme.test'], 'recording' => 'https://rec.aircall.io/101.mp3'],
            ['id' => 102, 'direction' => 'inbound', 'status' => 'done', 'raw_digits' => '+1 212-555-0000', 'started_at' => now()->subMinutes(20)->getTimestamp(), 'duration' => 40, 'user' => null],
            ['id' => 103, 'direction' => 'inbound', 'status' => 'ringing', 'raw_digits' => '+1 512-555-0199', 'started_at' => now()->subMinute()->getTimestamp()],
        ], 'meta' => ['next_page_link' => null]]),
    ]);
    $c = phoneConnect($this, 'aircall', ['api_id' => 'id-1', 'api_token' => 'tok-1']);
    expect($c->label)->toBe('Aircall (Acme Realty)')->and($c->settings['recordings'] ?? null)->toBeFalse();

    $call = Activity::query()->where('type', 'call')->sole();
    expect($call->only(['contact_id', 'direction', 'outcome', 'user_id', 'duration_seconds']))->toBe(['contact_id' => $this->jane->id, 'direction' => 'out', 'outcome' => 'connected',
        'user_id' => $this->ann->id, 'duration_seconds' => 240])
        ->and($call->body)->toBe('Outgoing call via Aircall, 3 min 5 sec.') // no recording link: not switched on
        ->and(Contact::query()->count())->toBe(1)
        ->and($this->lead->fresh()?->responded_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'api.aircall.io/v1/calls') && $r->hasHeader('Authorization', 'Basic '.base64_encode('id-1:tok-1')));

    app(SyncRunner::class)->run($c->fresh()); // again: nothing twice
    expect(Activity::query()->where('type', 'call')->count())->toBe(1);
});

it('shows recording links only when the brokerage switched them on, and reads Dialpad calls', function (): void {
    Http::fake([
        'dialpad.com/api/v2/company' => Http::response(['name' => 'Acme']),
        'dialpad.com/api/v2/call*' => Http::response(['items' => [
            ['call_id' => 'd-9', 'direction' => 'inbound', 'external_number' => '+15125550199', 'date_started' => now()->subMinutes(10)->getTimestampMs(), 'date_ended' => now()->subMinutes(9)->getTimestampMs(),
                'duration' => 61000, 'target' => ['email' => 'ann@acme.test'], 'recording_details' => [['url' => 'https://dialpad.com/r/d-9']]],
        ]]),
    ]);
    phoneConnect($this, 'dialpad', ['api_key' => 'dp-key', 'recordings' => '1']);
    expect(Activity::query()->where('type', 'call')->sole()->body)->toBe('Incoming call via Dialpad, 1 min 1 sec. Recording: https://dialpad.com/r/d-9');
    Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://dialpad.com/api/v2/call?') && $r->hasHeader('Authorization', 'Bearer dp-key'));
});

it('reads RingCentral calls and texts for the whole account', function (): void {
    Http::fake([
        'platform.ringcentral.com/restapi/oauth/token' => Http::response(['access_token' => 'rc-at', 'refresh_token' => 'rc-rt', 'expires_in' => 3600]),
        'platform.ringcentral.com/restapi/v1.0/account/~/call-log*' => Http::response(['records' => [
            ['id' => 'rc-1', 'direction' => 'Inbound', 'from' => ['phoneNumber' => '+15125550199'], 'to' => ['phoneNumber' => '+15125550100'], 'startTime' => now()->subMinutes(5)->toIso8601String(),
                'duration' => 0, 'result' => 'Missed']]]),
        'platform.ringcentral.com/restapi/v1.0/account/~/extension/~/message-store*' => Http::response(['records' => [
            ['id' => 'sms-1', 'direction' => 'Outbound', 'from' => ['phoneNumber' => '+15125550100'], 'to' => [['phoneNumber' => '+15125550199']], 'creationTime' => now()->subMinutes(3)->toIso8601String(),
                'subject' => 'Hi Jane, it\'s Olive. Still looking?']]]),
    ]);
    $start = $this->actingAs($this->owner)->post('/apps/ringcentral/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    $this->actingAs($this->owner)->get($home)->assertRedirect();

    expect(Activity::query()->where('contact_id', $this->jane->id)->whereIn('type', ['call', 'text'])->orderBy('id')->get(['type', 'direction', 'outcome', 'body'])->toArray())->toBe([
        ['type' => 'call', 'direction' => 'in', 'outcome' => 'no_answer', 'body' => 'Incoming call via RingCentral.'],
        ['type' => 'text', 'direction' => 'out', 'outcome' => null, 'body' => 'Hi Jane, it\'s Olive. Still looking? (from RingCentral)'],
    ]);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'account/~/call-log?') && $r->hasHeader('Authorization', 'Bearer rc-at'));
});
