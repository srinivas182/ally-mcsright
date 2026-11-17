<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Brief\DailyBriefs;
use Modules\Crm\Models\Contact;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Pricing\Calculators;
use Modules\Pricing\CmaOpenedMail;
use Modules\Pricing\Cmas;
use Modules\Pricing\Models\Cma;

/* CM1: CMA from licensed sold comps, adjustments, range, market, net sheet, sharing; buyer cost estimate. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['state_code' => 'TX'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://x.test', 'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->n = 0;
});

function cmaLicence(object $t, array $scopes): void
{
    MlsAuthorization::query()->delete();
    $auth = MlsAuthorization::query()->create(['tenant_id' => $t->tenant->id, 'mls_source_id' => $t->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, $scopes, null, null, User::factory()->hqStaff()->make());
}

/** A home near 30.25,-97.75 (the subject's ZIP 78704). */
function cmaHome(object $t, array $o): Listing
{
    $t->n++;

    return Listing::query()->create(array_merge(['mls_source_id' => $t->source->id, 'listing_key' => 'C'.$t->n, 'standard_status' => 'Closed', 'street_number' => (string) (100 + $t->n),
        'street_name' => 'Oak St', 'city' => 'Austin', 'state_or_province' => 'TX', 'postal_code' => '78704', 'beds_total' => 3, 'baths_full' => 2, 'baths_half' => 0,
        'living_area_sqft' => 2000, 'year_built' => 2000, 'list_price_cents' => 50_000_000, 'close_price_cents' => 50_000_000, 'close_date' => '2026-07-01', 'days_on_market' => 20,
        'latitude' => 30.2500 + $t->n / 10000, 'longitude' => -97.7500, 'modification_timestamp' => now()], $o));
}

it('finds sold comps nearby with reasons, adjusts them line by line, and gives a range with its confidence', function (): void {
    cmaLicence($this, ['idx', 'back_office']);
    $subject = cmaHome($this, ['standard_status' => 'Active', 'close_price_cents' => null, 'close_date' => null, 'street_number' => '1', 'living_area_sqft' => 2100, 'beds_total' => 4]);
    foreach ([[2000, 3, 50_000_000], [2100, 4, 52_000_000], [1900, 3, 48_500_000], [2200, 4, 54_000_000], [2050, 3, 51_000_000]] as [$sqft, $beds, $price]) {
        cmaHome($this, ['living_area_sqft' => $sqft, 'beds_total' => $beds, 'close_price_cents' => $price]);
    }
    cmaHome($this, ['close_date' => '2025-01-01']); // too old
    cmaHome($this, ['living_area_sqft' => 4000]); // too big
    cmaHome($this, ['latitude' => 30.40]); // too far

    $this->actingAs($this->ann)->post('/pricing/cma', ['listing' => $subject->ulid])->assertRedirect();
    $cma = Cma::query()->sole();
    $r = $cma->result;
    expect($r['sold'])->toBeTrue()->and(count($r['comps']))->toBe(5)
        ->and($r['comps'][0]['reason'])->toContain('Same street')->toContain('sold 62 days ago')
        ->and($r['low_cents'])->toBeLessThanOrEqual($r['likely_cents'])->and($r['high_cents'])->toBeGreaterThanOrEqual($r['likely_cents'])
        ->and($r['likely_cents'])->toBeGreaterThan(49_000_000)->toBeLessThan(55_000_000)
        ->and($r['confidence'])->toBe('high');
    $small = collect($r['comps'])->firstWhere('sqft', 1900);
    $size = collect($small['adjustments'])->firstWhere('key', 'size');
    expect($size['cents'])->toBeGreaterThan(0)->and($size['label'])->toBe('Size (200 sq ft smaller)') // subject is bigger: the comp is adjusted up
        ->and(collect($small['adjustments'])->firstWhere('key', 'beds')['cents'])->toBeGreaterThan(0);

    // The agent changes an adjustment and leaves a comp out; Bob can't see Ann's CMA.
    $this->actingAs($this->ann)->patch("/pricing/cma/{$cma->ulid}", ['overrides' => [$small['listing_id'] => ['size' => 9000]]]);
    $edited = collect($cma->fresh()?->result['comps'])->firstWhere('listing_id', $small['listing_id']);
    expect(collect($edited['adjustments'])->firstWhere('key', 'size'))->toMatchArray(['cents' => 900_000, 'edited' => true]);
    $this->actingAs($this->ann)->patch("/pricing/cma/{$cma->ulid}", ['on' => [$small['listing_id'] => false]]);
    expect(count($cma->fresh()?->result['comps']))->toBe(4)->and($cma->fresh()?->result['alternates'][0]['listing_id'])->toBe($small['listing_id']);
    $this->actingAs($this->bob)->get("/pricing/cma/{$cma->ulid}")->assertForbidden();
    $this->actingAs($this->ann)->get("/pricing/cma/{$cma->ulid}")->assertInertia(fn (Assert $p) => $p->component('pricing/cma')->where('result.sold', true));
});

it('falls back to asking prices, said plainly, without a sold-data licence', function (): void {
    cmaLicence($this, ['idx']);
    foreach (range(1, 3) as $i) {
        cmaHome($this, ['standard_status' => 'Active', 'close_price_cents' => null, 'close_date' => null]);
    }
    $cma = app(Cmas::class)->create($this->ann, ['street' => '9 Oak St', 'zip' => '78704', 'sqft' => 2000, 'beds' => 3]);
    expect($cma->result['sold'])->toBeFalse()->and($cma->result['confidence'])->toBe('low')->and($cma->result['confidence_reason'])->toContain('Asking prices')
        ->and(count($cma->result['comps']))->toBe(3); // no map position: same ZIP code
    $this->actingAs($this->ann)->post('/pricing/cma', ['street' => '9 Oak St', 'zip' => '78704'])->assertSessionHasErrors('sqft');
});

it('shows the seller net sheet at three prices and the market, and shares a private link that tells the agent when it is opened', function (): void {
    cmaLicence($this, ['idx', 'back_office']);
    foreach (range(1, 5) as $i) {
        cmaHome($this, []);
    }
    $maria = Contact::query()->create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'emails' => [], 'phones' => [], 'origin' => 'personal', 'source' => 'manual', 'lifecycle' => 'lead',
        'owner_user_id' => $this->ann->id]);
    $cma = app(Cmas::class)->create($this->ann, ['street' => '9 Oak St', 'zip' => '78704', 'sqft' => 2000, 'beds' => 3, 'baths_full' => 2, 'contact' => $maria->ulid]);
    app(Cmas::class)->update($cma, ['net' => ['payoff' => 200000, 'listing_pct' => 3, 'buyer_pct' => 2.5, 'closing_pct' => 1.5, 'repairs' => 0]]);
    $net = $cma->fresh()?->result['net']['likely'];
    // $500,000: payoff 200,000; listing 15,000; buyer's agent 12,500; closing 7,500.
    expect($net['price_cents'])->toBe(50_000_000)->and($net['net_cents'])->toBe(26_500_000)
        ->and(collect($net['lines'])->firstWhere('key', 'listing')['note'])->toContain('Negotiable')
        ->and($cma->fresh()?->result['market'])->toMatchArray(['zip' => '78704', 'sold_90' => 5, 'median_dom' => 20, 'sale_to_list' => 100.0]);

    $this->actingAs($this->ann)->post("/pricing/cma/{$cma->ulid}/share")->assertSessionHas('share_url');
    $url = (string) session('share_url');
    $token = substr($url, strrpos($url, '/') + 1);
    $page = $this->get('/cma/'.$token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    expect((string) $page->getContent())->toContain('$500,000')->toContain('not an appraisal')->toContain('Estimated net');
    Mail::assertSent(CmaOpenedMail::class, fn (CmaOpenedMail $m): bool => $m->who === 'Maria Lopez' && $m->hasTo($this->ann->email));
    $this->get('/cma/'.$token);
    Mail::assertSent(CmaOpenedMail::class, 1); // at most every 12 hours
    expect($cma->fresh()?->views)->toBe(2)
        ->and(collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'cma-opened-'.$cma->ulid)['title'])->toBe('Your CMA for 9 Oak St was opened 2 times');

    $this->get('/cma/'.str_repeat('x', 40))->assertNotFound();
    $this->travel(91)->days();
    $this->get('/cma/'.$token)->assertNotFound();
    $this->actingAs($this->ann)->get("/pricing/cma/{$cma->ulid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('works out a buyer\'s monthly payment and cash to close, with loan rules, and saves and shares it', function (): void {
    $r = Calculators::buyer(['price_cents' => 40_000_000, 'down_pct' => 20, 'loan' => 'conventional', 'rate' => 6.0, 'years' => 30, 'tax_pct' => 1.8, 'insurance_pct' => 0.9, 'closing_pct' => 2.0]);
    // $320,000 at 6% for 30 years = $1,918.56; taxes $600; insurance $300; no PMI at 20% down.
    expect($r['monthly']['principal_interest'])->toBe(191_856)->and($r['monthly']['mortgage_insurance'])->toBe(0)
        ->and($r['monthly_total_cents'])->toBe(191_856 + 60_000 + 30_000)->and($r['cash_to_close_cents'])->toBe(8_000_000 + 800_000);
    $fha = Calculators::buyer(['price_cents' => 40_000_000, 'down_pct' => 1, 'loan' => 'fha', 'rate' => 6.0]);
    expect($fha['down_cents'])->toBe(1_400_000)->and($fha['upfront_fee_cents'])->toBe(675_500)->and($fha['monthly']['mortgage_insurance'])->toBeGreaterThan(0)
        ->and($fha['notes'])->toContain('FHA loans need at least 3.5% down.');

    $this->actingAs($this->ann)->postJson('/pricing/buyer/calculate', ['price' => 400000])->assertOk()->assertJsonPath('inputs.tax_pct', 1.8);
    $this->actingAs($this->ann)->post('/pricing/buyer', ['price' => 400000, 'down_pct' => 10, 'title' => 'Round Rock 3-bed'])->assertRedirect();
    $this->actingAs($this->ann)->get('/pricing')->assertInertia(fn (Assert $p) => $p->component('pricing/index')->where('sheets.0.title', 'Round Rock 3-bed'));
    $this->actingAs($this->owner)->post('/pricing/defaults', ['interest_rate' => 7.25])->assertRedirect();
    $this->actingAs($this->ann)->postJson('/pricing/buyer/calculate', ['price' => 400000])->assertJsonPath('inputs.rate', 7.25);
    $this->actingAs($this->ann)->post('/pricing/defaults', ['interest_rate' => 5])->assertForbidden();
});
