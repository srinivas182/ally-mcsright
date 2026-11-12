<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\Office;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Access\Team;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\ContactVisibility;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create(['first_name' => 'Sue']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->roles = app(Roles::class);
    $this->roles->all();
});

function role(string $key): Role
{
    return Role::query()->where('template_key', $key)->firstOrFail();
}

it('changes nobody\'s access until roles are assigned', function (): void {
    expect($this->owner->can('finance.ledger.view'))->toBeTrue()
        ->and($this->staff->can('finance.ledger.view'))->toBeFalse()
        ->and($this->staff->can('core.users.invite'))->toBeFalse()
        ->and($this->agent->can('core.audit.view'))->toBeFalse();

    $this->actingAs($this->owner)->get('/settings/roles')->assertInertia(fn (Assert $page) => $page
        ->component('settings/roles')->where('roles.0.name', 'Owner')->where('roles.0.locked', true)
        ->where('roles', fn ($roles) => collect($roles)->pluck('name')->contains('Bookkeeper (read-only)')));
    $this->actingAs($this->agent)->get('/settings/roles')->assertForbidden();
    expect(fn () => $this->roles->save(role('owner'), 'Owner', null, [], $this->owner))->toThrow(ValidationException::class);
});

it('lets a read-only bookkeeper see and export the books but not post or close periods', function (): void {
    $this->actingAs($this->owner)->post("/users/{$this->staff->ulid}/roles", ['role' => role('bookkeeper')->ulid])->assertSessionHasNoErrors();
    app(Permissions::class)->forget();

    $staff = $this->staff->fresh();
    expect($staff->can('finance.ledger.view'))->toBeTrue()->and($staff->can('finance.ledger.export'))->toBeTrue()
        ->and($staff->can('finance.ledger.adjust'))->toBeFalse()->and($staff->can('finance.periods.close'))->toBeFalse();

    // Finance access requires two-step verification (sign-in security rules) ...
    $this->actingAs($staff)->get('/finance/accounts')->assertRedirect('/settings/security');
    // ... and once it is set up, the books open.
    $staff->forceFill(User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->save();
    $this->actingAs($staff->fresh())->get('/finance/accounts')->assertOk();
    $this->actingAs($staff->fresh())->get('/contacts')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($nav) => collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->contains('Chart of accounts')));
});

it('widens company-contact access by team and office, never to anyone\'s personal sphere', function (): void {
    $kim = User::factory()->create(['first_name' => 'Kim']);
    $lee = User::factory()->create(['first_name' => 'Lee']);
    $team = Team::query()->create(['name' => 'Smith Team', 'leader_user_id' => $this->agent->id]);
    DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'user_id' => $kim->id, 'joined_at' => now()]);

    $contacts = app(ManageContacts::class);
    $kimsLead = $contacts->create(['first_name' => 'TeamLead', 'email' => 't@x.test'], $this->owner);
    $contacts->assignOwner($kimsLead, $kim, $this->owner);
    $leesLead = $contacts->create(['first_name' => 'OtherLead', 'email' => 'o@x.test'], $this->owner);
    $contacts->assignOwner($leesLead, $lee, $this->owner);
    $kimsFriend = $contacts->create(['first_name' => 'KimsFriend', 'email' => 'f@x.test'], $kim);

    $this->roles->assign($this->agent, role('team_lead'), $this->owner);
    $visible = app(ContactVisibility::class)->query($this->agent->fresh())->pluck('first_name')->all();
    expect($visible)->toContain('TeamLead')->not->toContain('OtherLead')->not->toContain('KimsFriend');

    $contacts->assignOwner($kimsLead->fresh(), $this->agent, $this->agent->fresh());
    expect($kimsLead->fresh()?->owner_user_id)->toBe($this->agent->id)
        ->and(fn () => $contacts->assignOwner($leesLead->fresh(), $this->agent, $this->agent->fresh()))->toThrow(ValidationException::class);

    $office = Office::query()->create(['name' => 'Downtown']);
    foreach ([$this->staff, $lee] as $u) {
        DB::table('user_offices')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'office_id' => $office->id, 'is_primary' => true]);
    }
    $officeRole = $this->roles->save(null, 'Office viewer', null, ['crm.contacts.view' => 'office'], $this->owner);
    $this->roles->assign($lee, $officeRole, $this->owner);
    app(Permissions::class)->forget();
    expect(app(ContactVisibility::class)->query($lee->fresh())->pluck('first_name')->all())->toContain('OtherLead')->not->toContain('TeamLead');
});

it('ends temporary access on time and records the removal', function (): void {
    $this->actingAs($this->owner)->post("/users/{$this->staff->ulid}/roles", [
        'role' => role('bookkeeper')->ulid, 'expires_at' => now()->addDay()->toDateString(), 'reason' => 'Covering month-end',
    ])->assertSessionHasNoErrors();
    expect($this->staff->fresh()->can('finance.ledger.view'))->toBeTrue();

    $this->travel(3)->days();
    app(Permissions::class)->forget();
    expect($this->staff->fresh()->can('finance.ledger.view'))->toBeFalse();

    $this->artisan('core:expire-access')->assertSuccessful();
    (new FlushAuditOutbox)->handle();
    expect(DB::table('user_roles')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'core.role.revoked')->where('automation', 'access_expiry')->exists())->toBeTrue();
});

it('enforces separation of duties in roles, across roles, and on every check', function (): void {
    expect(fn () => $this->roles->save(null, 'Payments', null, ['finance.bank_details.manage' => null, 'finance.payouts.approve' => null], $this->owner))
        ->toThrow(ValidationException::class, 'separation of duties');

    $bank = $this->roles->save(null, 'Bank details', null, ['finance.bank_details.manage' => null], $this->owner);
    $approve = $this->roles->save(null, 'Approver', null, ['finance.payouts.approve' => null], $this->owner);
    $this->roles->assign($this->staff, $bank, $this->owner);
    expect(fn () => $this->roles->assign($this->staff, $approve, $this->owner))->toThrow(ValidationException::class);

    DB::table('user_roles')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $this->staff->id, 'role_id' => $approve->id, 'granted_at' => now()]);
    app(Permissions::class)->forget();
    expect($this->staff->fresh()->can('finance.bank_details.manage'))->toBeTrue()
        ->and($this->staff->fresh()->can('finance.payouts.approve'))->toBeFalse();
});

it('previews a role in plain English and records role changes', function (): void {
    $this->actingAs($this->owner)->postJson('/settings/roles/preview', ['permissions' => ['crm.contacts.view' => 'team', 'core.users.view' => null]])
        ->assertOk()
        ->assertJsonFragment(['Can see company contacts for their team.'])
        ->assertJsonFragment(['Cannot see commission amounts and earnings.']);

    $this->actingAs($this->owner)->post('/settings/roles', ['name' => 'Listing coordinator', 'permissions' => ['crm.import' => null]])->assertSessionHasNoErrors();
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'core.role.saved')->where('subject_label', 'Listing coordinator')->exists())->toBeTrue();
});

it('lets a custom role manage CRM settings', function (): void {
    $crm = $this->roles->save(null, 'CRM admin', null, ['crm.settings.manage' => null], $this->owner);
    $this->actingAs($this->agent)->post('/contacts/pond/settings', ['enabled' => true, 'after_days' => 3, 'daily_claims' => 5])->assertSessionHasErrors('pond');

    $this->roles->assign($this->agent, $crm, $this->owner);
    app(Permissions::class)->forget();
    $this->actingAs($this->agent->fresh())->post('/contacts/pond/settings', ['enabled' => true, 'after_days' => 3, 'daily_claims' => 5])->assertSessionHasNoErrors();
});
