<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Actions\ManageUserStatus;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Events\RepeatedLoginFailures;
use Modules\Core\Identity\Events\UserDeactivated;
use Modules\Core\Identity\Events\UserSuspended;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->acme = $this->onTenantHost('acme');
    $this->other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
});

function signIn(string $host, string $email, string $password = 'password'): TestResponse
{
    return test()->post("http://{$host}.rightally.app/login", ['email' => $email, 'password' => $password]);
}

it('treats the same email in two brokerages as two separate accounts', function (): void {
    $inAcme = User::factory()->create(['email' => 'pat@x.test', 'password' => 'acme-password-123']);
    $inOther = app(TenantContext::class)->runAs($this->other, fn () => User::factory()->create(['email' => 'pat@x.test', 'password' => 'other-password-123']));

    signIn('other', 'pat@x.test', 'acme-password-123')->assertSessionHasErrors('email');
    $this->assertGuest();

    signIn('other', 'pat@x.test', 'other-password-123');
    $this->assertAuthenticatedAs($inOther);
    expect($inAcme->id)->not->toBe($inOther->id);
});

it('lands each kind of user on their own page', function (Closure $make, string $landing): void {
    $user = $make();

    signIn('acme', $user->email)->assertRedirect($landing);
})->with([
    'owner' => [fn () => User::factory()->owner()->create(), '/dashboard'],
    'staff' => [fn () => User::factory()->staff()->create(), '/dashboard'],
    'agent' => [fn () => User::factory()->create(), '/agent'],
]);

it('lands HQ staff on the HQ console', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    $this->post('http://app.rightally.io/login', ['email' => $staff->email, 'password' => 'password'])->assertRedirect('/hq/tenants');
});

it('gives the same neutral error for unknown emails, wrong passwords and blocked users', function (): void {
    $suspended = User::factory()->status(UserStatus::Suspended)->create();
    $deactivated = User::factory()->status(UserStatus::Deactivated)->create();

    $neutral = ['email' => __('auth.failed')];

    signIn('acme', 'nobody@x.test')->assertSessionHasErrors($neutral);
    signIn('acme', $this->owner->email, 'wrong-password')->assertSessionHasErrors($neutral);
    signIn('acme', $suspended->email)->assertSessionHasErrors($neutral);
    signIn('acme', $deactivated->email)->assertSessionHasErrors($neutral);
    $this->assertGuest();
});

it('has no sign-in or registration on central addresses', function (): void {
    $this->get('http://localhost/login')->assertNotFound();
    $this->post('http://localhost/login', ['email' => 'owner@acme.test', 'password' => 'password'])->assertNotFound();
    $this->get('http://acme.rightally.app/register')->assertNotFound();
});

it('records the last sign-in time', function (): void {
    signIn('acme', $this->owner->email);

    expect($this->owner->fresh()?->last_login_at)->not->toBeNull();
});

it('raises a security alert after 20 failed sign-ins for one email in an hour', function (): void {
    Event::fake([RepeatedLoginFailures::class]);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 20) as $attempt) {
        signIn('acme', $this->owner->email, 'wrong-'.$attempt);
    }

    Event::assertDispatchedTimes(RepeatedLoginFailures::class, 1);
    Event::assertDispatched(RepeatedLoginFailures::class, fn ($e) => $e->emailHash === hash('sha256', 'owner@acme.test'));
});

it('signs out a user deactivated while signed in', function (): void {
    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/agent')->assertOk();

    $this->actingAs($this->owner)->post("/users/{$agent->ulid}/deactivate")->assertSessionHasNoErrors();

    $this->actingAs($agent->fresh())->get('/agent')->assertRedirect('/login');
    $this->assertGuest();
});

it('suspends, deactivates and reactivates with the right rules', function (): void {
    Event::fake([UserSuspended::class, UserDeactivated::class]);
    $agent = User::factory()->create();

    $this->actingAs($this->owner)->post("/users/{$agent->ulid}/suspend");
    expect($agent->fresh()?->status)->toBe(UserStatus::Suspended);

    $this->actingAs($this->owner)->post("/users/{$agent->ulid}/reactivate");
    expect($agent->fresh()?->status)->toBe(UserStatus::Active);

    $this->actingAs($this->owner)->post("/users/{$this->owner->ulid}/suspend")->assertSessionHasErrors('user');
    Event::assertDispatched(UserSuspended::class);
});

it('protects the last active owner', function (): void {
    $staff = User::factory()->staff()->create();
    $status = app(ManageUserStatus::class);

    expect(fn () => $status->deactivate($this->owner, $staff))->toThrow(ValidationException::class)
        ->and(fn () => $status->suspend($this->owner, $staff))->toThrow(ValidationException::class);

    $second = User::factory()->owner()->create();
    $status->deactivate($this->owner, $second);
    expect($this->owner->fresh()?->status)->toBe(UserStatus::Deactivated);
});

it('suspends an agent\'s assistants with them', function (): void {
    $agent = User::factory()->create();
    $assistant = User::factory()->assistantOf($agent)->create();

    $this->actingAs($this->owner)->post("/users/{$agent->ulid}/deactivate");

    expect($assistant->fresh()?->status)->toBe(UserStatus::Suspended);
});

it('keeps password-reset links separate per brokerage', function (): void {
    Notification::fake();
    $acmePat = User::factory()->create(['email' => 'pat@x.test']);
    $otherPat = app(TenantContext::class)->runAs($this->other, fn () => User::factory()->create(['email' => 'pat@x.test']));

    $this->post('http://acme.rightally.app/forgot-password', ['email' => 'pat@x.test']);
    $this->post('http://other.rightally.app/forgot-password', ['email' => 'pat@x.test']);

    expect(DB::table('password_reset_tokens')->pluck('email')->sort()->values()->all())
        ->toBe(collect([$acmePat->tenant_id.'|pat@x.test', $otherPat->tenant_id.'|pat@x.test'])->sort()->values()->all());

    $token = null;
    Notification::assertSentTo($acmePat, ResetPassword::class, function ($n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    $this->post('http://acme.rightally.app/reset-password', [
        'token' => $token, 'email' => 'pat@x.test', 'password' => 'brand-new-password-1', 'password_confirmation' => 'brand-new-password-1',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('brand-new-password-1', (string) $acmePat->fresh()?->password))->toBeTrue();
    $otherPassword = app(TenantContext::class)->runAs($this->other, fn () => $otherPat->fresh()?->password);
    expect(Hash::check('brand-new-password-1', (string) $otherPassword))->toBeFalse();
});
