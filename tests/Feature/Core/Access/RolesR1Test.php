<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\AccessAlertMail;
use Modules\Core\Access\AccessReviewDueMail;
use Modules\Core\Access\Office;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\ContactVisibility;
use Modules\Roster\Roster;
use Modules\Sites\SiteAccess;
use Modules\Transactions\Deals;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->coOwner = User::factory()->owner()->create(['first_name' => 'Omar']);
    $this->staff = User::factory()->staff()->create(['first_name' => 'Sue']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->roles = app(Roles::class);
    $this->roles->all();
});

function r1role(string $key): Role
{
    return Role::query()->where('template_key', $key)->firstOrFail();
}

function r1fresh(User $u): User
{
    app(Permissions::class)->forget();

    return $u->fresh() ?? $u;
}

it('lets roles decide what staff can do across every area once one is assigned', function (): void {
    // No role yet: staff keep the access they started with.
    expect(SiteAccess::manager($this->staff))->toBeTrue()->and(Roster::manages($this->staff))->toBeTrue();
    $this->actingAs($this->staff)->get('/settings/website')->assertOk();

    // Office admin: roster and deals yes, website and marketing no.
    $this->roles->assign($this->staff, r1role('office_admin'), $this->owner);
    $staff = r1fresh($this->staff);
    expect(Roster::manages($staff))->toBeTrue()->and(SiteAccess::manager($staff))->toBeFalse()
        ->and(app(Permissions::class)->manages($staff, 'marketing.manage'))->toBeFalse();
    $this->actingAs($staff)->get('/settings/website')->assertForbidden();
    $this->actingAs($staff)->get('/contacts')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($nav) => ! collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->contains('Website')));

    // A marketing manager role opens the website for them.
    $this->roles->assign($staff, r1role('marketing_manager'), $this->owner);
    $this->actingAs(r1fresh($staff))->get('/settings/website')->assertOk();
});

it('limits a role-governed staff member to company contacts in their scope', function (): void {
    $contacts = app(ManageContacts::class);
    $lee = User::factory()->create(['first_name' => 'Lee']);
    $kim = User::factory()->create(['first_name' => 'Kim']);
    $office = Office::query()->create(['name' => 'Downtown']);
    foreach ([$this->staff, $lee] as $u) {
        DB::table('user_offices')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'office_id' => $office->id, 'is_primary' => true]);
    }
    $downtown = $contacts->create(['first_name' => 'Downtown', 'email' => 'd@x.test'], $this->owner);
    $contacts->assignOwner($downtown, $lee, $this->owner);
    $uptown = $contacts->create(['first_name' => 'Uptown', 'email' => 'u@x.test'], $this->owner);
    $contacts->assignOwner($uptown, $kim, $this->owner);

    expect(app(ContactVisibility::class)->query($this->staff)->pluck('first_name')->all())->toContain('Downtown')->toContain('Uptown');

    $this->roles->assign($this->staff, r1role('office_admin'), $this->owner);
    $visible = app(ContactVisibility::class)->query(r1fresh($this->staff))->pluck('first_name')->all();
    expect($visible)->toContain('Downtown')->not->toContain('Uptown');

    $this->actingAs(r1fresh($this->staff))->getJson("/contacts/{$downtown->ulid}/why")->assertOk()
        ->assertJsonFragment(['allowed' => true])
        ->assertJson(fn ($json) => $json->where('reasons', fn ($r) => collect($r)->contains(fn ($l) => str_contains($l, 'Office admin') && str_contains($l, 'for your office'))
            && collect($r)->contains("{$lee->name} is in your office."))->etc());
    $this->actingAs(r1fresh($this->staff))->getJson("/contacts/{$uptown->ulid}/why")->assertNotFound();
});

it('hides commission from a transaction coordinator and says why', function (): void {
    $deal = app(Deals::class)->create(['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX'], $this->agent, $this->owner);
    $deal->forceFill(['gci_cents' => 900000])->save();

    $this->actingAs($this->owner)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('deal.gci_cents', 900000)->where('deal.commission_hidden', false));

    $this->roles->assign($this->staff, r1role('transaction_coordinator'), $this->owner);
    $tc = r1fresh($this->staff);
    $this->actingAs($tc)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('deal.gci_cents', null)->where('deal.commission_hidden', true));
    $this->actingAs($tc)->getJson("/deals/{$deal->ulid}/why")->assertOk()
        ->assertJson(fn ($json) => $json->where('reasons', fn ($r) => collect($r)->contains(fn ($l) => str_contains($l, 'Transaction coordinator'))
            && collect($r)->contains(fn ($l) => str_contains($l, 'Commission amounts are hidden')))->etc());

    // The agent always sees their own deal's figures.
    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('deal.gci_cents', 900000));
    $this->actingAs($this->agent)->getJson("/deals/{$deal->ulid}/why")->assertJsonFragment(['It is your deal.']);
});

it('previews the menu and hidden fields for a role before saving', function (): void {
    $this->actingAs($this->owner)->postJson('/settings/roles/preview-as', ['permissions' => ['sites.manage' => null, 'crm.contacts.view' => 'team']])
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->where('menu', fn ($menu) => collect($menu)->flatMap(fn ($g) => $g['items'])->contains('Website builder')
                && ! collect($menu)->flatMap(fn ($g) => $g['items'])->contains('Roles'))
            ->where('hidden', fn ($h) => collect($h)->contains('Commission amounts and earnings'))
            ->etc());
    $this->actingAs($this->agent)->postJson('/settings/roles/preview-as', ['permissions' => []])->assertForbidden();
});

it('emails the other owners when someone is given access to money, people or exports', function (): void {
    Notification::fake();
    $this->roles->assign($this->staff, r1role('team_lead'), $this->owner);
    Notification::assertNothingSent();

    $this->roles->assign($this->staff, r1role('bookkeeper'), $this->owner);
    Notification::assertSentTo($this->coOwner, AccessAlertMail::class, fn (AccessAlertMail $m) => str_contains($m->headline, 'Sue')
        && in_array('Export the books', $m->what, true));
    Notification::assertNotSentTo($this->owner, AccessAlertMail::class);

    // Widening a role someone holds alerts too.
    $this->roles->save(r1role('team_lead'), 'Team lead', null, ['crm.contacts.view' => 'team', 'core.users.manage' => null], $this->owner);
    Notification::assertSentTo($this->coOwner, AccessAlertMail::class, fn (AccessAlertMail $m) => str_contains($m->headline, 'Team lead role was given more access'));
});

it('adds ready-made roles a brokerage does not have yet', function (): void {
    r1role('isa')->forceDelete();
    $this->actingAs($this->owner)->get('/settings/roles')->assertInertia(fn (Assert $page) => $page->where('missingTemplates.isa', 'ISA (inside sales)'));
    $this->actingAs($this->owner)->post('/settings/roles/templates/isa')->assertSessionHasNoErrors();
    expect(r1role('isa')->name)->toBe('ISA (inside sales)')
        ->and(fn () => $this->roles->addTemplate('isa', $this->owner))->toThrow(ValidationException::class);
});

it('runs the quarterly access review: keep, remove, sign off and export', function (): void {
    Notification::fake();
    $this->roles->assign($this->staff, r1role('bookkeeper'), $this->owner);
    $this->roles->assign($this->agent, r1role('team_lead'), $this->owner);

    $this->actingAs($this->agent)->get('/settings/access-review')->assertForbidden();
    $this->actingAs($this->owner)->get('/settings/roles')->assertInertia(fn (Assert $page) => $page->where('accessReviewDue', true));

    $this->actingAs($this->owner)->post('/settings/access-review/start')->assertSessionHasNoErrors();
    $items = DB::table('access_review_items')->orderByDesc('risky')->get();
    expect($items)->toHaveCount(2)->and((bool) $items[0]->risky)->toBeTrue();

    $this->actingAs($this->owner)->get('/settings/access-review')->assertInertia(fn (Assert $page) => $page
        ->component('settings/access-review')->where('started', true)->count('items', 2)
        ->where('items.0.person', 'Sue '.$this->staff->last_name)->where('items.0.dormant', true));

    // Signing off needs the password confirmed.
    $this->actingAs($this->owner)->post('/settings/access-review/complete')->assertRedirect();
    expect(DB::table('access_reviews')->whereNotNull('completed_at')->count())->toBe(0);
    $confirmed = fn () => $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    $confirmed()->post('/settings/access-review/complete')->assertSessionHasErrors('review');

    $this->actingAs($this->owner)->post("/settings/access-review/items/{$items[0]->id}", ['decision' => 'remove'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/settings/access-review/items/{$items[1]->id}", ['decision' => 'keep'])->assertSessionHasNoErrors();
    expect(r1fresh($this->staff)->can('finance.ledger.view'))->toBeFalse()
        ->and(r1fresh($this->agent)->can('crm.contacts.reassign'))->toBeTrue();

    $confirmed()->post('/settings/access-review/complete', ['note' => 'Q review'])->assertSessionHasNoErrors();
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'core.access_review.completed')->exists())->toBeTrue()
        ->and(DB::table('access_reviews')->whereNotNull('completed_at')->count())->toBe(1);
    $this->actingAs($this->owner)->get('/settings/roles')->assertInertia(fn (Assert $page) => $page->where('accessReviewDue', false));

    $csv = $confirmed()->get('/settings/access-review/export');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Person,Email,Type,Roles,Permission')->toContain('Reassign company contacts');
});

it('reminds owners when the quarter\'s review is not signed off', function (): void {
    Notification::fake();
    $this->travelTo(now()->firstOfQuarter()->addDays(20));
    $this->artisan('core:access-review-reminder')->assertSuccessful();
    Notification::assertSentTo([$this->owner, $this->coOwner], AccessReviewDueMail::class);

    $this->travelTo(now()->firstOfQuarter()->addDays(3));
    Notification::fake();
    $this->artisan('core:access-review-reminder')->assertSuccessful();
    Notification::assertNothingSent();
});
