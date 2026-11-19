<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\Economics;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Models\Package;
use Modules\Billing\Usage\AllowanceUsed;
use Modules\Billing\Usage\MapAllowanceReached;
use Modules\Billing\Usage\MeteredSmsSender;
use Modules\Billing\Usage\MeteredVoiceCaller;
use Modules\Billing\Usage\TextSegments;
use Modules\Billing\Usage\UsageMeter;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\FakeVoiceCaller;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Maps;
use Modules\Sites\SiteSetup;

/* Sprint 18A-2b: monthly allowances for texts, call minutes and website map views; extra use draws credits. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->package = Package::query()->create(['name' => 'Growth', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);
    $this->package->forceFill(['included_text_segments' => 10, 'included_call_minutes' => 4, 'included_map_views' => 20])->save();
    DB::connection(Connections::control())->table('cost_catalog')->where('kind', 'monthly')->update(['cost_microcents' => 0]); // no per-brokerage costs here
    DB::connection(Connections::control())->table('billing_tenant_subscriptions')->insert(['tenant_id' => $this->tenant->id, 'package_id' => $this->package->id,
        'interval' => 'month', 'status' => 'active', 'price_cents' => 10_000, 'created_at' => now(), 'updated_at' => now()]);
    $this->texts = new FakeSmsSender;
    $this->calls = new FakeVoiceCaller;
    app()->instance(SmsSender::class, new MeteredSmsSender($this->texts, app(TenantContext::class)));
    app()->instance(VoiceCaller::class, new MeteredVoiceCaller($this->calls, app(TenantContext::class)));
});

function pool(): array
{
    return app(CreditLedger::class)->poolSummary();
}

it('counts text segments the way carriers bill them', function (): void {
    expect(TextSegments::count(str_repeat('a', 160)))->toBe(1)
        ->and(TextSegments::count(str_repeat('a', 161)))->toBe(2)
        ->and(TextSegments::count(str_repeat('a', 306)))->toBe(2)
        ->and(TextSegments::count(str_repeat('€', 80)))->toBe(1)   // extended GSM: 2 each = 160
        ->and(TextSegments::count('Hi 😀'))->toBe(1)                 // Unicode: up to 70
        ->and(TextSegments::count(str_repeat('é😀', 36)))->toBe(2); // 72 Unicode characters
});

it('counts texts against the allowance, then charges credits per segment and tells the owner', function (): void {
    app(CreditLedger::class)->add(null, 100, 'adjust', 'test', $this->owner);
    foreach (range(1, 8) as $i) {
        app(SmsSender::class)->send('+15125550100', 'Deadline today '.$i);
    }
    expect(app(UsageMeter::class)->used('texts'))->toBe(8)->and(pool()['available'])->toBe(100);
    Notification::assertSentToTimes($this->owner, AllowanceUsed::class, 1); // 80%

    app(SmsSender::class)->send('+15125550100', str_repeat('a', 300)); // 2 segments → 10, at the allowance
    app(SmsSender::class)->send('+15125550100', str_repeat('a', 300)); // 2 more, over by 2
    expect(app(UsageMeter::class)->summary()['texts'])->toBe(['used' => 12, 'included' => 10, 'label' => 'texts'])
        ->and(pool()['available'])->toBe(92) // 2 segments × 4 credits
        ->and(app(CreditLedger::class)->usage()['automations'])->toBe(8);
    Notification::assertSentToTimes($this->owner, AllowanceUsed::class, 2); // and 100%
    expect($this->texts->sent)->toHaveCount(10);
});

it('holds back inbox texts when the allowance and credits are used up, but never system alerts', function (): void {
    app(RateCard::class)->saveEnforced(true, $this->staff);
    DB::connection(Connections::cell())->table('usage_meters')->insert(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'meter' => 'texts', 'used' => 10, 'over' => 0,
        'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => app(SmsSender::class)->deliver('+15125550199', '+15125550100', 'Hi Maria'))->toThrow(InsufficientCredits::class, 'included texts');
    expect($this->texts->delivered)->toBe([]);

    app(SmsSender::class)->send('+15125550100', '482913 is your code to sign documents.'); // security code: always sent
    expect($this->texts->sent)->toHaveCount(1)->and(pool()['available'])->toBe(-4);
});

it('counts calls at an estimate, then settles the real minutes from Twilio once', function (): void {
    app(MessagingSettings::class)->save('AC'.str_repeat('1', 32), 'twilio-auth-token-0000000', '+15125550111', '+15125550112', true, true, $this->staff);
    $sid = app(VoiceCaller::class)->call('+15125550100', 'This is RightAlly for Acme. Inspection for 11 Oak St is due today.');
    expect(app(UsageMeter::class)->used('calls'))->toBe(1);

    $url = 'http://app.rightally.io/billing/twilio/call-status';
    $params = ['CallSid' => $sid, 'CallStatus' => 'completed', 'CallDuration' => '200'];
    app(TenantContext::class)->forget();
    $this->post($url, $params, ['X-Twilio-Signature' => 'wrong'])->assertForbidden();
    $this->post($url, $params, ['X-Twilio-Signature' => TwilioSignature::sign($url, $params, 'twilio-auth-token-0000000')])->assertNoContent();
    $this->post($url, $params, ['X-Twilio-Signature' => TwilioSignature::sign($url, $params, 'twilio-auth-token-0000000')])->assertNoContent(); // replay: settled once

    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant);
    expect(app(UsageMeter::class)->used('calls'))->toBe(4) // 200 s → 4 minutes
        ->and(DB::connection(Connections::control())->table('usage_calls')->where('call_sid', $sid)->value('actual_minutes'))->toBe(4);
});

it('opens website maps within the allowance, then on credits, and rests them when credits run out', function (): void {
    app(Maps::class)->save('pk.eyJ1IjoicmlnaHRhbGx5In0.Zm9vYmFy', $this->staff);
    $sites = app(SiteSetup::class);
    $site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($site, true, $this->owner);
    $web = 'http://acme.rightally.site';
    DB::connection(Connections::cell())->table('usage_meters')->insert(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'meter' => 'maps', 'used' => 19, 'over' => 0,
        'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->add(null, 13, 'adjust', 'test', $this->owner);
    app(RateCard::class)->saveEnforced(true, $this->staff);

    $this->postJson($web.'/_map')->assertJson(['ok' => true]); // 20th: within the allowance
    foreach (range(1, 10) as $i) {
        $this->postJson($web.'/_map')->assertJson(['ok' => true]); // 10 over: 13 credits
    }
    Notification::assertSentTo($this->staff, MapAllowanceReached::class);
    app(TenantContext::class)->set($this->tenant);
    expect(pool()['available'])->toBe(0);

    $this->postJson($web.'/_map')->assertJson(['ok' => false, 'token' => null]); // out of credits: the map rests

    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/credits/tenants/'.$this->tenant->ulid.'/maps-covered', ['covered' => true])->assertRedirect();
    $this->postJson($web.'/_map')->assertJson(['ok' => true]); // RightAlly covers it
    app(TenantContext::class)->set($this->tenant);
    expect(pool()['available'])->toBe(0)->and(app(UsageMeter::class)->used('maps'))->toBe(31);
});

it('prices allowances in the package planner and lets HQ set them', function (): void {
    $plan = app(Economics::class)->packagePlan($this->package);
    // 10 texts × 1.33¢ + 4 minutes × 1.4¢ + 20 map views × 0.5¢ = 13.3 + 5.6 + 10 = 28.9¢.
    expect($plan['allowance_microcents'])->toBe(28_900_000);

    app(TenantContext::class)->forget();
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/credits/packages/'.$this->package->ulid, ['included_credits' => 0, 'included_text_segments' => 500,
        'included_call_minutes' => 100, 'included_map_views' => 5000])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->package->fresh()->only(['included_text_segments', 'included_call_minutes', 'included_map_views']))
        ->toBe(['included_text_segments' => 500, 'included_call_minutes' => 100, 'included_map_views' => 5000]);

    // 500 × 1.33¢ + 100 × 1.4¢ + 5,000 × 0.5¢ = $6.65 + $1.40 + $25 = $33.05 of a $100 package.
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/credits')->assertInertia(fn (Assert $p) => $p
        ->where('packages.0.allowance_microcents', 3_305_000_000)->where('packages.0.typical_margin_pct', 66)
        ->where('unit_costs', ['texts' => 1_330_000, 'calls' => 1_400_000, 'maps' => 500_000])
        ->where('actions', fn ($a) => collect($a)->firstWhere('action', 'usage.text_segment')['suggested_credits'] === 4));
});

it('shows owners this month\'s allowances in the menu meter', function (): void {
    app(SmsSender::class)->send('+15125550100', 'Hello');
    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p
        ->where('credits.usage.texts', ['used' => 1, 'included' => 10, 'label' => 'texts'])
        ->where('credits.usage.maps.included', 20));
});
