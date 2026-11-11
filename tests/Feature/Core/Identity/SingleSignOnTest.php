<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Core\Identity\Models\UserIdentity;
use Modules\Core\Identity\Notifications\ConfirmNewEmail;
use Modules\Core\Identity\Notifications\EmailChanged;
use Modules\Core\Identity\Notifications\EmailChangeRequested;
use Modules\Core\Tenancy\Models\Tenant;

beforeEach(function (): void {
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gs', 'services.microsoft.client_id' => 'mid', 'services.microsoft.client_secret' => 'ms']);
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create(['email' => 'pat@acme.test']);
    Notification::fake();
});

/** Simulates the provider: returns $remote after the round trip. */
function fakeProvider(string $provider, string $id, string $email, array $raw = []): void
{
    $remote = (new SocialiteUser)->setRaw($raw)->map(['id' => $id, 'email' => $email]);
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('stateless', 'redirectUrl', 'with')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect()->away("https://{$provider}.example/authorize"));
    $driver->shouldReceive('user')->andReturn($remote);
    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

/** Runs the full hand-off and returns the final response on the brokerage host. */
function ssoRoundTrip(string $provider, string $intent = 'login'): TestResponse
{
    $start = test()->get("/auth/{$provider}/start".($intent === 'link' ? '?intent=link' : ''));
    $central = $start->headers->get('Location');
    expect($central)->toStartWith("https://auth.rightally.app/oauth/{$provider}/redirect?state=");
    parse_str((string) parse_url((string) $central, PHP_URL_QUERY), $q);

    test()->get($central)->assertRedirect("https://{$provider}.example/authorize");
    $back = test()->get("https://auth.rightally.app/oauth/{$provider}/callback?state=".urlencode($q['state']).'&code=abc');
    $ticketUrl = (string) $back->headers->get('Location');
    expect($ticketUrl)->toContain('/auth/complete?ticket=');

    return test()->get($ticketUrl);
}

it('signs in with Google by verified email the first time, then by Google\'s permanent ID', function (): void {
    fakeProvider('google', 'g-123', 'pat@acme.test', ['email_verified' => true]);

    ssoRoundTrip('google')->assertRedirect('/agent');
    $this->assertAuthenticatedAs($this->agent);
    expect(UserIdentity::query()->sole()->subject)->toBe('g-123');

    auth()->logout();
    $this->agent->forceFill(['email' => 'pat.new@acme.test'])->save();
    fakeProvider('google', 'g-123', 'whatever@gmail.com', ['email_verified' => false]);
    ssoRoundTrip('google')->assertRedirect('/agent');
    $this->assertAuthenticatedAs($this->agent);
});

it('never matches by an unverified Google email or by a Microsoft email (nOAuth)', function (): void {
    fakeProvider('google', 'g-999', 'pat@acme.test', ['email_verified' => false]);
    ssoRoundTrip('google')->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();

    fakeProvider('microsoft', 'm-1', 'pat@acme.test');
    ssoRoundTrip('microsoft')->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('signs in with Microsoft after connecting it once from security settings', function (): void {
    fakeProvider('microsoft', 'm-777', 'someone@contoso.test');
    $this->actingAs($this->agent);
    $r = ssoRoundTrip('microsoft', 'link');
    $r->assertRedirect('/settings/security');
    expect(UserIdentity::query()->where('provider', 'microsoft')->value('subject'))->toBe('m-777');

    auth()->logout();
    ssoRoundTrip('microsoft')->assertRedirect('/agent');
    $this->assertAuthenticatedAs($this->agent);

    $this->actingAs($this->agent)->delete('/settings/identities/microsoft');
    expect(UserIdentity::query()->count())->toBe(0);
});

it('still asks for two-step verification', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'boss@acme.test']);
    fakeProvider('google', 'g-boss', 'boss@acme.test', ['email_verified' => true]);

    ssoRoundTrip('google')->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    expect(session('login.id'))->toBe($owner->id);
});

it('uses single-use tickets that only work for the same browser and brokerage', function (): void {
    fakeProvider('google', 'g-123', 'pat@acme.test', ['email_verified' => true]);
    $start = $this->get('/auth/google/start');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $q);
    $ticketUrl = (string) $this->get('https://auth.rightally.app/oauth/google/callback?state='.urlencode($q['state']).'&code=x')->headers->get('Location');

    // Another brokerage's host cannot redeem it.
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->get(str_replace('acme.', 'other.', $ticketUrl))->assertSessionHasErrors('email');
    $this->assertGuest();

    // A different browser (no matching nonce) cannot redeem it either.
    $this->flushSession();
    $this->get($ticketUrl)->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->get('https://auth.rightally.app/oauth/google/redirect?state=tampered')->assertStatus(400);
});

it('refuses deactivated users and hides buttons for providers that are not set up', function (): void {
    $this->agent->forceFill(['status' => 'deactivated'])->save();
    fakeProvider('google', 'g-123', 'pat@acme.test', ['email_verified' => true]);
    ssoRoundTrip('google')->assertSessionHasErrors('email');
    $this->assertGuest();

    config(['services.microsoft.client_id' => null]);
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('ssoProviders', ['google']));
    $this->get('/auth/microsoft/start')->assertRedirect('/login');
});

it('changes the sign-in email only after the new address is confirmed, telling the old one', function (): void {
    $this->actingAs($this->agent)->patch('/settings/profile', ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat.new@acme.test'])->assertSessionHasNoErrors();

    expect($this->agent->fresh()?->email)->toBe('pat@acme.test')->and($this->agent->fresh()?->pending_email)->toBe('pat.new@acme.test');
    Notification::assertSentTo($this->agent, EmailChangeRequested::class);
    $url = null;
    Notification::assertSentOnDemand(ConfirmNewEmail::class, function (ConfirmNewEmail $n, array $channels, object $notifiable) use (&$url): bool {
        $url = $n->url;

        return $notifiable->routes['mail'] === 'pat.new@acme.test';
    });

    $this->actingAs($this->agent->fresh())->get($url)->assertRedirect('/settings/profile');
    expect($this->agent->fresh()?->email)->toBe('pat.new@acme.test');
    Notification::assertSentOnDemand(EmailChanged::class, fn ($n, $c, object $notifiable): bool => $notifiable->routes['mail'] === 'pat@acme.test');

    User::factory()->create(['email' => 'taken@acme.test']);
    $this->actingAs($this->agent->fresh())->patch('/settings/profile', ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'taken@acme.test'])->assertSessionHasErrors('email');
    $this->actingAs($this->agent->fresh())->get(URL::temporarySignedRoute('email-change.confirm', now()->addHour(), ['token' => 'wrong']))->assertSessionHasErrors('email');
});
