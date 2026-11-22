<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Idx\DemoListings;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Samples\SampleHomes;

/*
| TP1: sample homes. Every website shows real-looking homes with licensed photos from the day it is made, in the
| brokerage's own state, until its first MLS board goes live. They are samples, never indexed, and cost no AI.
*/

beforeEach(function (): void {
    config(['listings.sample_homes' => true, 'core.hq_host' => 'app.rightally.io']);
    Storage::fake('public');
    $jpeg = (function (): string {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    })();
    Http::fake(['images.pexels.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
    $this->artisan('tenants:bootstrap-hq');
});

it('lists only hand-picked Pexels photos, each used once, for every kind a sample home needs', function (): void {
    $groups = SampleHomes::photoGroups();
    foreach (['exterior_luxury', 'exterior_family', 'exterior_coastal', 'exterior_townhome', 'exterior_condo', 'living', 'kitchen', 'bedroom', 'bathroom', 'dining', 'backyard'] as $kind) {
        expect($groups[$kind] ?? [])->not->toBeEmpty();
    }
    $ids = [];
    foreach ($groups as $list) {
        foreach ($list as $p) {
            expect($p['image'])->toStartWith('https://images.pexels.com/photos/'.$p['id'].'/')->and($p['page'])->toBe('https://www.pexels.com/photo/'.$p['id'].'/');
            $ids[] = $p['id'];
        }
    }
    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('installs sample homes for every launch state, with stored photos already labelled, and installs again without duplicates', function (): void {
    $this->artisan('listings:sample-homes')->assertSuccessful();

    $sources = MlsSource::query()->where('key', 'like', 'sample-%')->get();
    expect($sources)->toHaveCount(5)->and($sources->every(fn (MlsSource $s): bool => $s->is_demo && $s->credentials === null))->toBeTrue();
    expect(Listing::query()->whereIn('mls_source_id', $sources->pluck('id'))->count())->toBe(84);

    $home = Listing::query()->where('listing_key', 'STX001')->sole();
    expect($home)->city->toBe('Houston')->state_or_province->toBe('TX')->list_office_name->toBe(SampleHomes::OFFICE)
        ->photos_count->toBe(6)->photos_tagged_at->not->toBeNull()->places_at->not->toBeNull();
    $media = ListingMedia::query()->where('listing_id', $home->id)->orderBy('position')->get();
    expect($media)->toHaveCount(6)->and($media->every(fn (ListingMedia $m): bool => $m->status === 'stored' && $m->room !== null && $m->tagged_at !== null))->toBeTrue()
        ->and($media->first()->room)->toBe('front');
    Http::assertSent(fn ($r): bool => str_starts_with($r->url(), 'https://images.pexels.com/photos/'));

    // Again: same homes, nothing downloaded twice.
    $sent = count(Http::recorded());
    $this->artisan('listings:sample-homes')->assertSuccessful();
    expect(Listing::query()->whereIn('mls_source_id', $sources->pluck('id'))->count())->toBe(84)->and(count(Http::recorded()))->toBe($sent);
});

it('does nothing when sample homes are switched off', function (): void {
    config(['listings.sample_homes' => false]);
    $this->artisan('listings:sample-homes')->assertSuccessful();
    expect(MlsSource::query()->where('key', 'like', 'sample-%')->count())->toBe(0);
});

it('shows sample homes in the brokerage\'s own state until its first board is live', function (): void {
    app(SampleHomes::class)->install();
    $tx = SampleHomes::sourceIds('TX');
    $fl = SampleHomes::sourceIds('FL');
    $acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $control = DB::connection(Connections::control());

    // No board and no sign-up state: every state's samples.
    expect(app(MlsAccess::class)->licensedSources($acme->id))->toEqualCanonicalizing(SampleHomes::sourceIds());

    // Signed up in Florida: Florida's samples.
    $control->table('signups')->insert(['ulid' => (string) str()->ulid(), 'type' => 'brokerage', 'brokerage' => 'Acme', 'slug' => 'acme', 'owner_name' => 'A B',
        'owner_email' => 'a@acme.test', 'state' => 'FL', 'status' => 'live', 'tenant_id' => $acme->id, 'created_at' => now(), 'updated_at' => now()]);
    DemoListings::forget($acme->id);
    expect(app(MlsAccess::class)->licensedSources($acme->id))->toEqualCanonicalizing($fl);

    // A Texas board waiting for approval: Texas samples.
    $har = $control->table('idx_boards')->where('code', 'HAR')->value('id');
    $control->table('idx_subscriptions')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $acme->id, 'board_id' => $har, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    DemoListings::forget($acme->id);
    expect(app(MlsAccess::class)->licensedSources($acme->id))->toEqualCanonicalizing($tx);

    // The brokerage switched samples off.
    $owner = app(TenantContext::class)->runAs($acme, fn () => User::factory()->owner()->create());
    app(DemoListings::class)->set($acme->id, false, $owner);
    expect(app(MlsAccess::class)->licensedSources($acme->id))->toBe([]);
    app(DemoListings::class)->set($acme->id, true, $owner);

    // The board goes live: its own listings replace the samples.
    $control->table('idx_subscriptions')->where('tenant_id', $acme->id)->update(['status' => 'live']);
    DemoListings::forget($acme->id);
    expect(app(DemoListings::class)->sourcesFor($acme->id))->toBe([]);
});
