<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Jobs\FetchListingPhotos;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Sync\ListingSync;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17B-1: listing photos on our own storage, price history, and licensed listing search. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Storage::fake('public');
    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->jpeg = (function (): string {
        $img = imagecreatetruecolor(2000, 1500);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 160, 200));
        ob_start();
        imagejpeg($img, null, 70);

        return (string) ob_get_clean();
    })();
});

function home(int $i, array $over = []): array
{
    return $over + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 300000 + $i * 50000,
        'OriginalListPrice' => 300000 + $i * 50000, 'BedroomsTotal' => 2 + ($i % 3), 'BathroomsFull' => 2, 'LivingArea' => 1500 + $i * 100, 'StreetNumber' => (string) (10 + $i),
        'StreetName' => 'Oak St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => $i % 2 ? '78704' : '78701', 'Latitude' => 30.20 + $i / 100, 'Longitude' => -97.75,
        'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
        'ModificationTimestamp' => '2026-05-20T00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00Z'];
}

function licence(object $t, int $tenantId): void
{
    $a = MlsAuthorization::query()->create(['tenant_id' => $tenantId, 'mls_source_id' => $t->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
}

it('stores each MLS photo once as WebP in three sizes, refetches only when it changes, and drops removed photos', function (): void {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('GD without WebP');
    }
    Http::fake(['photos.example/*' => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
    $media = [['MediaKey' => 'm1', 'MediaURL' => 'https://photos.example/1.jpg', 'Order' => 1, 'MediaModificationTimestamp' => '2026-05-01T00:00:00Z'],
        ['MediaKey' => 'm2', 'MediaURL' => 'https://photos.example/2.jpg', 'Order' => 0, 'ShortDescription' => 'Front']];
    $this->feed->records = [home(1, ['Media' => $media])];
    app(ListingSync::class)->run($this->source);

    $l = Listing::query()->sole();
    $m2 = ListingMedia::query()->where('media_key', 'm2')->sole();
    expect(ListingMedia::query()->pluck('status')->unique()->all())->toBe(['stored'])->and($m2->width)->toBe(2000)
        ->and($l->refresh()->cover_path)->toBe($m2->storage_path)
        ->and(Photos::url($l->cover_path, 'hero'))->toEndWith('-1600.webp');
    foreach ([400, 800, 1600] as $w) {
        Storage::disk('public')->assertExists("{$m2->storage_path}-{$w}.webp");
    }
    expect(getimagesizefromstring((string) Storage::disk('public')->get("{$m2->storage_path}-400.webp"))[0])->toBe(400);
    Http::assertSentCount(2);

    // The listing changes but the photos do not: nothing is downloaded again.
    $this->feed->records = [home(1, ['ListPrice' => 340000, 'ModificationTimestamp' => '2026-05-21T00:00:00Z', 'Media' => $media])];
    app(ListingSync::class)->run($this->source->refresh());
    Http::assertSentCount(2);

    // One photo replaced, one removed.
    $this->feed->records = [home(1, ['ModificationTimestamp' => '2026-05-22T00:00:00Z', 'Media' => [['MediaKey' => 'm1', 'MediaURL' => 'https://photos.example/1.jpg', 'Order' => 1,
        'MediaModificationTimestamp' => '2026-05-22T00:00:00Z']]])];
    app(ListingSync::class)->run($this->source->refresh());
    Http::assertSentCount(3);
    expect(ListingMedia::query()->pluck('media_key')->all())->toBe(['m1']);
    Storage::disk('public')->assertMissing("{$m2->storage_path}-800.webp");
});

it('only downloads public https images, and gives up on a broken photo after a day', function (): void {
    Http::fake(['bad.example/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);
    $this->feed->records = [home(1, ['Media' => [['MediaKey' => 'a', 'MediaURL' => 'http://photos.example/a.jpg'], ['MediaKey' => 'b', 'MediaURL' => 'https://10.0.0.5/b.jpg'],
        ['MediaKey' => 'c', 'MediaURL' => 'https://bad.example/c.jpg']]])];
    app(ListingSync::class)->run($this->source);
    $errors = ListingMedia::query()->orderBy('media_key')->pluck('error', 'media_key')->all();
    expect($errors['a'])->toBe('Only public https photo addresses are fetched.')->and($errors['b'])->toBe('Only public https photo addresses are fetched.')
        ->and($errors['c'])->toBe('Not an image (text/html).')->and(ListingMedia::query()->pluck('status')->unique()->all())->toBe(['pending']);
    Http::assertSentCount(1); // the private and plain-http addresses were never requested

    $this->travel(25)->hours();
    (new FetchListingPhotos(Listing::query()->sole()->id))->handle(app(Photos::class));
    expect(ListingMedia::query()->pluck('status')->unique()->all())->toBe(['failed']);
});

it('keeps price history and shows it on the listing', function (): void {
    $this->feed->records = [home(1)];
    app(ListingSync::class)->run($this->source);
    $this->feed->records = [home(1, ['ListPrice' => 325000, 'ModificationTimestamp' => '2026-05-25T00:00:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    $tenant = $this->onTenantHost('acme');
    licence($this, $tenant->id);

    $l = Listing::query()->sole();
    expect($l->price_changed_at?->toDateString())->toBe('2026-05-25')
        ->and(app(ListingSearch::class)->find($tenant->id, $l->ulid)['price_history'])->toBe([['date' => '2026-05-25', 'from' => 350000, 'to' => 325000]]);
});

it('searches only licensed MLSs, with filters, map area, sorting and paging', function (): void {
    $this->feed->records = array_merge(array_map(fn (int $i): array => home($i), range(1, 8)), [
        home(9, ['StandardStatus' => 'Pending']), home(10, ['InternetEntireListingDisplayYN' => false]), home(11, ['City' => 'Round Rock', 'PostalCode' => '78664', 'Latitude' => 30.5]),
    ]);
    app(ListingSync::class)->run($this->source);
    $tenant = $this->onTenantHost('acme');
    $search = fn (array $f): array => app(ListingSearch::class)->search($tenant->id, $f);

    expect($search([])['total'])->toBe(0); // no licence yet
    licence($this, $tenant->id);

    expect($search([])['total'])->toBe(9) // pending and no-internet-display excluded
        ->and($search(['statuses' => 'Active,Pending'])['total'])->toBe(10)
        ->and($search(['q' => '78704'])['total'])->toBe(4)
        ->and($search(['q' => 'Round Rock'])['total'])->toBe(1)
        ->and($search(['q' => '13 Oak'])['results'][0]['mls_number'])->toBe('MLS3')
        ->and($search(['q' => 'MLS5'])['total'])->toBe(1)
        ->and($search(['min_price' => 500000, 'max_price' => 600000])['total'])->toBe(3)
        ->and($search(['beds' => 4])['total'])->toBe(4)
        ->and($search(['bounds' => '30.20,-97.80,30.255,-97.70'])['total'])->toBe(5)
        ->and(array_column($search(['sort' => 'price_asc', 'per_page' => 3])['results'], 'price'))->toBe([350000, 400000, 450000])
        ->and($search(['sort' => 'price_asc', 'per_page' => 3, 'page' => 3])['results'][0]['price'])->toBe(650000);
    expect($search(['per_page' => 500])['per_page'])->toBe(48);
});

it('shows agents the Listings pages and hides listings the brokerage is not licensed for', function (): void {
    $this->feed->records = [home(1)];
    app(ListingSync::class)->run($this->source);
    $tenant = $this->onTenantHost('acme');
    $agent = User::factory()->create();
    $ulid = Listing::query()->sole()->ulid;

    $this->actingAs($agent)->get('/listings')->assertInertia(fn (Assert $page) => $page->component('listings/index')->where('licensed', false)->where('total', 0));
    $this->actingAs($agent)->get('/listings/'.$ulid)->assertNotFound();
    licence($this, $tenant->id);
    $this->actingAs($agent)->get('/listings?q=Austin')->assertInertia(fn (Assert $page) => $page->where('licensed', true)->where('total', 1)
        ->where('results.0.attribution', 'Listing courtesy of Acme Realty'));
    assertPageContract($this->actingAs($agent)->get('/listings'), 'listings/index');
    assertPageContract($this->actingAs($agent)->get('/listings/'.$ulid), 'listings/show');
});
