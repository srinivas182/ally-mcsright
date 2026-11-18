<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Notifications\SignInHelpRequested;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| SX3: "I lost my phone" — the broker confirms it is really them, then resets their sign-in.
*/

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Ann', 'two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()]);
});

it('asks the broker for help from the two-step page, once, and only after the password', function (): void {
    $this->post('/two-factor-challenge/help')->assertRedirect('/login'); // no password typed yet: nothing to ask for

    $this->withSession(['login.id' => $this->agent->id])->from('/two-factor-challenge')->post('/two-factor-challenge/help')
        ->assertRedirect('/two-factor-challenge')->assertSessionHas('status', 'sign-in-help-asked');
    $this->withSession(['login.id' => $this->agent->id])->post('/two-factor-challenge/help');

    Notification::assertSentTo($this->owner, SignInHelpRequested::class);
    Notification::assertNotSentTo($this->agent, SignInHelpRequested::class);
    expect(DB::table('sign_in_help_requests')->where('status', 'waiting')->count())->toBe(1);
    Notification::assertSentToTimes($this->owner, SignInHelpRequested::class, 1);
});

it('sends an owner on their own to RightAlly support', function (): void {
    $this->withSession(['login.id' => $this->owner->id])->post('/two-factor-challenge/help')->assertSessionHas('status', 'sign-in-help-support');
    expect(DB::table('sign_in_help_requests')->count())->toBe(0);
});

it('lets the broker reset sign-in only after ticking that they checked it is really them', function (): void {
    $this->withSession(['login.id' => $this->agent->id])->post('/two-factor-challenge/help');
    $this->actingAs($this->owner)->get('/users')->assertInertia(fn (Assert $p) => $p->where('signInHelp.0.name', $this->agent->name));
    $ulid = (string) DB::table('sign_in_help_requests')->value('ulid');

    $this->actingAs($this->owner)->post("/users/sign-in-help/{$ulid}/reset")->assertSessionHasErrors('confirmed');
    expect($this->agent->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($this->agent)->post("/users/sign-in-help/{$ulid}/reset", ['confirmed' => true])->assertForbidden(); // agents cannot manage users

    $this->actingAs($this->owner)->post("/users/sign-in-help/{$ulid}/reset", ['confirmed' => true])->assertSessionHasNoErrors();
    expect($this->agent->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sign_in_help_requests')->value('status'))->toBe('reset');
    Notification::assertSentTo($this->agent, ResetPassword::class);
    $this->actingAs($this->owner)->post("/users/sign-in-help/{$ulid}/reset", ['confirmed' => true])->assertSessionHasErrors('help'); // answered
});

it('changes nothing when the broker says it is not them, and expires a request after a day', function (): void {
    $this->withSession(['login.id' => $this->agent->id])->post('/two-factor-challenge/help');
    $ulid = (string) DB::table('sign_in_help_requests')->value('ulid');
    $this->actingAs($this->owner)->post("/users/sign-in-help/{$ulid}/decline")->assertSessionHasNoErrors();
    expect($this->agent->fresh()->two_factor_confirmed_at)->not->toBeNull()->and(DB::table('sign_in_help_requests')->value('status'))->toBe('declined');

    $this->withSession(['login.id' => $this->agent->id])->post('/two-factor-challenge/help');
    $this->travel(25)->hours();
    $this->actingAs($this->owner)->get('/users')->assertInertia(fn (Assert $p) => $p->where('signInHelp', []));
});

it('keeps another brokerage out of the request', function (): void {
    $this->withSession(['login.id' => $this->agent->id])->post('/two-factor-challenge/help');
    $ulid = (string) DB::table('sign_in_help_requests')->value('ulid');
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->useTenantHost($other);
    $theirOwner = app(TenantContext::class)->runAs($other, fn () => User::factory()->owner()->create());

    $this->actingAs($theirOwner)->post("/users/sign-in-help/{$ulid}/reset", ['confirmed' => true])->assertSessionHasErrors('help');
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => $this->agent->fresh()->two_factor_confirmed_at))->not->toBeNull();
});
