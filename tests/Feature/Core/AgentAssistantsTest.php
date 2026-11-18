<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Identity\Models\Invitation;
use Modules\Core\Support\Connections;

/*
| AG1 (specs/grow/03): agents add their own assistants, with a preset for what they may open (never the agent's
| money); the brokerage can require approval first.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
});

it('lets an agent invite their own assistant with a preset', function (): void {
    $this->actingAs($this->ann)->post('/my-assistants', ['email' => 'Sam@Example.test', 'first_name' => 'Sam', 'last_name' => 'Helper', 'preset' => 'leads'])->assertSessionHasNoErrors();
    $invite = Invitation::query()->where('email', 'sam@example.test')->sole();
    expect($invite->assisted_user_id)->toBe($this->ann->id)
        ->and(DB::connection(Connections::cell())->table('assistant_access')->value('preset'))->toBe('leads');
    $this->actingAs($this->ann)->get('/my-assistants')->assertInertia(fn (Assert $p) => $p->component('settings/my-assistants')->where('waiting.0.email', 'sam@example.test'));

    $this->actingAs($this->owner)->get('/my-assistants')->assertForbidden();
    $this->actingAs($this->ann)->post('/my-assistants', ['email' => 'x@example.test', 'first_name' => 'X', 'last_name' => 'Y', 'preset' => 'everything'])->assertSessionHasErrors('preset');
});

it('waits for approval when the owner requires it', function (): void {
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/approvals/rules', ['action' => 'agents.add_assistant', 'needed' => 1]);
    $this->actingAs($this->ann)->post('/my-assistants', ['email' => 'sam@example.test', 'first_name' => 'Sam', 'last_name' => 'Helper', 'preset' => 'full'])->assertSessionHasNoErrors();
    expect(Invitation::query()->where('email', 'sam@example.test')->exists())->toBeFalse();

    $ulid = (string) DB::connection(Connections::cell())->table('approval_requests')->value('ulid');
    $this->actingAs($this->owner)->post('/settings/approvals/'.$ulid, ['approve' => true])->assertSessionHasNoErrors();
    expect(Invitation::query()->where('email', 'sam@example.test')->sole()->assisted_user_id)->toBe($this->ann->id);
});

it('opens only what the preset allows, never the agent\'s money, and the agent can change or remove them', function (): void {
    $sam = User::factory()->assistantOf($this->ann)->create(['first_name' => 'Sam', 'email' => 'sam@example.test']);
    $this->actingAs($sam)->get('/my-money')->assertForbidden(); // full: no money
    $this->actingAs($sam)->get('/contacts')->assertOk();

    $this->actingAs($this->ann)->post('/my-assistants/'.$sam->ulid, ['preset' => 'leads'])->assertSessionHasNoErrors();
    $this->actingAs($sam)->get('/deals')->assertForbidden();
    $this->actingAs($sam)->get('/agent')->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($groups): bool => ! collect($groups)->flatMap(fn ($g) => $g['items'])->contains('href', '/deals')));

    $this->actingAs($this->ann)->post('/my-assistants/'.$sam->ulid, ['preset' => 'admin']);
    $this->actingAs($sam)->get('/deals')->assertOk();

    $this->actingAs($this->bob)->post('/my-assistants/'.$sam->ulid, ['preset' => 'full'])->assertSessionHasErrors('assistant'); // not Bob's
    $this->actingAs($this->ann)->withSession(['auth.password_confirmed_at' => time()])->post('/my-assistants/'.$sam->ulid.'/remove')->assertSessionHasNoErrors();
    expect($sam->fresh()->status)->toBe(UserStatus::Deactivated);
});

it('allows up to three assistants', function (): void {
    foreach (range(1, 3) as $n) {
        User::factory()->assistantOf($this->ann)->create();
    }
    $this->actingAs($this->ann)->post('/my-assistants', ['email' => 'four@example.test', 'first_name' => 'Four', 'last_name' => 'Th', 'preset' => 'full'])->assertSessionHasErrors('email');
});
