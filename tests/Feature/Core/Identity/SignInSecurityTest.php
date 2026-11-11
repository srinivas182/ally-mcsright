<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Models\TrustedDevice;
use Modules\Core\Identity\Notifications\NewSignInNotification;
use Modules\Core\Identity\Notifications\SecurityResetNotification;
use Modules\Core\Identity\Security\TrustedDevices;
use PragmaRX\Google2FA\Google2FA;

const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    Notification::fake();
});

function withTwoFactor(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['a-b'])), 'two_factor_confirmed_at' => now()])->save();

    return $secret;
}

function addSession(User $user, string $id, string $agent = CHROME_WIN): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
}

it('sends owners without a second factor to set one up', function (): void {
    $owner = User::factory()->owner()->withoutTwoFactor()->create();

    $this->actingAs($owner)->get('/users')->assertRedirect('/settings/security');
    // The security page asks for the password first (existing protection); confirm it.
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', true)->where('security.secondFactorMissing', true));

    withTwoFactor($owner);
    $this->actingAs($owner->fresh())->get('/users')->assertOk();
});

it('lets agents choose, unless the brokerage requires it for everyone', function (): void {
    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/agent')->assertOk();

    $this->tenant->forceFill(['require_two_factor' => true])->save();
    $this->actingAs($agent)->get('/agent')->assertRedirect('/settings/security');
});

it('trusts a device for 30 days after a successful 2FA check', function (): void {
    $owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
    $secret = withTwoFactor($owner);

    $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $response = $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret), 'remember_device' => '1']);
    $response->assertRedirect('/dashboard');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === TrustedDevices::COOKIE);
    expect($cookie)->not->toBeNull()->and(TrustedDevice::query()->count())->toBe(1);

    auth()->logout();
    $this->withUnencryptedCookie(TrustedDevices::COOKIE, $cookie->getValue())
        ->post('/login', ['email' => 'owner@acme.test', 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($owner);

    auth()->logout();
    $this->travel(31)->days();
    $this->withUnencryptedCookie(TrustedDevices::COOKIE, $cookie->getValue())
        ->post('/login', ['email' => 'owner@acme.test', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
});

it('emails an alert for a sign-in from a new device, with a link to secure the account', function (): void {
    $agent = User::factory()->create(['email' => 'agent@acme.test']);

    $this->withHeader('User-Agent', CHROME_WIN)->post('/login', ['email' => 'agent@acme.test', 'password' => 'password']);
    auth()->logout();
    Notification::assertNothingSentTo($agent);

    $this->withHeader('User-Agent', CHROME_WIN)->post('/login', ['email' => 'agent@acme.test', 'password' => 'password']);
    auth()->logout();
    Notification::assertNothingSentTo($agent);

    $this->withHeader('User-Agent', SAFARI_IPHONE)->post('/login', ['email' => 'agent@acme.test', 'password' => 'password']);
    $url = null;
    Notification::assertSentTo($agent, NewSignInNotification::class, function (NewSignInNotification $n) use (&$url): bool {
        $url = $n->secureUrl;

        return $n->device === 'Safari on iPhone';
    });
    auth()->logout();

    addSession($agent, 'stolen-session');
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/account-secured'));
    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0);
    Notification::assertSentTo($agent, ResetPassword::class);

    $this->get(URL::route('security.secure-account', ['user' => $agent->ulid]))->assertForbidden();
});

it('lists sessions and signs out other devices with the password', function (): void {
    config(['session.driver' => 'database']); // as in production
    $agent = User::factory()->create();
    addSession($agent, 'phone-session', SAFARI_IPHONE);

    $this->actingAs($agent)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')->assertInertia(fn (Assert $page) => $page
        ->where('sessions.0.label', 'Safari on iPhone'));

    $this->actingAs($agent)->post('/settings/sessions/sign-out-others', ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->actingAs($agent)->post('/settings/sessions/sign-out-others', ['password' => 'password'])->assertSessionHasNoErrors();
    expect(DB::table('sessions')->where('id', 'phone-session')->exists())->toBeFalse();
});

it('signs out other devices when the password changes', function (): void {
    $agent = User::factory()->create();
    addSession($agent, 'old-laptop');

    $this->actingAs($agent)->put('/settings/password', [
        'current_password' => 'password', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'old-laptop')->exists())->toBeFalse();
});

it('lets an owner reset a locked-out user\'s sign-in security, but not their own', function (): void {
    $owner = User::factory()->owner()->create();
    withTwoFactor($owner);
    $agent = User::factory()->create();
    withTwoFactor($agent);
    addSession($agent, 'agent-session');

    $this->actingAs($owner)->post("/users/{$agent->ulid}/security-reset")->assertSessionHasNoErrors();

    $agent->refresh();
    expect($agent->two_factor_secret)->toBeNull()->and($agent->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0);
    Notification::assertSentTo($agent, SecurityResetNotification::class);
    Notification::assertSentTo($agent, ResetPassword::class);

    $this->actingAs($owner)->post("/users/{$owner->ulid}/security-reset")->assertSessionHasErrors('user');
    $this->actingAs($agent->fresh())->post("/users/{$owner->ulid}/security-reset")->assertForbidden();
});

it('lets an owner sign a user out everywhere', function (): void {
    $owner = User::factory()->owner()->create();
    withTwoFactor($owner);
    $agent = User::factory()->create();
    addSession($agent, 'lost-phone', SAFARI_IPHONE);
    TrustedDevice::query()->create(['user_id' => $agent->id, 'token_hash' => str_repeat('a', 64), 'label' => 'x', 'expires_at' => now()->addDays(5)]);

    $this->actingAs($owner)->post("/users/{$agent->ulid}/sign-out-everywhere")->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(TrustedDevice::query()->where('user_id', $agent->id)->count())->toBe(0);
});

it('offers a passkey until the user sets one up or says not now', function (): void {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get('/agent')->assertInertia(fn (Assert $page) => $page->where('security.passkeyPrompt', true));
    $this->actingAs($agent)->post('/security/passkey-prompt/dismiss');
    $this->actingAs($agent->fresh())->get('/agent')->assertInertia(fn (Assert $page) => $page->where('security.passkeyPrompt', false));

    $this->travel(31)->days();
    $this->actingAs($agent->fresh())->get('/agent')->assertInertia(fn (Assert $page) => $page->where('security.passkeyPrompt', true));
});
