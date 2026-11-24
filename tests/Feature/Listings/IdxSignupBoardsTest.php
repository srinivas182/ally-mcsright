<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\BoardFees;

/*
| IDX7: the broker ticks their states and MLS boards at sign-up. The package includes one board; each extra board adds
| its monthly fee to the invoice on the 1st. The chosen boards are added to the new brokerage, on demo listings.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->package = Package::query()->create(['name' => 'Brokerage', 'monthly_cents' => 49_900, 'yearly_cents' => 499_000, 'ailly_conversations' => 500, 'features' => [], 'active' => true]);
    $this->db = DB::connection(Connections::control());
    // HQ has opened Texas and priced two boards; NTREIS costs more than HAR.
    $this->db->table('idx_states')->where('code', 'TX')->update(['enabled' => true]);
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'main_cost_cents' => 4_000]);
    $this->db->table('idx_boards')->where('code', 'NTREIS')->update(['visibility' => 'public', 'main_cost_cents' => 6_000]);
    $this->har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
    $this->ntreis = (string) $this->db->table('idx_boards')->where('code', 'NTREIS')->value('ulid');
    $this->hidden = (string) $this->db->table('idx_boards')->where('visibility', '!=', 'public')->value('ulid');
});

function paySignup(string $signup): void
{
    $event = ['id' => 'evt_'.$signup, 'type' => 'checkout.session.completed', 'data' => ['object' => ['subscription' => 'sub_1', 'customer' => 'cus_1',
        'metadata' => ['kind' => 'signup', 'signup' => $signup, 'package_id' => (string) test()->package->id, 'interval' => 'month', 'price_cents' => '49900']]]];
    test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], (string) json_encode($event))->assertOk();
}

it('shows open boards by state at sign-up, without naming the connector', function (): void {
    $this->get('http://app.rightally.io/start')->assertOk()
        ->assertSee('Your MLS boards')->assertSee('One board is included in the price.')
        ->assertSee('Houston Association of REALTORS', false)->assertSee('value="'.$this->har.'"', false)
        ->assertDontSee('Repliers')->assertDontSee('Spark');
});

it('adds the chosen boards to the new brokerage once it has paid, ignoring boards that are not offered', function (): void {
    $this->post('http://app.rightally.io/start', ['brokerage' => 'Oak Realty', 'slug' => 'oak-realty', 'owner_name' => 'Dana Smith', 'owner_email' => 'dana@oak.test',
        'package' => $this->package->ulid, 'interval' => 'month', 'terms' => '1', 'boards' => [$this->har, $this->ntreis, $this->hidden, $this->har]])->assertRedirect();
    $signup = $this->db->table('signups')->orderByDesc('id')->first();
    expect(json_decode((string) $signup->idx_boards, true))->toBe([$this->har, $this->ntreis]);

    paySignup((string) $signup->ulid);
    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();
    $subs = $this->db->table('idx_subscriptions')->where('tenant_id', $tenant->id)->get();
    expect($subs)->toHaveCount(2)->and($subs->pluck('status')->unique()->all())->toBe(['demo'])->and($subs->pluck('created_by')->filter()->all())->toBe([]);

    // The dearer board is the included one; HAR is the extra.
    $fees = app(TenantContext::class)->runAs($tenant, fn () => app(BoardFees::class)->current());
    expect($fees['included'])->toContain('North Texas')->and($fees['extras'])->toHaveCount(1)->and($fees['extra_cents'])->toBe((int) $subs->min('fee_cents'));
});

it('bills each extra board once a month and nothing for the included board', function (): void {
    $this->post('http://app.rightally.io/start', ['brokerage' => 'Oak Realty', 'slug' => 'oak-realty', 'owner_name' => 'Dana Smith', 'owner_email' => 'dana@oak.test',
        'package' => $this->package->ulid, 'interval' => 'month', 'terms' => '1', 'boards' => [$this->har, $this->ntreis]])->assertRedirect();
    paySignup((string) $this->db->table('signups')->orderByDesc('id')->value('ulid'));
    $tenant = Tenant::query()->where('slug', 'oak-realty')->sole();

    $this->travelTo(CarbonImmutable::parse('2026-07-01 05:10', 'UTC'));
    $this->artisan('billing:bill-addons')->assertSuccessful();
    $this->artisan('billing:bill-addons')->assertSuccessful(); // the same month again
    $items = array_values(array_filter($this->gateway->invoiceItems, fn (array $i): bool => str_starts_with($i['description'], 'MLS board:')));
    expect($items)->toHaveCount(1)->and($items[0]['description'])->toBe('MLS board: Houston Association of REALTORS® (HAR), July 2026')->and($items[0]['customer'])->toBe('cus_1');

    // With one board left, nothing is extra.
    $this->db->table('idx_subscriptions')->where('tenant_id', $tenant->id)->where('board_id', $this->db->table('idx_boards')->where('code', 'HAR')->value('id'))->update(['status' => 'cancelled']);
    expect(app(TenantContext::class)->runAs($tenant, fn () => app(BoardFees::class)->current())['extra_cents'])->toBe(0);
});

it('shows the brokerage which board is included and what the others add', function (): void {
    $tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $owner = app(TenantContext::class)->runAs($tenant, fn () => User::factory()->owner()->create());
    giveLicence($tenant);
    $this->actingAs($owner)->post('http://acme.rightally.app/settings/mls/boards', ['board' => $this->har])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post('http://acme.rightally.app/settings/mls/boards', ['board' => $this->ntreis])->assertSessionHasNoErrors();

    $this->actingAs($owner)->get('http://acme.rightally.app/settings/mls')->assertInertia(fn (Assert $page) => $page
        ->where('fees.extras.0.name', fn ($n) => str_contains((string) $n, 'HAR'))
        ->where('fees.included_ulid', fn ($u) => is_string($u)));
});
