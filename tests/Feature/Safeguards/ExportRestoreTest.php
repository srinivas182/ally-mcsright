<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Safeguards\BrokerageExport;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\CellRouting;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    Storage::fake('local');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->agent = User::factory()->create();
});

function hqPeople(): array
{
    config(['core.hq_host' => 'app.rightally.io']);
    test()->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();

    return app(TenantContext::class)->runAs($hq, fn () => [User::factory()->hqStaff()->create(), User::factory()->hqStaff()->create()]);
}

function openZip(string $path, string $password): ZipArchive
{
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, Storage::disk('local')->get($path));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $zip->setPassword($password);

    return $zip;
}

it('lets the owner download everything, password-protected and without secrets, and only theirs', function (): void {
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    Storage::disk('local')->put("forms/{$this->tenant->id}/form.pdf", '%PDF-1.4 ours');
    $other = Tenant::factory()->create(['slug' => 'other']);
    app(TenantContext::class)->runAs($other, fn () => Contact::query()->create(['first_name' => 'Not', 'last_name' => 'Ours', 'origin' => 'company', 'source' => 'manual',
        'emails' => [], 'phones' => []]));
    app(TenantContext::class)->set($this->tenant);

    $this->actingAs($this->agent)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/data-export')->assertForbidden();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/data-export')->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/data-export')->assertSessionHasErrors('export'); // once a day

    $e = DB::connection(Connections::control())->table('brokerage_exports')->sole();
    expect($e->status)->toBe('ready')->and($e->kind)->toBe('portable');
    $page = $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post("/settings/data-export/{$e->ulid}/password")->getSession()->get('exportPassword');

    $zip = openZip((string) $e->path, $page['password']);
    $contacts = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) $zip->getFromName('tables/crm_contacts.jsonl'))));
    $users = (string) $zip->getFromName('tables/users.jsonl');
    expect(array_column($contacts, 'first_name'))->toBe(['Sam'])
        ->and($users)->not->toContain('"password"')->not->toContain('remember_token')
        ->and($zip->getFromName("files/forms/{$this->tenant->id}/form.pdf"))->toBe('%PDF-1.4 ours')
        ->and(json_decode((string) $zip->getFromName('manifest.json'), true)['kind'])->toBe('portable');

    $wrong = openZip((string) $e->path, 'wrong-password');
    expect($wrong->getFromName('manifest.json'))->toBeFalse(); // AES-256 protected

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get("/settings/data-export/{$e->ulid}")->assertOk();
    $this->travel(8)->days();
    $this->artisan('safeguards:expire-exports')->assertSuccessful();
    expect(DB::connection(Connections::control())->table('brokerage_exports')->value('status'))->toBe('expired')->and(Storage::disk('local')->exists((string) $e->path))->toBeFalse();
});

it('restores one brokerage from a backup with two HQ people, after a safety copy, leaving others alone', function (): void {
    $sam = app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 'sam@x.test'], $this->agent);
    Storage::disk('local')->put("forms/{$this->tenant->id}/form.pdf", 'v1');
    [$hq1, $hq2] = hqPeople();
    $url = "http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/data";
    $confirmed = ['auth.password_confirmed_at' => time()];

    $this->actingAs($hq1)->withSession($confirmed)->post("{$url}/export")->assertRedirect();
    $backup = DB::connection(Connections::control())->table('brokerage_exports')->where('kind', 'backup')->sole();
    expect($backup->status)->toBe('ready');

    // After the backup: Sam is renamed, Kim is added, the file changes; another brokerage changes too.
    app(TenantContext::class)->set($this->tenant);
    $sam->forceFill(['first_name' => 'Samuel'])->save();
    $kim = app(ManageContacts::class)->create(['first_name' => 'Kim', 'email' => 'kim@x.test'], $this->agent);
    Storage::disk('local')->put("forms/{$this->tenant->id}/form.pdf", 'v2');
    $other = Tenant::factory()->create(['slug' => 'other']);
    app(TenantContext::class)->runAs($other, fn () => Contact::query()->create(['first_name' => 'Pat', 'last_name' => 'Other', 'origin' => 'company', 'source' => 'manual',
        'emails' => [], 'phones' => []]));

    $this->actingAs($hq1)->withSession($confirmed)->post("{$url}/restores", ['export' => $backup->ulid, 'reason' => 'Agent deleted contacts by mistake', 'confirm' => 'wrong'])
        ->assertSessionHasErrors('confirm');
    $this->actingAs($hq1)->withSession($confirmed)->post("{$url}/restores", ['export' => $backup->ulid, 'reason' => 'Agent deleted contacts by mistake', 'confirm' => 'acme'])
        ->assertSessionHasNoErrors();
    $restore = DB::connection(Connections::control())->table('brokerage_restores')->sole();
    $this->actingAs($hq1)->withSession($confirmed)->post("{$url}/restores/{$restore->ulid}/approve")->assertSessionHasErrors('restore'); // not the asker
    $this->actingAs($hq2)->withSession($confirmed)->post("{$url}/restores/{$restore->ulid}/approve")->assertSessionHasNoErrors();

    $done = DB::connection(Connections::control())->table('brokerage_restores')->sole();
    expect($done->status)->toBe('done')->and($done->safety_export_id)->not->toBeNull();
    app(TenantContext::class)->set($this->tenant);
    expect(Contact::query()->pluck('first_name')->all())->toBe(['Sam'])
        ->and(Storage::disk('local')->get("forms/{$this->tenant->id}/form.pdf"))->toBe('v1')
        ->and(app(TenantContext::class)->runAs($other, fn () => Contact::query()->pluck('first_name')->all()))->toBe(['Pat'])
        ->and(DB::table('audit_events')->where('action', 'safeguards.restore.done')->exists())->toBeTrue();

    $this->actingAs($hq1)->get($url)->assertInertia(fn (Assert $p) => $p->component('hq/tenants/data')->where('restores.0.status', 'done')
        ->where('exports', fn ($e): bool => collect($e)->pluck('kind')->contains('safety')));
});

it('routes each brokerage to its own cell connection, and back', function (): void {
    config(['database.connections.cell_two' => config('database.connections.'.config('database.default'))]);
    $cell = Cell::factory()->create(['db_connection' => 'cell_two']);
    $moved = Tenant::factory()->create(['slug' => 'moved', 'cell_id' => $cell->id]);
    $ghost = Tenant::factory()->create(['slug' => 'ghost', 'cell_id' => Cell::factory()->create(['db_connection' => 'not_defined'])->id]);
    $base = Connections::cell();

    app(TenantContext::class)->runAs($moved, function (): void {
        expect(Connections::cell())->toBe('cell_two');
    });
    expect(Connections::cell())->toBe($base)
        ->and(app(TenantContext::class)->runAs($ghost, fn () => Connections::cell()))->toBe($base)
        ->and(app(CellRouting::class)->connectionFor($moved))->toBe('cell_two');
    app(TenantContext::class)->push($moved);
    expect(Connections::cell())->toBe('cell_two');
    app(TenantContext::class)->pop();
    expect(Connections::cell())->toBe($base);
    expect(BrokerageExport::tables())->toContain('crm_contacts')->not->toContain('tenants')->not->toContain('jobs');
});
