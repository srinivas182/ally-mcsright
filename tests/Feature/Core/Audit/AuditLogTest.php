<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Audit\AuditEvent;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\LeadPond;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Maria']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
});

function events(): Collection
{
    return DB::table('audit_events')->orderBy('tenant_id')->orderBy('chain_seq')->get();
}

it('seals every change into a gapless, verifiable chain, masking sensitive values and never storing session IDs', function (): void {
    $this->actingAs($this->agent);
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'phone' => '6015550123'], $this->agent);
    app(ManageContacts::class)->update($sam, ['lifecycle' => 'active'], $this->agent);
    $this->get("/contacts/{$sam->ulid}")->assertOk();

    $rows = events()->where('tenant_id', $this->tenant->id)->values();
    expect($rows->pluck('chain_seq')->all())->toBe(range(1, $rows->count()))
        ->and($rows->pluck('action')->all())->toContain('crm.contact.created', 'crm.contact.updated', 'crm.contact.viewed');

    $created = $rows->firstWhere('action', 'crm.contact.created');
    $changes = json_decode((string) $created->changes, true);
    expect($changes['primary_phone']['new'])->toBe('***0123')
        ->and($created->actor_name)->toBe($this->agent->name);

    $viewed = $rows->firstWhere('action', 'crm.contact.viewed');
    expect($viewed->session_ref)->toMatch('/^[0-9a-f]{64}$/')
        ->and(json_encode($rows))->not->toContain(session()->getId());

    $this->artisan('audit:verify', ['tenant' => 'acme'])->expectsOutputToContain('Audit log intact')->assertSuccessful();
    expect(DB::table('audit_outbox')->count())->toBe(0);
});

it('detects an altered or missing event, and the application cannot change events', function (): void {
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->agent);
    app(ManageContacts::class)->create(['first_name' => 'Kim', 'email' => 'k@x.test'], $this->agent);

    $event = AuditEvent::query()->orderBy('chain_seq')->firstOrFail();
    expect(fn () => $event->forceFill(['action' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    DB::table('audit_events')->where('id', $event->id)->update(['subject_label' => 'Someone else']);
    $this->artisan('audit:verify', ['tenant' => 'acme'])->expectsOutputToContain('Broken at sequence 1')->assertFailed();

    DB::table('audit_events')->where('id', $event->id)->delete();
    $this->artisan('audit:verify', ['tenant' => 'acme'])->expectsOutputToContain('missing without a retention record')->assertFailed();
});

it('writes nothing when the change itself is rolled back', function (): void {
    $before = DB::table('audit_events')->count();
    try {
        DB::transaction(function (): void {
            app(ManageContacts::class)->create(['first_name' => 'Ghost', 'email' => 'g@x.test'], $this->agent);
            throw new RuntimeException('cancel');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('audit_events')->count())->toBe($before)->and(DB::table('audit_outbox')->count())->toBe(0);
});

it('records automation acting on someone\'s behalf', function (): void {
    $plans = app(FollowUpPlans::class);
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->agent);
    $plans->enroll($sam, $plans->plansFor($this->agent)->firstWhere('name', 'Open house follow-up'), $this->agent);

    $lead = app(ManageContacts::class)->create(['first_name' => 'Lee', 'email' => 'lee@x.test'], $this->owner);
    app(ManageContacts::class)->assignOwner($lead, $this->agent, $this->owner);
    $this->travel(3)->days();
    $this->tenant->forceFill(['crm_pond_enabled' => true, 'crm_pond_after_days' => 2])->save();
    app(TenantContext::class)->set($this->tenant->fresh());
    app(LeadPond::class)->sweep();

    $plan = events()->firstWhere('automation', 'follow_up_plan');
    $pond = events()->first(fn ($e) => $e->automation === 'lead_pond' && $e->action === 'crm.contact.updated');
    expect($plan->action)->toBe('crm.task.created')->and((int) $plan->on_behalf_of_user_id)->toBe($this->agent->id)
        ->and($plan->automation_scope)->toBe('Plan: Open house follow-up')->and($plan->actor_id)->toBeNull()
        ->and((int) $pond->on_behalf_of_user_id)->toBe($this->agent->id);
});

it('records sign-ins, failed sign-ins and log in as', function (): void {
    $this->post('/login', ['email' => $this->agent->email, 'password' => 'wrong']);
    $this->post('/login', ['email' => $this->agent->email, 'password' => 'password']);
    $this->actingAs($this->owner)->post("/users/{$this->agent->ulid}/impersonate", ['reason' => 'Helping with a missing commission']);

    $failed = events()->firstWhere('action', 'core.auth.sign_in_failed');
    expect(json_decode((string) $failed->metadata, true)['email'])->toStartWith('***')
        ->and(events()->firstWhere('action', 'core.auth.signed_in'))->not->toBeNull();
    $started = events()->firstWhere('action', 'core.impersonation.started');
    expect($started->severity)->toBe('warning')->and(json_decode((string) $started->metadata, true)['reason'])->toBe('Helping with a missing commission');
});

it('keeps each brokerage\'s log and chain separate, and shows it to owners only', function (): void {
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    app(TenantContext::class)->runAs($other, fn () => app(ManageContacts::class)->create(['first_name' => 'Zed', 'email' => 'z@x.test'],
        User::factory()->create()));
    // A company contact (owners cannot see agents' personal contacts).
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->owner);

    expect(events()->where('tenant_id', $other->id)->min('chain_seq'))->toBe(1);
    $this->artisan('audit:verify', ['tenant' => 'other'])->assertSuccessful();

    $this->actingAs($this->owner)->get('/audit?area=crm.contact&view=technical')->assertInertia(fn (Assert $page) => $page
        ->component('audit/index')
        ->where('rows', fn ($rows) => collect($rows)->pluck('subject')->contains('Sam') && ! collect($rows)->pluck('subject')->contains('Zed')));
    $this->actingAs($this->agent)->get('/audit')->assertForbidden();

    app(Timeline::class)->log($sam, ['type' => 'note', 'body' => 'x'], $this->owner);
    $this->actingAs($this->owner)->get("/contacts/{$sam->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('history.0.what', 'viewed contact')->where('history.0.who', $this->owner->name));
});
