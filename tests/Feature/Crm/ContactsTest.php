<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Enums\Origin;
use Modules\Crm\Enums\Relation;
use Modules\Crm\Events\ContactCreated;
use Modules\Crm\Events\ContactOwnerChanged;
use Modules\Crm\Models\Contact;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create();
    $this->agentA = User::factory()->create();
    $this->agentB = User::factory()->create();
    $this->assistantA = User::factory()->assistantOf($this->agentA)->create();
    $this->contacts = app(ManageContacts::class);

    $this->aPersonal = $this->contacts->create(['first_name' => 'Pat', 'last_name' => 'Personal', 'email' => 'pat@a.test'], $this->agentA);
    $this->bPersonal = $this->contacts->create(['first_name' => 'Bea', 'last_name' => 'Private', 'email' => 'bea@b.test'], $this->agentB);
    $this->company = $this->contacts->create(['first_name' => 'Cal', 'last_name' => 'Company', 'phone' => '601-555-0100'], $this->owner);
    $this->contacts->assignOwner($this->company, $this->agentA, $this->owner);
});

/** @return list<string> names the user can see */
function visibleTo(User $user): array
{
    return app(ContactVisibility::class)->query($user)->orderBy('first_name')->pluck('first_name')->all();
}

it('applies one visibility rule: personal sphere stays with the agent', function (): void {
    expect(visibleTo($this->agentA))->toBe(['Cal', 'Pat'])
        ->and(visibleTo($this->agentB))->toBe(['Bea'])
        ->and(visibleTo($this->assistantA))->toBe(['Cal', 'Pat'])
        ->and(visibleTo($this->owner))->toBe(['Cal'])
        ->and(visibleTo($this->staff))->toBe(['Cal']);
});

it('lets the brokerage open personal contacts to owners if it chooses', function (): void {
    $this->tenant->forceFill(['crm_personal_visible_to_owners' => true])->save();

    expect(visibleTo($this->owner))->toBe(['Bea', 'Cal', 'Pat'])->and(visibleTo($this->staff))->toBe(['Cal']);
});

it('shows "not found" for contacts the user may not see, in the list and on the detail page', function (): void {
    $this->actingAs($this->agentB)->get('/contacts')
        ->assertInertia(fn (Assert $page) => $page->component('crm/contacts/index')->has('contacts.data', 1)->where('contacts.data.0.name', 'Bea Private'));

    $this->actingAs($this->agentB)->get("/contacts/{$this->aPersonal->ulid}")->assertNotFound();
    $this->actingAs($this->owner)->get("/contacts/{$this->aPersonal->ulid}")->assertNotFound();
    $this->actingAs($this->agentA)->get("/contacts/{$this->aPersonal->ulid}")->assertOk();
});

it('never shows another brokerage\'s contacts', function (): void {
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $otherAgent = app(TenantContext::class)->runAs($other, fn () => User::factory()->create());

    $this->actingAs($otherAgent)->get("http://other.rightally.app/contacts/{$this->aPersonal->ulid}")->assertNotFound();
    expect(app(TenantContext::class)->runAs($other, fn () => visibleTo($otherAgent)))->toBe([]);
});

it('quick-adds with a name and an email or phone, normalised for search', function (): void {
    Event::fake([ContactCreated::class]);

    $this->actingAs($this->agentB)->post('/contacts', ['first_name' => 'Nia', 'phone' => '(601) 555-0123', 'source' => 'open_house'])->assertRedirect();
    $contact = Contact::query()->where('first_name', 'Nia')->sole();

    expect($contact->primary_phone)->toBe('+16015550123')->and($contact->origin)->toBe(Origin::Personal)
        ->and($contact->owner_user_id)->toBe($this->agentB->id)->and($contact->source->value)->toBe('open_house');
    Event::assertDispatched(ContactCreated::class);

    $this->actingAs($this->agentB)->post('/contacts', ['first_name' => 'X'])->assertSessionHasErrors('email');
    $this->actingAs($this->agentB)->post('/contacts', ['email' => 'x@y.test'])->assertSessionHasErrors('first_name');
});

it('finds contacts by name, email or phone digits', function (string $search, string $expected): void {
    $this->actingAs($this->agentA)->get('/contacts?search='.urlencode($search))
        ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1)->where('contacts.data.0.name', $expected));
})->with([
    'name' => ['pers', 'Pat Personal'],
    'email' => ['PAT@A', 'Pat Personal'],
    'phone digits' => ['555-0100', 'Cal Company'],
]);

it('catches duplicates without revealing hidden contacts', function (): void {
    try {
        $this->contacts->create(['first_name' => 'Pat again', 'email' => 'PAT@a.test'], $this->agentA);
        test()->fail('Expected a duplicate error.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'][0])->toContain('Pat Personal')->and($e->errors()['duplicate'][0])->toBe($this->aPersonal->ulid);
    }

    try {
        $this->contacts->create(['first_name' => 'Someone', 'email' => 'pat@a.test'], $this->agentB);
        test()->fail('Expected a duplicate error.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'][0])->not->toContain('Pat')->toContain('already exists in the brokerage')
            ->and($e->errors()['duplicate'][0])->toBe('');
    }
});

it('lets the brokerage reassign company contacts, but not personal ones', function (): void {
    Event::fake([ContactOwnerChanged::class]);

    $this->actingAs($this->owner)->post("/contacts/{$this->company->ulid}/assign", ['owner_user_id' => $this->agentB->id])->assertSessionHasNoErrors();
    expect($this->company->fresh()?->owner_user_id)->toBe($this->agentB->id)
        ->and(visibleTo($this->agentB))->toContain('Cal')->and(visibleTo($this->agentA))->not->toContain('Cal');
    Event::assertDispatched(ContactOwnerChanged::class);

    expect(fn () => $this->contacts->assignOwner($this->aPersonal, $this->agentB, $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => $this->contacts->assignOwner($this->company->fresh(), $this->agentA, $this->agentB))->toThrow(ValidationException::class);
});

it('updates details and stage only for contacts the user manages', function (): void {
    $this->actingAs($this->agentA)->patch("/contacts/{$this->aPersonal->ulid}", ['lifecycle' => 'active', 'city' => 'Jackson', 'state' => 'ms'])->assertSessionHasNoErrors();
    $fresh = $this->aPersonal->fresh();
    expect($fresh?->lifecycle->value)->toBe('active')->and($fresh?->state)->toBe('MS');

    $this->actingAs($this->agentB)->patch("/contacts/{$this->aPersonal->ulid}", ['city' => 'Hacked'])->assertNotFound();
});

it('links people as a household and relationships, only among visible contacts', function (): void {
    $spouse = $this->contacts->create(['first_name' => 'Sam', 'last_name' => 'Personal', 'email' => 'sam@a.test'], $this->agentA);

    $this->actingAs($this->agentA)->post("/contacts/{$this->aPersonal->ulid}/relationships", ['related' => $spouse->ulid, 'relation' => 'spouse', 'household' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agentA)->get("/contacts/{$this->aPersonal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('relationships.0.name', 'Sam Personal')->where('households.0.name', 'Personal household'));

    $this->actingAs($this->agentA)->post("/contacts/{$this->aPersonal->ulid}/relationships", ['related' => $this->bPersonal->ulid, 'relation' => 'referrer'])->assertNotFound();
    expect(fn () => $this->contacts->relate($this->aPersonal, $this->aPersonal, Relation::Other, $this->agentA))->toThrow(ValidationException::class);
});

it('appears in the menu for brokerage users but not HQ staff', function (): void {
    $this->actingAs($this->agentA)->get('/agent')->assertInertia(fn (Assert $page) => $page->where('navigation.1.items.0.title', 'Contacts'));
});
