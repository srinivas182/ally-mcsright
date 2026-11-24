<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\Mail\IdxUpdateMail;

/*
| IDX8: "My board isn't listed" (the brokerage asks, HQ adds or closes, everyone who asked is emailed when it opens),
| and agents inherit the brokerage's boards on their own website.
*/

beforeEach(function (): void {
    Mail::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme', 'name' => 'Acme Realty']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'owner@acme.test']));
    $this->oak = Tenant::factory()->withPlatformDomain()->create(['slug' => 'oak', 'name' => 'Oak Realty']);
    $this->oakOwner = app(TenantContext::class)->runAs($this->oak, fn () => User::factory()->owner()->create(['email' => 'owner@oak.test']));
    $this->db = DB::connection(Connections::control());
});

function boardFields(string $code, string $name): array
{
    return ['code' => $code, 'name' => $name, 'state' => 'TX', 'other_states' => [], 'region' => null, 'visibility' => 'public', 'approval_days_min' => 3, 'approval_days_max' => 7,
        'main_connector' => 'repliers', 'main_cost_cents' => 3_000, 'backup_connector' => null, 'backup_cost_cents' => null, 'connector_code' => $code, 'monthly_fee_cents' => null,
        'setup_fee_cents' => 0, 'rules_profile_id' => null, 'checked' => true, 'notes' => null];
}

it('lets a brokerage ask for a missing board, and HQ add it and open it, emailing everyone who asked', function (): void {
    $ask = ['name' => 'Golden Spread Association of Realtors', 'state' => 'TX', 'website' => 'https://example.org'];
    $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/requests', $ask)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/requests', $ask)->assertSessionHasNoErrors(); // the same again: one request
    $this->actingAs($this->oakOwner)->post('http://oak.rightally.app/settings/mls/requests', ['name' => 'golden spread association of realtors', 'state' => 'TX'])->assertSessionHasNoErrors();
    expect($this->db->table('idx_board_requests')->count())->toBe(2);

    // Each brokerage sees only its own request.
    $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/mls')->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.status', 'open'));

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/idx')->assertInertia(fn (Assert $page) => $page->has('requests', 2)->where('requests.0.same', 2));
    $first = (string) $this->db->table('idx_board_requests')->orderBy('id')->value('ulid');
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/requests/'.$first.'/add', ['code' => 'gsar'])->assertSessionHasNoErrors();
    $board = $this->db->table('idx_boards')->where('code', 'GSAR')->first();
    expect($board->visibility)->toBe('hidden')->and($this->db->table('idx_board_requests')->where('board_id', $board->id)->count())->toBe(2);
    Mail::assertNothingQueued();

    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$board->ulid, boardFields('GSAR', $board->name))->assertSessionHasNoErrors();
    Mail::assertQueued(IdxUpdateMail::class, 2);
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m) => $m->hasTo('owner@oak.test') && str_contains($m->heading, 'is ready for your website'));

    // Saving it again emails nobody twice.
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/idx/boards/'.$board->ulid, boardFields('GSAR', $board->name))->assertSessionHasNoErrors();
    Mail::assertQueued(IdxUpdateMail::class, 2);
});

it('closes a request with a reason the brokerage sees, and keeps brokerages to their owners and a sensible number', function (): void {
    $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/requests', ['name' => 'Tiny Board', 'state' => 'TX'])->assertSessionHasNoErrors();
    $ulid = (string) $this->db->table('idx_board_requests')->value('ulid');
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/requests/'.$ulid.'/close', ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/requests/'.$ulid.'/close', ['note' => 'This board shares its listings with HAR; add HAR instead.'])->assertSessionHasNoErrors();
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m) => $m->hasTo('owner@acme.test') && in_array('This board shares its listings with HAR; add HAR instead.', $m->lines, true));
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/requests/'.$ulid.'/add', ['code' => 'TINY'])->assertSessionHasErrors('request');

    $agent = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->create());
    $this->actingAs($agent)->post('http://acme.rightally.app/settings/mls/requests', ['name' => 'Another', 'state' => 'TX'])->assertForbidden();

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/requests', ['name' => 'Board number '.$i, 'state' => 'TX']);
    }
    $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/requests', ['name' => 'One too many', 'state' => 'TX'])->assertSessionHasErrors('name');
});

it('shows agents the boards their brokerage has, read-only, on My website', function (): void {
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'main_connector' => 'repliers', 'main_cost_cents' => 4_000]);
    $this->db->table('idx_states')->where('code', 'TX')->update(['enabled' => true]);
    $har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
    giveLicence(Tenant::query()->where('slug', 'acme')->sole());
    $this->actingAs($this->owner)->post('http://acme.rightally.app/settings/mls/boards', ['board' => $har])->assertSessionHasNoErrors();

    $agent = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->create());
    $this->actingAs($agent)->get('http://acme.rightally.app/settings/my-website')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('mls_boards', 1)->where('mls_boards.0.status', 'demo')->where('mls_boards.0.name', fn ($n) => str_contains((string) $n, 'HAR')));
    // Another brokerage's agents see none of them.
    $other = app(TenantContext::class)->runAs($this->oak, fn () => User::factory()->create());
    $this->actingAs($other)->get('http://oak.rightally.app/settings/my-website')->assertOk()->assertInertia(fn (Assert $page) => $page->has('mls_boards', 0));
});
