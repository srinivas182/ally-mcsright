<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\Notifications\ConnectionNeedsAttention;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Booking\Availability;

/* Sprint C1b: one-click Google and Microsoft calendar — sign in and approve; bookings avoid busy times and land in the calendar. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC')); // Wednesday 10:00 in Chicago
    Notification::fake();
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret', 'services.microsoft.client_id' => 'mid', 'services.microsoft.client_secret' => 'msecret']);
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create(['first_name' => 'Jane']);
    $hq = User::factory()->make(['id' => 999999]);
    foreach (['google_calendar', 'microsoft_calendar'] as $key) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, $hq);
    }
    $wed = ['mon' => [], 'tue' => [], 'wed' => [['12:00', '15:00']], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []];
    app(Availability::class)->save($this->agent, true, $wed, 60, 0, $this->agent);
});

/** Presses Connect, approves at the provider, comes back home. Returns the final response. */
function calendarConnect(string $app, string $provider, array $tokens = ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600]): TestResponse
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response($tokens),
        'login.microsoftonline.com/*' => Http::response($tokens),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'jane@gmail.test']),
        'graph.microsoft.com/v1.0/me' => Http::response(['mail' => 'jane@outlook.test']),
    ]);
    $start = test()->actingAs(test()->agent)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    $start->assertStatus(409);
    $to = (string) $start->headers->get('X-Inertia-Location');
    expect($to)->toStartWith($provider === 'google' ? 'https://accounts.google.com/o/oauth2/v2/auth?' : 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    expect($q['redirect_uri'])->toBe('https://auth.rightally.app/connect/oauth/callback')
        ->and($q['code_challenge_method'])->toBe('S256')
        ->and($q['scope'])->toContain($provider === 'google' ? 'calendar.freebusy' : 'Calendars.ReadWrite');

    $back = test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']));
    $home = (string) $back->headers->get('Location');
    expect($home)->toStartWith('https://acme.rightally.app/apps/oauth/complete?');

    return test()->actingAs(test()->agent)->get($home);
}

it('connects Google Calendar in one click, with the secret kept on the server', function (): void {
    calendarConnect('google_calendar', 'google')->assertRedirect('/apps/google_calendar');
    $c = Connection::query()->sole();
    expect($c->only(['app_key', 'owner_user_id', 'label', 'status']))->toBe(['app_key' => 'google_calendar', 'owner_user_id' => $this->agent->id, 'label' => 'jane@gmail.test', 'status' => 'connected'])
        ->and($c->credentials['refresh_token'])->toBe('rt-1');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://oauth2.googleapis.com/token' && $r['client_secret'] === 'gsecret' && $r['code_verifier'] !== '' && $r['code'] === 'abc');

    $this->actingAs($this->agent)->get('/settings/availability')->assertInertia(fn (Assert $p) => $p->where('calendar.name', 'Google Calendar')->where('calendar.account', 'jane@gmail.test'));
});

it('only lets the same person, in the same browser, finish connecting', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'x@gmail.test'])]);
    $start = $this->actingAs($this->agent)->post('/apps/google_calendar/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');

    $this->actingAs(User::factory()->create())->get($home)->assertRedirect('/apps/google_calendar')->assertSessionHasErrors('app');
    expect(Connection::query()->count())->toBe(0);
    $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state=forged')->assertStatus(400);
});

it('says plainly when the person cancels at Google', function (): void {
    $start = $this->actingAs($this->agent)->post('/apps/google_calendar/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?error=access_denied&state='.urlencode($q['state']))->headers->get('Location');
    $this->actingAs($this->agent)->get($home)->assertRedirect('/apps/google_calendar')
        ->assertSessionHasErrors(['app' => 'You didn\'t approve the connection, so nothing changed. Press Connect to try again.']);
});

it('offers only times the calendar is free, and puts each booking into it', function (): void {
    calendarConnect('google_calendar', 'google');
    Http::fake([
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => '2026-06-03T18:00:00Z', 'end' => '2026-06-03T19:00:00Z']]]]]),
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1']),
    ]);
    $labels = fn (): array => array_map(fn (CarbonImmutable $t): string => $t->format('H:i'), app(Availability::class)->slots($this->agent));
    expect($labels())->toBe(['12:00', '14:00']); // 13:00 Chicago is busy in Google

    app(Availability::class)->book($this->agent, null, CarbonImmutable::parse('2026-06-03 12:00', 'America/Chicago'), 'call', '');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/calendars/primary/events') && $r['summary'] === 'Call booked from the website: a website visitor'
        && $r['start']['dateTime'] === '2026-06-03T17:00:00Z' && $r['end']['dateTime'] === '2026-06-03T18:00:00Z' && $r->header('Authorization')[0] === 'Bearer at-1');
});

it('connects Microsoft 365 and reads busy times from Outlook', function (): void {
    calendarConnect('microsoft_calendar', 'microsoft')->assertRedirect('/apps/microsoft_calendar');
    expect(Connection::query()->sole()->label)->toBe('jane@outlook.test');
    Http::fake(['graph.microsoft.com/v1.0/me/calendarView*' => Http::response(['value' => [
        ['start' => ['dateTime' => '2026-06-03T17:00:00'], 'end' => ['dateTime' => '2026-06-03T18:00:00'], 'showAs' => 'busy'],
        ['start' => ['dateTime' => '2026-06-03T19:00:00'], 'end' => ['dateTime' => '2026-06-03T20:00:00'], 'showAs' => 'free'],
    ]])]);
    expect(array_map(fn (CarbonImmutable $t): string => $t->format('H:i'), app(Availability::class)->slots($this->agent)))->toBe(['13:00', '14:00']);
});

it('refreshes an expired sign-in, and asks to connect again when Google refuses', function (): void {
    calendarConnect('google_calendar', 'google', ['access_token' => 'old', 'refresh_token' => 'rt-1', 'expires_in' => 60]);
    $this->travel(5)->minutes();
    Http::swap(new Factory);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]])]);
    app(Availability::class)->slots($this->agent);
    $c = Connection::query()->sole();
    expect($c->credentials['access_token'])->toBe('fresh')->and($c->credentials['refresh_token'])->toBe('rt-1');

    $this->travel(2)->hours();
    Http::swap(new Factory);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    expect(app(Availability::class)->slots($this->agent))->not->toBe([]); // booking still works from RightAlly tasks
    expect($c->refresh()->status)->toBe('needs_reconnect');
    Notification::assertSentTo($this->agent, ConnectionNeedsAttention::class);
    $this->actingAs($this->agent)->get('/settings/availability')->assertInertia(fn (Assert $p) => $p->where('calendar.status', 'needs_reconnect'));
});

it('keeps offering times when the calendar can\'t be reached', function (): void {
    calendarConnect('google_calendar', 'google');
    Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response('down', 503)]);
    expect(count(app(Availability::class)->slots($this->agent)))->toBe(3);
    expect(Connection::query()->sole()->failures)->toBe(1);
});

it('tells Google to forget the sign-in on disconnect, and shows "not ready" until HQ sets it up', function (): void {
    calendarConnect('google_calendar', 'google');
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response('')]);
    $c = Connection::query()->sole();
    $this->actingAs($this->agent)->delete('/connections/'.$c->ulid)->assertSessionHasNoErrors();
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'rt-1');

    config(['services.google.client_id' => '']);
    $this->actingAs($this->agent)->get('/apps/google_calendar')->assertInertia(fn (Assert $p) => $p->where('app.ready', false)->where('app.one_click', true));
    $this->actingAs($this->agent)->post('/apps/google_calendar/oauth')->assertSessionHasErrors('app');
});
