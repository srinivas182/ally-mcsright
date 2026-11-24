<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;
use Modules\Roster\Brokerage\LicenceNotice;
use Modules\Roster\Verification\ServiceVerifier;
use Modules\Sites\SiteSetup;

/*
| LV2: the brokerage's own licences (Settings › Licences, owners), checked against the states' records every day, with
| warnings before expiry. Boards need an active licence in their state, and the website stays "coming soon" until one
| licence is active. HQ can mark a licence as checked by hand.
*/

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
    ServiceVerifier::load('TX', "License Number,Last Name,License Status,Expiration Date\n0654321,Brooks,Active,".CarbonImmutable::today()->addDays(45)->toDateString()."\n0777777,Brooks,Expired,2025-01-31\n");
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Dana', 'last_name' => 'Brooks']);
});

it('lets owners add licences, checking each in the state records, and only owners', function (): void {
    $this->actingAs($this->owner)->get('/settings/licences')->assertOk();
    $this->actingAs($this->owner)->post('/settings/licences', ['state' => 'TX', 'number' => '0123999'])->assertSessionHasErrors('number');
    $this->actingAs($this->owner)->post('/settings/licences', ['state' => 'TX', 'number' => '0654321'])->assertSessionHasNoErrors();
    $licences = app(BrokerageLicences::class);
    expect($licences->goodStates())->toBe(['TX'])->and($licences->all()[0]['status'])->toBe('active');

    $agent = User::factory()->create(['kind' => UserKind::Agent]);
    $this->actingAs($agent)->get('/settings/licences')->assertForbidden();
});

it('keeps the website "coming soon" until a licence is active', function (): void {
    $licences = app(BrokerageLicences::class);
    $licences->save('TX', '0777777', 'Dana Brooks', 'expired', '2025-01-31');
    $site = app(SiteSetup::class)->create('modern', ['area' => 'Austin'], $this->owner);
    expect(fn () => app(SiteSetup::class)->publish($site, true, $this->owner))->toThrow(ValidationException::class, 'once one of your licences is active');
    $licences->save('TX', '0654321', 'Dana Brooks', 'active', null);
    app(SiteSetup::class)->publish($site, true, $this->owner);
    expect($site->fresh()->status)->toBe('live');
});

it('checks licences again every day and warns owners before one expires, once per step', function (): void {
    app(BrokerageLicences::class)->save('TX', '0654321', 'Dana Brooks', 'active', null);
    $this->artisan('roster:brokerage-licences')->assertSuccessful();
    Notification::assertSentTo($this->owner, LicenceNotice::class, fn (LicenceNotice $n): bool => str_contains($n->what, 'expires in 45 days'));
    $this->artisan('roster:brokerage-licences')->assertSuccessful();
    Notification::assertSentToTimes($this->owner, LicenceNotice::class, 1);
    expect(app(BrokerageLicences::class)->all()[0]['expires_on'])->toBe(CarbonImmutable::today()->addDays(45)->toDateString());
});

it('lets HQ mark a licence as checked by hand, so boards can be added in that state', function (): void {
    app(BrokerageLicences::class)->save('LA', 'BR-1234', 'Dana Brooks', 'new', null);
    expect(app(BrokerageLicences::class)->goodStates())->toBe([]);
    $id = app(BrokerageLicences::class)->all()[0]['id'];
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $admin = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $this->actingAs($admin)->post('http://app.rightally.io/hq/tenants/'.$this->tenant->ulid.'/licences/'.$id.'/checked')->assertRedirect();
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => app(BrokerageLicences::class)->goodStates()))->toBe(['LA']);
});
