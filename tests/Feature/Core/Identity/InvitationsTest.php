<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Identity\Notifications\InvitationNotification;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'owner@acme.test']);
});

/** Invite through the screen and return the raw token from the email. */
function inviteAndGetToken(string $email = 'New.Agent@Example.com', string $kind = 'agent', array $extra = []): string
{
    test()->actingAs(test()->owner)->post('/users/invitations', array_merge([
        'email' => $email, 'first_name' => 'Nia', 'last_name' => 'Agent', 'kind' => $kind,
    ], $extra))->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n) use (&$token): bool {
        $token = basename($n->url);

        return true;
    });
    auth()->logout();

    return (string) $token;
}

it('invites by email, stores only a hash, and sends a link on the brokerage address', function (): void {
    $token = inviteAndGetToken();

    $invitation = Invitation::query()->sole();
    expect($invitation->email)->toBe('new.agent@example.com')
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->expires_at->diffInDays(now(), true))->toBeGreaterThan(6.9);

    Notification::assertSentOnDemand(InvitationNotification::class, fn (InvitationNotification $n) => str_starts_with($n->url, 'https://acme.rightally.app/invitations/'));
});

it('accepts an invitation: active, verified, signed in and on the right page', function (): void {
    $token = inviteAndGetToken();

    $this->get('/invitations/'.$token)->assertInertia(fn (Assert $page) => $page
        ->component('auth/accept-invitation')->where('email', 'new.agent@example.com'));

    $this->post('/invitations/'.$token, [
        'first_name' => 'Nia', 'last_name' => 'Agent',
        'password' => 'a-long-secure-password', 'password_confirmation' => 'a-long-secure-password',
    ])->assertRedirect('/agent');

    $user = User::query()->where('email', 'new.agent@example.com')->sole();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->kind)->toBe(UserKind::Agent)
        ->and($user->created_by)->toBe($this->owner->id);
    $this->assertAuthenticatedAs($user);
});

it('works once only', function (): void {
    $token = inviteAndGetToken();
    $accept = ['first_name' => 'Nia', 'last_name' => 'Agent', 'password' => 'a-long-secure-password', 'password_confirmation' => 'a-long-secure-password'];

    $this->post('/invitations/'.$token, $accept);
    auth()->logout();

    $this->get('/invitations/'.$token)->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
    expect(User::query()->where('email', 'new.agent@example.com')->count())->toBe(1);
});

it('expires after 7 days', function (): void {
    $token = inviteAndGetToken();
    $this->travel(8)->days();

    $this->get('/invitations/'.$token)->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
});

it('resending issues a new link and the old one stops working', function (): void {
    $old = inviteAndGetToken();
    $invitation = Invitation::query()->sole();
    Notification::fake();

    $this->actingAs($this->owner)->post("/users/invitations/{$invitation->ulid}/resend")->assertSessionHasNoErrors();
    $new = null;
    Notification::assertSentOnDemand(InvitationNotification::class, function ($n) use (&$new): bool {
        $new = basename($n->url);

        return true;
    });
    auth()->logout();

    $this->get('/invitations/'.$old)->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
    $this->get('/invitations/'.$new)->assertInertia(fn (Assert $page) => $page->component('auth/accept-invitation'));
});

it('revoked invitations stop working', function (): void {
    $token = inviteAndGetToken();
    $invitation = Invitation::query()->sole();

    $this->actingAs($this->owner)->post("/users/invitations/{$invitation->ulid}/revoke");
    auth()->logout();

    $this->get('/invitations/'.$token)->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
});

it('only works on the brokerage that sent it', function (): void {
    $token = inviteAndGetToken();
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);

    $this->get('http://other.rightally.app/invitations/'.$token)
        ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
    $this->get('http://localhost/invitations/'.$token)->assertNotFound();
});

it('refuses duplicate people, assistants without an agent, and HQ staff outside HQ', function (array $payload, string $field): void {
    $this->actingAs($this->owner)->post('/users/invitations', array_merge(
        ['first_name' => 'A', 'last_name' => 'B', 'kind' => 'agent'], $payload,
    ))->assertSessionHasErrors($field);
})->with([
    'existing user' => [['email' => 'OWNER@acme.test'], 'email'],
    'assistant without agent' => [['email' => 'a@x.test', 'kind' => 'assistant'], 'assisted_user_id'],
    'hq staff in a brokerage' => [['email' => 'h@x.test', 'kind' => 'hq_staff'], 'kind'],
]);

it('links an assistant to the agent they work for', function (): void {
    $agent = User::factory()->create();
    $token = inviteAndGetToken('assist@x.test', 'assistant', ['assisted_user_id' => $agent->id]);

    $this->post('/invitations/'.$token, [
        'first_name' => 'Sam', 'last_name' => 'Assist', 'password' => 'a-long-secure-password', 'password_confirmation' => 'a-long-secure-password',
    ])->assertRedirect('/agent');

    expect(User::query()->where('email', 'assist@x.test')->sole()->assisted_user_id)->toBe($agent->id);
});

it('rejects short and breached passwords', function (): void {
    $token = inviteAndGetToken();
    $base = ['first_name' => 'Nia', 'last_name' => 'Agent'];

    $this->post('/invitations/'.$token, $base + ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');

    config(['core.breached_password_check' => true]);
    $breached = 'a-long-but-leaked-password';
    $sha = strtoupper(sha1($breached));
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($sha, 5).':1234')]);

    $this->post('/invitations/'.$token, $base + ['password' => $breached, 'password_confirmation' => $breached])
        ->assertSessionHasErrors('password');
});

it('lets only owners manage people', function (): void {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get('/users')->assertForbidden();
    $this->actingAs($agent)->post('/users/invitations', ['email' => 'x@y.test', 'first_name' => 'X', 'last_name' => 'Y', 'kind' => 'agent'])->assertForbidden();
    $this->actingAs($this->owner)->get('/users')->assertInertia(fn (Assert $page) => $page->component('users/index')->where('can.invite', true));
});

it('invites the first HQ staff member from the bootstrap command', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);

    $this->artisan('tenants:bootstrap-hq', ['--admin-email' => 'founder@rightally.io'])->assertSuccessful();

    $hq = Tenant::query()->where('type', 'hq')->sole();
    $invitation = app(TenantContext::class)->runAs($hq, fn () => Invitation::query()->sole());
    expect($invitation->kind)->toBe(UserKind::HqStaff)->and($invitation->email)->toBe('founder@rightally.io');
    expect(ManageInvitations::VALID_DAYS)->toBe(7);
});
