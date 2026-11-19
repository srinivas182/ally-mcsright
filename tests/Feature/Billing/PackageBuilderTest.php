<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Builder\PackageQuote;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| PB1: the package builder — choose modules, see what they cost RightAlly (with the sum behind each), price at 70%
| markup (cost 100 → price 170, rounded to end in 9), and a written reason for any price under cost + 40%.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    // Known prices: $2 per person a month, no hosting; texts 1.33¢.
    $db = DB::connection(Connections::control())->table('cost_catalog');
    $db->clone()->where('key', 'platform.seat')->update(['cost_microcents' => 200_000_000]);
    $db->clone()->where('key', 'hosting.brokerage')->update(['cost_microcents' => 0]);
});

it('prices a package from its modules: cost with the sums behind it, then 70% markup ending in 9', function (): void {
    $q = app(PackageQuote::class)->quote('brokerage', ['texting'], 10, ['texting' => ['texts' => 1000]]);

    // 10 people × 1,000 texts × 1.33¢ = $133.00; 10 × $2 = $20; card fees on the price.
    $texting = collect($q['lines'])->firstWhere('key', 'texting');
    expect($q['modules'])->toContain('back_office') // always included
        ->and($texting['cost_cents'])->toBe(13_300)
        ->and($texting['items'][0]['tip'])->toBe('10,000 texts × $0.0133 = $133.00 (Twilio $0.0083 + carrier fee up to $0.005)')
        ->and($q['fixed']['per_seat_cents'])->toBe(2_000)
        ->and($q['markup_pct'])->toBe(70)
        ->and($q['price_cents'] % 1000)->toBe(900) // ends in 9 dollars
        ->and($q['price_cents'])->toBeGreaterThanOrEqual((int) ceil($q['cost_cents'] * 1.7))
        ->and($q['price_cents'])->toBeLessThan((int) ceil($q['cost_cents'] * 1.7) + 1000)
        ->and($q['margin_pct'])->toBeBetween(39, 45)
        ->and($q['included']['texts'])->toBe(10_000);
    expect(PackageQuote::roundPrice(163))->toBe(16_900)->and(PackageQuote::roundPrice(169))->toBe(16_900)->and(PackageQuote::roundPrice(3))->toBe(900);
});

it('offers solo agents only the modules made for them, and shows what everything on would cost', function (): void {
    $q = app(PackageQuote::class)->quote('agent', ['accounting', 'recruiting', 'crm'], 1);
    expect($q['modules'])->toContain('crm')->not->toContain('accounting')->not->toContain('recruiting');

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/packages')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/packages')
        ->where('rules.markup', 70)->where('rules.min_markup', 40)
        ->where('everything.brokerage.modules', fn ($m): bool => count($m) === 17)->has('catalog', 17));
});

it('saves a package with its modules, features and allowances, and needs a reason for a price under cost + 40%', function (): void {
    $payload = ['name' => 'Brokerage Pro', 'audience' => 'brokerage', 'modules' => ['crm', 'crm_ai', 'website', 'texting'], 'seats' => 5, 'usage' => ['texting' => ['texts' => 200]]];
    $quote = $this->actingAs($this->staff)->postJson('http://app.rightally.io/hq/packages/quote', $payload)->assertOk()->json();

    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/packages', $payload)->assertRedirect('/hq/packages');
    $p = Package::query()->where('name', 'Brokerage Pro')->sole();
    expect($p->monthly_cents)->toBe($quote['price_cents'])->and($p->yearly_cents)->toBe($quote['price_cents'] * 10)
        ->and($p->features)->toEqualCanonicalizing(['backoffice.minimal', 'backoffice.basic', 'crm.ai', 'website'])
        ->and((int) $p->getAttribute('included_text_segments'))->toBe(1_000)
        ->and((int) $p->getAttribute('included_map_views'))->toBe(2_000);

    $cheap = (int) floor($quote['cost_cents'] * 1.2 / 100);
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/packages/'.$p->ulid, $payload + ['price' => $cheap])->assertSessionHasErrors('price');
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/packages/'.$p->ulid, $payload + ['price' => $cheap, 'reason' => 'Launch offer until March'])->assertSessionHasNoErrors();
    expect($p->fresh()->getAttribute('price_reason'))->toBe('Launch offer until March');
});

it('keeps the builder to HQ', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/hq/packages')->assertNotFound();
});
