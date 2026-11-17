<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Safeguards\BrokerageKeys;
use Modules\Core\Safeguards\DatabaseMoves;
use Modules\Core\Safeguards\MoveNotice;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    Storage::fake('local');
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->agent = User::factory()->create();
    $this->dbFile = sys_get_temp_dir().'/dedicated-'.uniqid().'.sqlite';
    touch($this->dbFile);
});

afterEach(function (): void {
    @unlink($this->dbFile);
});

function hqPair(): array
{
    config(['core.hq_host' => 'app.rightally.io']);
    test()->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();

    return app(TenantContext::class)->runAs($hq, fn () => [User::factory()->hqStaff()->create(), User::factory()->hqStaff()->create()]);
}

it('moves a brokerage to its own database with two people, verifies it, switches, and can switch back', function (): void {
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    $other = Tenant::factory()->create(['slug' => 'other', 'cell_id' => $this->tenant->cell_id]);
    app(TenantContext::class)->runAs($other, fn () => Contact::query()->create(['first_name' => 'Pat', 'last_name' => 'Other', 'origin' => 'company', 'source' => 'manual',
        'emails' => [], 'phones' => []]));
    [$hq1, $hq2] = hqPair();
    $url = "http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/data";
    $ok = ['auth.password_confirmed_at' => time()];

    $this->actingAs($hq1)->withSession($ok)->post("{$url}/moves", ['target' => 'dedicated', 'driver' => 'sqlite', 'database' => $this->dbFile])->assertSessionHasNoErrors();
    $move = DB::connection(Connections::control())->table('brokerage_moves')->sole();
    $cell = Cell::query()->where('dedicated', true)->sole();
    expect($move->status)->toBe('planned')->and(json_decode((string) $move->plan, true)['rows'])->toBeGreaterThan(0)
        ->and($cell->toArray())->not->toHaveKey('credentials'); // never shown again
    $this->actingAs($hq1)->withSession($ok)->post("{$url}/moves/{$move->ulid}/approve")->assertSessionHasErrors('move');
    $this->actingAs($hq2)->withSession($ok)->post("{$url}/moves/{$move->ulid}/approve")->assertSessionHasNoErrors();

    $done = DB::connection(Connections::control())->table('brokerage_moves')->sole();
    expect($done->status)->toBe('switched')->and(json_decode((string) $done->result, true)['problems'])->toBe([])
        ->and((int) $this->tenant->fresh()->cell_id)->toBe($cell->id);
    Notification::assertSentTo($this->owner, MoveNotice::class, fn (MoveNotice $n): bool => $n->when === 'before');
    Notification::assertSentTo($this->owner, MoveNotice::class, fn (MoveNotice $n): bool => $n->when === 'after');

    // The brokerage now reads from its own database; the other brokerage is untouched.
    app(TenantContext::class)->set($this->tenant->fresh());
    expect(Connections::cell())->toBe('cell_'.$cell->id)
        ->and(Contact::query()->pluck('first_name')->all())->toBe(['Sam'])
        ->and(DB::connection('cell_'.$cell->id)->table('crm_contacts')->where('tenant_id', $other->id)->count())->toBe(0)
        ->and(app(TenantContext::class)->runAs($other, fn () => Contact::query()->pluck('first_name')->all()))->toBe(['Pat']);

    // Work continues in the new database, then a one-click switch-back brings it home.
    app(ManageContacts::class)->create(['first_name' => 'Kim', 'email' => 'kim@x.test'], $this->agent);
    $this->actingAs($hq1)->withSession($ok)->post("{$url}/moves/{$move->ulid}/switch-back")->assertSessionHasNoErrors();
    $tenant = $this->tenant->fresh();
    expect((int) $tenant->cell_id)->toBe((int) $move->from_cell_id);
    app(TenantContext::class)->set($tenant);
    expect(Contact::query()->orderBy('first_name')->pluck('first_name')->all())->toBe(['Kim', 'Sam'])
        ->and(Contact::query()->where('first_name', 'Kim')->value('id'))->toBeGreaterThan(1_000_000_000_000); // made in the dedicated range: no clash
    $this->artisan('safeguards:daily')->assertSuccessful();
});

it('runs a drill: copies, checks and removes the copy without switching', function (): void {
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    [$hq1, $hq2] = hqPair();
    $url = "http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/data";
    $ok = ['auth.password_confirmed_at' => time()];
    $this->actingAs($hq1)->withSession($ok)->post("{$url}/moves", ['target' => 'drill', 'driver' => 'sqlite', 'database' => $this->dbFile]);
    $move = DB::connection(Connections::control())->table('brokerage_moves')->sole();
    $this->actingAs($hq2)->withSession($ok)->post("{$url}/moves/{$move->ulid}/approve");

    $cell = Cell::query()->where('dedicated', true)->sole();
    expect(DB::connection(Connections::control())->table('brokerage_moves')->value('status'))->toBe('done')
        ->and((int) $this->tenant->fresh()->cell_id)->not->toBe($cell->id)
        ->and(DB::connection(DatabaseMoves::name($cell))->table('crm_contacts')->count())->toBe(0);
    $this->actingAs($hq1)->get($url)->assertInertia(fn (Assert $p) => $p->where('moves.0.kind', 'drill')->where('moves.0.step', 'Drill passed; the copy was removed'));
});

it('pauses saving during the final copy of a move', function (): void {
    Cache::put(DatabaseMoves::readOnlyKey($this->tenant->id), true, 60);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Zed', 'email' => 'zed@x.test'])->assertStatus(503)->assertHeader('Retry-After', '120');
    $this->actingAs($this->agent)->get('/contacts')->assertOk();
});

it('seals tax ids and bank numbers with the brokerage\'s own key, rotates and re-seals', function (): void {
    $keys = app(BrokerageKeys::class);
    $sealed = $keys->seal('123-45-6789');
    expect($sealed)->toStartWith('bk1:1:')->and($keys->open($sealed))->toBe('123-45-6789');

    // Another brokerage cannot open it with its own key.
    $other = Tenant::factory()->create(['slug' => 'other']);
    expect(fn () => app(TenantContext::class)->runAs($other, fn () => app(BrokerageKeys::class)->open(str_replace('bk1:1:', 'bk1:1:', $sealed))))
        ->toThrow(Exception::class);

    // Values sealed before keys existed still open, and re-keying moves them to the brokerage key.
    DB::connection(Connections::cell())->table('pay_w9s')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->agent->id,
        'legal_name' => 'Ann Agent', 'tax_class' => 'individual', 'tin_type' => 'ssn', 'tin_sealed' => Crypt::encryptString('987-65-4321'), 'tin_last4' => '4321',
        'address' => '1 Main St', 'signature_name' => 'Ann Agent', 'signed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect($keys->status())->toMatchArray(['version' => 1, 'legacy' => 1]);
    $keys->rotate();
    expect($keys->rekey())->toBe(1)
        ->and($keys->status())->toMatchArray(['version' => 2, 'sealed' => 1, 'legacy' => 0])
        ->and($keys->open($sealed))->toBe('123-45-6789') // old versions still open
        ->and($keys->open((string) DB::connection(Connections::cell())->table('pay_w9s')->value('tin_sealed')))->toBe('987-65-4321');
});
