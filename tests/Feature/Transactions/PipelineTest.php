<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Deals;
use Modules\Transactions\Reports\ListingBoard;

/*
| PG1: deals not under contract yet live in CRM › Pipeline, by stage. Agents see and move only their own; the
| deal moves to Transactions once it is under contract.
*/

beforeEach(function (): void {
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->other = User::factory()->create(['first_name' => 'Otto']);
    $deals = app(Deals::class);
    $this->mine = $deals->create(['type' => 'buyer', 'street' => '12 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 45_000_000], $this->agent, $this->agent);
    $this->theirs = $deals->create(['type' => 'listing', 'street' => '9 Oak Ave', 'state_code' => 'TX'], $this->other, $this->other);
});

it('shows each agent their own deals by stage, and the owner everyone\'s', function (): void {
    $this->actingAs($this->agent)->get('/pipeline')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('crm/pipeline')->has('stages', 3)->has('deals', 1)
        ->where('deals.0.street', '12 Elm St')->where('deals.0.stage', 'new'));
    $this->actingAs($this->owner)->get('/pipeline')->assertInertia(fn (Assert $page) => $page->has('deals', 2));
});

it('moves a deal between stages, only for people who can see it', function (): void {
    $this->actingAs($this->agent)->post("/pipeline/{$this->mine->ulid}/stage", ['stage' => 'offer'])->assertRedirect();
    expect($this->mine->fresh()->getAttribute('pipeline_stage'))->toBe('offer');

    $this->actingAs($this->agent)->post("/pipeline/{$this->mine->ulid}/stage", ['stage' => 'closed'])->assertSessionHasErrors('stage');
    $this->actingAs($this->agent)->post("/pipeline/{$this->theirs->ulid}/stage", ['stage' => 'offer'])->assertNotFound();
});

it('keeps deals under contract out of the pipeline', function (): void {
    $this->mine->forceFill(['status' => 'under_contract'])->save();

    $this->actingAs($this->agent)->get('/pipeline')->assertInertia(fn (Assert $page) => $page->has('deals', 0));
    $this->actingAs($this->agent)->post("/pipeline/{$this->mine->ulid}/stage", ['stage' => 'offer'])->assertStatus(422);
});

it('names the client on pipeline and listing cards: the buyer or seller, else the contact the deal came from (FX5)', function (): void {
    $db = DB::connection(Connections::cell());
    $contact = Contact::query()->create(['type' => 'person', 'first_name' => 'Bella', 'last_name' => 'Buyer', 'emails' => [], 'phones' => [], 'origin' => 'company', 'source' => 'manual', 'owner_user_id' => $this->agent->id]);
    $this->mine->forceFill(['contact_id' => $contact->id])->save();
    $db->table('tx_parties')->insert(['tenant_id' => $this->theirs->tenant_id, 'deal_id' => $this->theirs->id,
        'role' => 'seller', 'name' => 'Sam Seller', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->get('/pipeline')->assertInertia(fn (Assert $page) => $page
        ->where('deals', fn ($deals): bool => collect($deals)->firstWhere('street', '12 Elm St')['client'] === 'Bella Buyer'
            && collect($deals)->firstWhere('street', '9 Oak Ave')['client'] === 'Sam Seller'
            && collect($deals)->firstWhere('street', '9 Oak Ave')['client_role'] === 'Seller'));
    $board = app(ListingBoard::class)->build();
    expect($board['columns']['getting_ready'][0]['client'])->toBe('Sam Seller');
});
