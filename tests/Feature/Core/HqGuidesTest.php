<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ4: the HQ console's menu guides: their lesson videos and closing a guide work on the HQ host, for everyone in HQ.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
});

it('gives HQ its own guide videos', function (): void {
    DB::connection(Connections::control())->table('help_videos')->insert([
        ['path' => 'guide:hq-customers:go-live', 'title' => 'Go-live', 'url' => 'https://example.com/a.mp4', 'created_at' => now(), 'updated_at' => now()],
        ['path' => 'guide:crm:new-leads', 'title' => 'New leads', 'url' => 'https://example.com/b.mp4', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($this->staff)->getJson('http://app.rightally.io/hq/guides/videos')->assertOk()
        ->assertJsonPath('guide:hq-customers:go-live', 'https://example.com/a.mp4')
        ->assertJsonMissingPath('guide:crm:new-leads');
});

it('stops a guide opening by itself once closed, for HQ guides only', function (): void {
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/guides/seen', ['key' => 'HQ Customers'])->assertRedirect();
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/guides/seen', ['key' => 'CRM'])->assertRedirect();

    expect($this->staff->fresh()->ui_guides_seen)->toBe(['HQ Customers']);
});

it('is never open to a brokerage', function (): void {
    $brokerage = Tenant::factory()->create();
    $owner = app(TenantContext::class)->runAs($brokerage, fn () => User::factory()->owner()->create());
    expect($this->actingAs($owner)->getJson('http://app.rightally.io/hq/guides/videos')->status())->toBeIn([302, 401, 403, 404]);
});
