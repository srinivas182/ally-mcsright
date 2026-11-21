<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;

/*
| IDX5: demo listings. A brokerage that has added a board but has none live shows sample listings (from feeds HQ marks
| as demo), marked as samples and never indexed, until its first board goes live. The brokerage can switch them off.
| MLS notices come only from HQ's display templates; a brokerage cannot change them.
*/

beforeEach(function (): void {
    Mail::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create());
    $this->rules = RulesProfile::query()->create(['name' => 'Sample', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Sample data from Repliers. Not real listings.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 1440, 'index_off_market' => false]);
    $this->demo = MlsSource::query()->create(['key' => 'demo', 'name' => 'Repliers sample', 'states' => ['TX'], 'provider' => 'repliers', 'base_url' => 'https://api.repliers.io',
        'status' => 'active', 'is_demo' => true, 'rules_profile_id' => $this->rules->id, 'last_success_at' => now()]);
    $this->feed = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'repliers', 'base_url' => 'https://api.repliers.io', 'status' => 'active']);
    DB::connection(Connections::control())->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 5_000, 'mls_source_id' => $this->feed->id]);
    $this->har = (string) DB::connection(Connections::control())->table('idx_boards')->where('code', 'HAR')->value('ulid');
});

it('shows demo listings only while a board is waiting, and the first live board replaces them', function (): void {
    $access = app(MlsAccess::class);
    expect($access->licensedSources($this->acme->id))->toBe([]); // no board yet: nothing

    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    expect($access->licensedSources($this->acme->id))->toBe([$this->demo->id])
        ->and($access->licensedSources($this->acme->id, 'back_office'))->toBe([]); // websites only

    $subs->submit($this->acme->id, $ulid, ['member_id' => 'M1', 'license_number' => 'L1', 'managing_broker_name' => 'Dana', 'managing_broker_email' => 'dana@acme.test'], $this->owner);
    $subs->move($ulid, 'sent', null, $this->staff);
    $subs->goLive($ulid, null, null, $this->staff);
    expect($access->licensedSources($this->acme->id))->toBe([$this->feed->id]);
});

it('lets the brokerage switch demo listings off and on', function (): void {
    app(Subscriptions::class)->add($this->acme->id, $this->har, $this->owner);
    $this->actingAs($this->owner)->put('http://acme.rightally.app/settings/mls/demo', ['on' => false])->assertRedirect();
    expect(app(MlsAccess::class)->licensedSources($this->acme->id))->toBe([]);
    $this->actingAs($this->owner)->put('http://acme.rightally.app/settings/mls/demo', ['on' => true])->assertRedirect();
    expect(app(MlsAccess::class)->licensedSources($this->acme->id))->toBe([$this->demo->id]);
});

it('marks demo listings as samples, never indexed, with HQ\'s notice', function (): void {
    $l = Listing::query()->create(['mls_source_id' => $this->demo->id, 'listing_key' => 'S1', 'standard_status' => 'Active', 'list_price_cents' => 30_000_000,
        'internet_display_allowed' => true, 'address_display_allowed' => true, 'list_office_name' => 'Sample Realty', 'modification_timestamp' => now()]);
    $shown = ListingPresenter::forDisplay($l->load('source.rulesProfile'));
    expect($shown)->toMatchArray(['sample' => true, 'indexable' => false, 'disclaimer' => 'Sample data from Repliers. Not real listings.']);
});

it('never lets a brokerage change MLS notices: the display templates are HQ only', function (): void {
    foreach (['/hq/mls/profiles', '/hq/mls/sources/'.$this->demo->ulid] as $path) {
        foreach (['post', 'patch'] as $method) {
            expect($this->actingAs($this->owner)->{$method}('http://acme.rightally.app'.$path, ['disclaimer' => 'changed'])->status())->toBeIn([403, 404, 405]);
        }
    }
    expect($this->rules->refresh()->disclaimer)->toBe('Sample data from Repliers. Not real listings.');
});
