<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Usage\AssistantAllowance;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| AO1: a brokerage's website assistant gets its package's monthly conversations, unless HQ gives this brokerage its
| own number (more for a busy brokerage, fewer to hold costs); HQ can go back to the package's number.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $this->admin = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $this->jones = Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones']);
});

it('lets HQ give one brokerage its own monthly number of assistant conversations', function (): void {
    expect(AssistantAllowance::for($this->jones->id))->toBe(['package' => 500, 'override' => null, 'monthly' => 500]);
    $hq = 'http://app.rightally.io/hq/tenants/'.$this->jones->ulid;
    $this->actingAs($this->admin)->get($hq)->assertInertia(fn (Assert $p) => $p->where('assistant.monthly', 500));

    $this->actingAs($this->admin)->post($hq.'/assistant-limit', ['monthly' => 2500])->assertRedirect();
    expect(AssistantAllowance::for($this->jones->id))->toBe(['package' => 500, 'override' => 2500, 'monthly' => 2500]);
    $this->actingAs($this->admin)->post($hq.'/assistant-limit', ['monthly' => -3])->assertSessionHasErrors('monthly');

    $this->actingAs($this->admin)->post($hq.'/assistant-limit', ['monthly' => null])->assertRedirect();
    expect(AssistantAllowance::for($this->jones->id)['monthly'])->toBe(500);
});

it('is only for HQ', function (): void {
    $owner = app(TenantContext::class)->runAs($this->jones, fn () => User::factory()->owner()->create());
    $this->actingAs($owner)->post('http://jones.rightally.app/hq/tenants/'.$this->jones->ulid.'/assistant-limit', ['monthly' => 99999])->assertNotFound();
    expect(AssistantAllowance::for($this->jones->id)['override'])->toBeNull();
});
