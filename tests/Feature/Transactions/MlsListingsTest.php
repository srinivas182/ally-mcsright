<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Transactions\Deals;
use Modules\Transactions\Mls\MlsListings;
use Modules\Transactions\Models\Deal;

/*
| ML2: agents' own MLS listings come into My listings (one click, or by themselves when the owner chooses), prices
| stay in step, MLS status changes ask the agent to mark the deal, and the owner sees MLS sales with no deal here.
*/

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->andy = User::factory()->create(['first_name' => 'Andrew', 'last_name' => 'Smith']);
    $this->maria = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Chen']);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'fake', 'base_url' => 'https://example.test', 'status' => 'active']);
    MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'OFF1', 'scopes' => ['idx'], 'status' => 'active']);
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->andy->id, 'kind' => 'mls',
        'number' => 'A100', 'issuer' => 'ACTRIS', 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    ml2Home('K1', 'L-1', 'Active', ['list_agent_mls_id' => 'A100'], '27 Willow Ln', 61_500_000);
    ml2Home('K2', 'L-2', 'Pending', ['list_agent_mls_id' => 'A100'], '9 Pine St', 45_000_000);
    ml2Home('K3', 'L-3', 'Closed', ['list_agent_mls_id' => 'ZZ', 'list_office_mls_id' => 'ELSE', 'buyer_agent_mls_id' => 'A100', 'buyer_office_mls_id' => 'OFF1'], '4 Elm Ct', 30_000_000, '2026-05-01');
    ml2Home('K4', 'L-4', 'Closed', ['list_agent_mls_id' => 'A100'], '8 Oak Rd', 52_000_000, '2026-04-01');
    ml2Home('K5', 'L-5', 'Active', ['list_agent_mls_id' => 'ZZ'], '1 Other St', 10_000_000);
    // 9 Pine St is on the board at the old price; 8 Oak Rd's sale is in RightAlly under its address (no MLS number).
    $this->pine = app(Deals::class)->create(['type' => 'listing', 'street' => '9 Pine St', 'city' => 'Austin', 'state_code' => 'TX', 'mls_number' => 'L-2', 'price_cents' => 47_500_000], $this->andy, $this->andy);
    app(Deals::class)->create(['type' => 'listing', 'street' => '8 Oak Rd', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 52_000_000], $this->andy, $this->andy);
    $this->travelTo('2026-06-01 09:00');
});

function ml2Home(string $key, string $id, string $status, array $agents, string $street, int $price, ?string $closed = null): void
{
    [$number, $rest] = explode(' ', $street, 2);
    DB::connection(Connections::listings())->table('listings')->insert($agents + ['list_office_mls_id' => 'OFF1', 'ulid' => (string) Str::ulid(), 'mls_source_id' => test()->source->id, 'listing_key' => $key,
        'listing_id' => $id, 'standard_status' => $status, 'street_number' => $number, 'street_name' => $rest, 'city' => 'Austin', 'state_or_province' => 'TX',
        'list_price_cents' => $price, 'close_price_cents' => $closed !== null ? $price : null, 'close_date' => $closed,
        'created_at' => now(), 'updated_at' => now()]);
}

it('offers the agent their own new MLS listings, adds one with a click, and asks about a pending one', function (): void {
    $this->actingAs($this->andy)->get('/deals/listings')->assertInertia(fn (Assert $p) => $p
        ->has('mls.found', 1)->where('mls.found.0.street', '27 Willow Ln')->where('mls.found.0.mls_number', 'L-1')
        ->where('mls.prompts.0.street', '9 Pine St')->where('mls.prompts.0.action', 'under_contract'));

    $ulid = DB::connection(Connections::listings())->table('listings')->where('listing_key', 'K1')->value('ulid');
    $this->actingAs($this->andy)->post("/deals/listings/mls/{$ulid}/add")->assertSessionHasNoErrors();
    $deal = Deal::query()->where('mls_number', 'L-1')->sole();
    expect($deal->agent_user_id)->toBe($this->andy->id)->and($deal->price_cents)->toBe(61_500_000)->and($deal->type)->toBe('listing');

    // Once added it is not offered again; Maria (no MLS ID) is offered nothing and cannot add Andy's listing.
    $this->actingAs($this->andy)->get('/deals/listings')->assertInertia(fn (Assert $p) => $p->has('mls.found', 0));
    $this->actingAs($this->maria)->post("/deals/listings/mls/{$ulid}/add")->assertSessionHasErrors('listing');
});

it('remembers "not now", keeps prices in step and adds by itself only when the owner chooses', function (): void {
    $ulid = DB::connection(Connections::listings())->table('listings')->where('listing_key', 'K1')->value('ulid');
    $this->actingAs($this->andy)->post("/deals/listings/mls/{$ulid}/dismiss");
    expect(app(MlsListings::class)->board($this->andy)['found'])->toBe([]);
    DB::table('tx_mls_dismissed')->delete();

    // Nightly: price in step; nothing added while automatic is off.
    expect(app(MlsListings::class)->sync($this->owner))->toBe(['added' => 0, 'repriced' => 1])
        ->and($this->pine->fresh()->price_cents)->toBe(45_000_000);

    $this->actingAs($this->andy)->post('/deals/listings/mls-auto', ['on' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/listings/mls-auto', ['on' => true])->assertSessionHasNoErrors();
    expect(app(MlsListings::class)->sync($this->owner)['added'])->toBe(1)
        ->and(Deal::query()->where('mls_number', 'L-1')->value('agent_user_id'))->toBe($this->andy->id);
});

it('shows the owner sales in the MLS that have no deal here, on either side', function (): void {
    $this->actingAs($this->owner)->get('/deals/mls-check')->assertInertia(fn (Assert $p) => $p->component('deals/mls-check')
        ->has('sales', 1)->where('sales.0.street', '4 Elm Ct')->where('sales.0.side', 'Buyer side')->where('sales.0.agent', $this->andy->name)
        ->where('sales.0.close_price_cents', 30_000_000));
    $this->actingAs($this->andy)->get('/deals/mls-check')->assertForbidden();
});

it('never brings in another brokerage\'s listing, or one carrying someone else\'s name, under an agent\'s ID', function (): void {
    ml2Home('K6', 'L-6', 'Active', ['list_agent_mls_id' => 'A100', 'list_agent_name' => 'Andrew Smith', 'list_office_mls_id' => 'ELSE'], '2 Far Rd', 20_000_000);
    ml2Home('K7', 'L-7', 'Active', ['list_agent_mls_id' => 'A100', 'list_agent_name' => 'Robert Jones'], '6 Near Rd', 20_000_000);
    ml2Home('K8', 'L-8', 'Active', ['list_agent_mls_id' => 'A100', 'list_agent_name' => 'Smith, Andrew J.'], '7 Good Rd', 20_000_000);

    $streets = array_column(app(MlsListings::class)->board($this->andy)['found'], 'street');
    expect($streets)->toContain('7 Good Rd')->toContain('27 Willow Ln')->not->toContain('2 Far Rd')->not->toContain('6 Near Rd');
});
