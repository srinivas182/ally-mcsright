<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Affiliates\Affiliates;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| RF1 (specs/billing/08): RightAlly's own referral programme. People earn 20% of a referred brokerage's first-year
| subscription as cash; brokerages earn 20% as credit (PB3: HQ can change both). Rewards wait 30 days, self-referrals
| earn nothing, HQ pays.
*/

const RF = 'http://app.rightally.io';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->package = Package::query()->create(['name' => 'Brokerage', 'monthly_cents' => 29_900, 'yearly_cents' => 299_000, 'ailly_conversations' => 500, 'features' => [], 'active' => true]);
});

function rfDb(): Connection
{
    return DB::connection(Connections::control());
}

/** A referred signup that pays, through the referral cookie. */
function rfSignup(?string $code, string $slug, string $email): Tenant
{
    $req = test()->withCookie(Affiliates::COOKIE, $code ?? '');
    $req->post(RF.'/start', ['brokerage' => ucfirst($slug), 'slug' => $slug, 'owner_name' => 'Dana Smith', 'owner_email' => $email, 'state' => 'TX',
        'agents' => 12, 'package' => test()->package->ulid, 'interval' => 'month', 'terms' => '1'])->assertRedirect();
    $signup = (string) rfDb()->table('signups')->orderByDesc('id')->value('ulid');
    $event = ['id' => 'evt_'.$slug, 'type' => 'checkout.session.completed', 'data' => ['object' => ['subscription' => 'sub_'.$slug, 'customer' => 'cus_'.$slug, 'amount_total' => 29_900,
        'metadata' => ['kind' => 'signup', 'signup' => $signup, 'package_id' => (string) test()->package->id, 'interval' => 'month', 'price_cents' => '29900']]]];
    test()->call('POST', RF.'/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], (string) json_encode($event))->assertOk();

    return Tenant::query()->where('slug', $slug)->sole();
}

it('gives a person a link and private dashboard, and earns 20% of the first year after a 30-day wait', function (): void {
    $this->actingAs($this->staff)->post(RF.'/hq/affiliates', ['name' => 'Coach Kim', 'email' => 'kim@coaching.test'])->assertRedirect()
        ->assertSessionHas('affiliateToken.code', 'coachkim');
    $token = (string) str((string) session('affiliateToken.dashboard'))->afterLast('/');
    $this->actingAs($this->staff)->post(RF.'/hq/affiliates', ['name' => 'Coach Kim', 'email' => 'kim@coaching.test'])->assertSessionHasErrors('email');

    $this->get(RF.'/r/coachkim')->assertRedirect('/start')->assertCookie(Affiliates::COOKIE, 'coachkim');
    $this->get(RF.'/r/nobody')->assertRedirect('/start')->assertCookieMissing(Affiliates::COOKIE);
    $tenant = rfSignup('coachkim', 'oak-realty', 'dana@oak.test');

    $ref = rfDb()->table('affiliate_referrals')->sole();
    expect($ref->status)->toBe('pending')->and((int) $ref->tenant_id)->toBe($tenant->id)->and((int) $ref->reward_cents)->toBe(5_980)
        ->and($ref->reward_kind)->toBe('cash');
    // A renewal in the first year adds to the reward; after the first year it doesn't.
    app(Affiliates::class)->paid($tenant->id, 29_900);
    expect((int) rfDb()->table('affiliate_referrals')->value('reward_cents'))->toBe(11_960);

    $this->artisan('billing:affiliates-settle')->assertSuccessful();
    expect(rfDb()->table('affiliate_referrals')->value('status'))->toBe('pending'); // still in the refund window
    $this->travel(31)->days();
    $this->artisan('billing:affiliates-settle')->assertSuccessful();
    expect(rfDb()->table('affiliate_referrals')->value('status'))->toBe('approved');

    $html = (string) $this->get(RF.'/affiliate/'.$token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($html)->toContain('$119.60')->not->toContain('Oak'); // people don't see brokerage names
    $this->get(RF.'/affiliate/'.str_repeat('x', 40))->assertNotFound();

    $id = (int) rfDb()->table('affiliate_referrals')->value('id');
    $this->actingAs($this->staff)->post(RF."/hq/affiliates/referrals/{$id}/paid", ['note' => 'Sent by bank transfer'])->assertRedirect(); // asks for the password first
    expect(rfDb()->table('affiliate_referrals')->value('status'))->toBe('approved');
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post(RF."/hq/affiliates/referrals/{$id}/paid", ['note' => 'Sent by bank transfer'])->assertRedirect();
    expect(rfDb()->table('affiliate_referrals')->value('status'))->toBe('paid');

    $this->actingAs($this->staff)->get(RF.'/hq/affiliates')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hq/affiliates')
        ->where('referrals.0.status', 'paid')->where('referrals.0.brokerage', 'Oak-realty')->where('affiliates.0.joined', 1));
});

it('gives a referring brokerage 20% of the first year as credit, and nothing for referring itself or after a quick cancel', function (): void {
    $acme = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create(['email' => 'owner@acme-realty.com']);
    $this->actingAs($owner)->get('/settings/refer')->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/refer')
        ->where('link', fn (string $l): bool => str_starts_with($l, 'https://app.rightally.io/r/'))->where('share', 20)->where('stats.joined', 0));
    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/settings/refer')->assertForbidden();
    auth()->logout();

    $code = (string) rfDb()->table('affiliates')->where('tenant_id', $acme->id)->value('code');
    $pine = rfSignup($code, 'pine-homes', 'jo@pine.test');
    $same = rfSignup($code, 'acme-two', 'boss@acme-realty.com'); // the same company domain
    $quick = rfSignup($code, 'quick-homes', 'lee@quick.test');

    $refs = rfDb()->table('affiliate_referrals')->get()->keyBy('tenant_id');
    expect($refs[$pine->id]->status)->toBe('pending')->and((int) $refs[$pine->id]->reward_cents)->toBe(5_980)->and($refs[$pine->id]->reward_kind)->toBe('credit')
        ->and($refs[$same->id]->status)->toBe('void')->and($refs[$same->id]->note)->toBe('Self-referral: the same company email domain');

    rfDb()->table('billing_tenant_subscriptions')->where('tenant_id', $quick->id)->update(['status' => 'cancelled']);
    $this->travel(31)->days();
    $this->artisan('billing:affiliates-settle')->assertSuccessful();
    expect(rfDb()->table('affiliate_referrals')->where('tenant_id', $pine->id)->value('status'))->toBe('approved')
        ->and(rfDb()->table('affiliate_referrals')->where('tenant_id', $quick->id)->value('note'))->toBe('Cancelled within 30 days');

    // An unknown or missing code changes nothing.
    rfSignup(null, 'plain-homes', 'pat@plain.test');
    expect(rfDb()->table('affiliate_referrals')->count())->toBe(3);
});

it('lets HQ change both referral shares, behind the password, for payments from then on', function (): void {
    $this->actingAs($this->staff)->post(RF.'/hq/affiliates', ['name' => 'Coach Kim', 'email' => 'kim@coaching.test'])->assertRedirect();
    $tenant = rfSignup('coachkim', 'oak-realty', 'dana@oak.test');
    expect((int) rfDb()->table('affiliate_referrals')->value('reward_cents'))->toBe(5_980); // 20% to start

    $this->actingAs($this->staff)->post(RF.'/hq/affiliates/rewards', ['person' => 25, 'brokerage' => 15])->assertRedirect(); // asks for the password first
    expect(Affiliates::rewards())->toBe(['person' => 20, 'brokerage' => 20]);
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post(RF.'/hq/affiliates/rewards', ['person' => 60, 'brokerage' => 15])->assertSessionHasErrors('person');
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post(RF.'/hq/affiliates/rewards', ['person' => 25, 'brokerage' => 15])->assertSessionHasNoErrors();
    expect(Affiliates::rewards())->toBe(['person' => 25, 'brokerage' => 15]);

    app(Affiliates::class)->paid($tenant->id, 29_900); // the next payment earns the new share; the first keeps its 20%
    expect((int) rfDb()->table('affiliate_referrals')->value('reward_cents'))->toBe(5_980 + 7_475);
    $this->actingAs($this->staff)->get(RF.'/hq/affiliates')->assertInertia(fn (Assert $page) => $page->where('share', 25)->where('brokerageShare', 15));
});
