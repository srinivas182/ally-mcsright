<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Events\ImpersonationStarted;
use Modules\Core\Identity\Events\ImpersonationStopped;
use Modules\Core\Identity\Notifications\AccountViewed;
use Modules\Core\Identity\Notifications\NewSignInNotification;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Maria']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    Notification::fake();
});

function viewAs(User $owner, User $target, string $reason = 'Helping with the missing commission on 14 Oak St')
{
    return test()->actingAs($owner)->post("/users/{$target->ulid}/impersonate", ['reason' => $reason]);
}

it('lets an owner view an agent\'s account with a reason, telling the agent, without sign-in side effects', function (): void {
    Event::fake([ImpersonationStarted::class]);
    $lastLogin = $this->agent->last_login_at;

    viewAs($this->owner, $this->agent, 'short')->assertSessionHasErrors('reason');
    viewAs($this->owner, $this->agent)->assertRedirect('/');

    $this->assertAuthenticatedAs($this->agent);
    Notification::assertSentTo($this->agent, AccountViewed::class, fn (AccountViewed $n): bool => str_contains($n->reason, '14 Oak St'));
    Notification::assertNotSentTo($this->agent, NewSignInNotification::class);
    Event::assertDispatched(ImpersonationStarted::class, fn ($e): bool => $e->byKind === 'owner' && $e->targetUserId === $this->agent->id);
    expect($this->agent->fresh()?->last_login_at)->toEqual($lastLogin);

    $this->get('/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('impersonation.target', $this->agent->name));
});

it('locks passwords, security, email, user management and finance changes while viewing', function (string $method, string $path): void {
    viewAs($this->owner, $this->agent);

    $this->from('/contacts')->{$method}($path, [])->assertRedirect('/contacts')->assertSessionHasErrors('impersonation');
})->with([
    'security page' => ['get', '/settings/security'],
    'password' => ['put', '/settings/password'],
    'email change' => ['patch', '/settings/profile'],
    'two-factor' => ['post', '/user/two-factor-authentication'],
    'connect Google' => ['get', '/auth/google/start'],
    'people' => ['get', '/users'],
    'finance change' => ['post', '/finance/adjustments'],
]);

it('returns the owner to their own account when they stop, and after 60 minutes', function (): void {
    Event::fake([ImpersonationStopped::class]);
    viewAs($this->owner, $this->agent);

    $this->post('/impersonate/stop')->assertRedirect('/users');
    $this->assertAuthenticatedAs($this->owner);
    Event::assertDispatched(ImpersonationStopped::class, fn ($e): bool => $e->why === 'stopped');

    viewAs($this->owner, $this->agent);
    $this->travel(61)->minutes();
    $this->get('/contacts')->assertRedirect('/users');
    $this->assertAuthenticatedAs($this->owner);
});

it('never lets owners view other owners, HQ staff or themselves, and only owners can start', function (): void {
    $otherOwner = User::factory()->owner()->create();
    $staff = User::factory()->staff()->create();

    viewAs($this->owner, $otherOwner)->assertSessionHasErrors('user');
    viewAs($this->owner, $this->owner)->assertSessionHasErrors('user');
    viewAs($staff, $this->agent)->assertSessionHasErrors('user');
    $this->assertAuthenticatedAs($staff);
});

it('lets HQ support view a brokerage user through a single-use ticket, then signs them out', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $support = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    $response = $this->actingAs($support)->post("http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/impersonate", ['email' => $this->agent->email, 'reason' => 'Checking a reported login problem'], ['X-Inertia' => 'true']);
    $url = (string) $response->headers->get('X-Inertia-Location');
    expect($url)->toStartWith('https://acme.rightally.app/impersonate/accept?ticket=');

    auth()->logout();
    $this->get($url)->assertRedirect('/');
    $this->assertAuthenticatedAs($this->agent);
    Notification::assertSentTo($this->agent, AccountViewed::class, fn (AccountViewed $n): bool => str_contains($n->byName, 'RightAlly support'));

    $this->post('/impersonate/stop');
    $this->assertGuest();

    $this->get($url)->assertRedirect('/login')->assertSessionHasErrors('email');
    // A brokerage owner has no access to the HQ console: no ticket is ever issued.
    $denied = $this->actingAs($this->owner)->post("http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/impersonate", ['email' => $this->agent->email, 'reason' => 'Not allowed for owners']);
    expect($denied->headers->get('X-Inertia-Location'))->toBeNull()->and($denied->status())->not->toBe(200);
});
