<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Scale\CellsAlert;
use Modules\Core\Tenancy\Actions\PlaceInCell;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| PS1 (specs/core/14): cells close themselves to new brokerages when full and reopen when they have room, HQ is told
| early, and a new cell is only opened once its tables are copied and checked.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $this->tenant = $this->onTenantHost('acme');
    $this->cell = Cell::query()->findOrFail($this->tenant->cell_id);
    $this->dbFile = sys_get_temp_dir().'/cell-'.uniqid().'.sqlite';
    touch($this->dbFile);
});

afterEach(function (): void {
    @unlink($this->dbFile);
});

it('warns HQ at 85%, closes a full cell to new brokerages, and reopens it below 75%', function (): void {
    $used = Tenant::query()->where('cell_id', $this->cell->id)->whereNot('status', 'closed')->count();
    $this->cell->update(['capacity_tenants' => 10]);
    Cell::query()->whereKeyNot($this->cell->id)->update(['status' => CellStatus::Maintenance]);
    foreach (range(1, 9 - $used) as $n) {
        Tenant::factory()->create(['slug' => 'b'.$n, 'cell_id' => $this->cell->id]);
    }

    $this->artisan('cells:watch')->assertSuccessful(); // 90% full
    Notification::assertSentTo($this->staff, CellsAlert::class, fn (CellsAlert $m): bool => str_contains($m->subject, 'filling up'));
    expect($this->cell->fresh()->status)->toBe(CellStatus::Active);

    Tenant::factory()->create(['slug' => 'pine', 'cell_id' => $this->cell->id]);
    $this->artisan('cells:watch')->assertSuccessful();
    expect($this->cell->fresh()->status)->toBe(CellStatus::Full)->and((bool) $this->cell->fresh()->getAttribute('auto_full'))->toBeTrue();
    Notification::assertSentTo($this->staff, CellsAlert::class, fn (CellsAlert $m): bool => $m->urgent && str_contains($m->subject, 'No cell has room'));
    expect(fn () => app(PlaceInCell::class)->handle())->toThrow(RuntimeException::class);

    $this->cell->update(['capacity_tenants' => 20]); // 50%
    $this->artisan('cells:watch')->assertSuccessful();
    expect($this->cell->fresh()->status)->toBe(CellStatus::Active)->and(app(PlaceInCell::class)->handle()->id)->toBe($this->cell->id);
});

it('never reopens a cell a person closed', function (): void {
    $this->cell->forceFill(['status' => CellStatus::Full, 'auto_full' => false])->save();
    $this->artisan('cells:watch')->assertSuccessful();
    expect($this->cell->fresh()->status)->toBe(CellStatus::Full);
});

it('adds a cell from HQ, copies and checks its tables, and only then opens it', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('A new cell here is a SQLite file, so this runs where the main database is SQLite too.');
    }
    auth()->logout();
    $url = 'http://app.rightally.io/hq/cells';
    $this->actingAs($this->staff)->post($url, ['name' => 'us-east-2', 'region' => 'us-east', 'driver' => 'sqlite', 'database' => $this->dbFile])->assertRedirect(); // asks for the password
    expect(Cell::query()->where('name', 'us-east-2')->exists())->toBeFalse();

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post($url, ['name' => 'us-east-2', 'region' => 'us-east', 'driver' => 'sqlite', 'database' => $this->dbFile, 'capacity_tenants' => 500])->assertRedirect();
    $cell = Cell::query()->where('name', 'us-east-2')->sole();
    expect($cell->status)->toBe(CellStatus::Active)->and($cell->capacity_tenants)->toBe(500)
        ->and((string) $cell->getAttribute('credentials'))->not->toContain($this->dbFile) // encrypted
        ->and(DB::connection('cell_'.$cell->id)->getSchemaBuilder()->hasTable('crm_contacts'))->toBeTrue();

    $this->actingAs($this->staff)->get($url)->assertInertia(fn (Assert $page) => $page->component('hq/cells')
        ->where('cells', fn ($cells): bool => collect($cells)->firstWhere('name', 'us-east-2')['last_check'][0]['ok'] === true)
        ->missing('cells.0.credentials'));

    // A cell that can't be reached is added but stays closed.
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post($url, ['name' => 'broken', 'region' => 'us-east', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'x'])->assertRedirect();
    expect(Cell::query()->where('name', 'broken')->sole()->status)->toBe(CellStatus::Maintenance);
    $broken = Cell::query()->where('name', 'broken')->sole();
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post("{$url}/{$broken->ulid}/status", ['status' => 'active'])
        ->assertSessionHasErrors('cell');
});
