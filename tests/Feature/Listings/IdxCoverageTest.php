<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Coverage;
use Modules\Listings\Idx\StarterDirectory;

/*
| IDX1: HQ › IDX coverage: open states, the MLS board directory (main and backup connector, our cost, the client's fee,
| the IDX display template) and the data connectors. Brokers never see connector names.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->db = DB::connection(Connections::control());
});

function idxHq(string $path): string
{
    return 'http://app.rightally.io'.$path;
}

it('starts with every state, the five launch states open, and their boards ready for HQ to check', function (): void {
    expect($this->db->table('idx_states')->count())->toBe(count(StarterDirectory::STATES))
        ->and($this->db->table('idx_states')->where('enabled', true)->orderBy('code')->pluck('code')->all())->toBe(['AL', 'FL', 'LA', 'MS', 'TX']);
    foreach (StarterDirectory::LAUNCH_STATES as $state) {
        expect($this->db->table('idx_boards')->where('state', $state)->count())->toBeGreaterThan(3);
    }
    // Brokers' advice: Repliers for Houston, Spark for MLS United. Nothing is public until HQ checks it.
    expect($this->db->table('idx_boards')->where('code', 'HAR')->value('main_connector'))->toBe('repliers')
        ->and($this->db->table('idx_boards')->where('code', 'MLSUNITED')->value('main_connector'))->toBe('spark')
        ->and($this->db->table('idx_boards')->where('visibility', 'public')->count())->toBe(0);

    $this->actingAs($this->staff)->get(idxHq('/hq/idx'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('hq/idx')
        ->has('states', count(StarterDirectory::STATES))->has('boards', count(StarterDirectory::BOARDS))->has('connectors', count(StarterDirectory::CONNECTORS)));
});

it('is HQ only', function (): void {
    $brokerage = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $owner = app(TenantContext::class)->runAs($brokerage, fn () => User::factory()->owner()->create());

    $this->actingAs($owner)->get('http://acme.rightally.app/hq/idx')->assertNotFound();
});

it('prices a board at our cost plus the connector margin, or at the fee HQ sets', function (): void {
    expect(Coverage::fee(null, 5_000, 30))->toBe(6_500)
        ->and(Coverage::fee(null, 4_550, 30))->toBe(6_000) // rounded up to whole dollars
        ->and(Coverage::fee(7_500, 5_000, 30))->toBe(7_500)
        ->and(Coverage::fee(null, null, 30))->toBeNull();
});

it('offers a board at sign-up only when its state is open, it is public, and it has a fee; never with connector names', function (): void {
    $har = $this->db->table('idx_boards')->where('code', 'HAR')->first();
    $this->actingAs($this->staff)->put(idxHq('/hq/idx/boards/'.$har->ulid), [
        'code' => 'HAR', 'name' => $har->name, 'state' => 'TX', 'other_states' => [], 'region' => 'Greater Houston', 'visibility' => 'public',
        'approval_days_min' => 3, 'approval_days_max' => 7, 'main_connector' => 'repliers', 'main_cost_cents' => 4_000, 'backup_connector' => 'trestle',
        'backup_cost_cents' => null, 'connector_code' => 'HAR', 'monthly_fee_cents' => null, 'setup_fee_cents' => 0, 'rules_profile_id' => null, 'checked' => true, 'notes' => null,
    ])->assertSessionHasNoErrors();

    $offered = app(Coverage::class)->offered(['TX']);
    expect($offered)->toHaveCount(1)->and($offered[0]['code'])->toBe('HAR')->and($offered[0]['fee_cents'])->toBe(5_200)
        ->and(json_encode($offered))->not->toContain('repliers')->not->toContain('trestle');
    expect(app(Coverage::class)->offered(['FL']))->toBe([]);

    // Close Texas: HAR is no longer offered.
    $this->actingAs($this->staff)->put(idxHq('/hq/idx/states/TX'), ['enabled' => false])->assertRedirect();
    expect(app(Coverage::class)->offered(['TX']))->toBe([]);
});

it('will not make a board public without a connector, and keeps codes unique', function (): void {
    $base = ['name' => 'Test Board', 'state' => 'LA', 'other_states' => ['MS'], 'region' => null, 'approval_days_min' => 2, 'approval_days_max' => 5,
        'main_connector' => null, 'main_cost_cents' => null, 'backup_connector' => null, 'backup_cost_cents' => null, 'connector_code' => null,
        'monthly_fee_cents' => 5_000, 'setup_fee_cents' => 0, 'rules_profile_id' => null, 'checked' => false, 'notes' => null];

    $this->actingAs($this->staff)->post(idxHq('/hq/idx/boards'), [...$base, 'code' => 'TESTLA', 'visibility' => 'public'])->assertSessionHasErrors('visibility');
    $this->actingAs($this->staff)->post(idxHq('/hq/idx/boards'), [...$base, 'code' => 'HAR', 'visibility' => 'hidden'])->assertSessionHasErrors('code');
    $this->actingAs($this->staff)->post(idxHq('/hq/idx/boards'), [...$base, 'code' => 'TESTLA', 'visibility' => 'coming_soon'])->assertSessionHasNoErrors();

    // A board covering two states shows under both.
    expect(collect(app(Coverage::class)->boards('MS'))->pluck('code'))->toContain('TESTLA');
});

it('lets HQ pause a connector and set its margin', function (): void {
    $this->actingAs($this->staff)->put(idxHq('/hq/idx/connectors/repliers'), ['status' => 'active', 'margin_percent' => 40, 'notes' => 'Per-board wholesale'])->assertSessionHasNoErrors();
    expect($this->db->table('idx_connectors')->where('key', 'repliers')->first())->status->toBe('active')->margin_percent->toBe(40);
    $this->actingAs($this->staff)->put(idxHq('/hq/idx/connectors/repliers'), ['status' => 'gone', 'margin_percent' => 40])->assertSessionHasErrors('status');
});
