<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Catalogue;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Pricing;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Recruiting\SelfJoin;

/*
| CM4: every agent has a join link. A new agent with a valid licence picks a pack, pays, and is placed under the
| agent whose link they used straight away (no approval).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->sponsor = User::factory()->create(['first_name' => 'James', 'last_name' => 'Smith']);
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    app(PaymentSettings::class)->setOptions(true, false, $this->owner);
    $catalogue = app(Catalogue::class);
    $catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => '20']]);
    $this->pro = $catalogue->savePlan(null, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 20_000, 'floor_cents' => 10_000, 'features' => ['backoffice.basic']]);
    app(Pricing::class)->setPrice($this->pro, 15_000, true, $this->owner);
    DB::connection(Connections::control())->table('idx_states')->insertOrIgnore(['code' => 'TX', 'name' => 'Texas', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->code = app(SelfJoin::class)->codeFor($this->sponsor);
});

function joinForm(array $over = []): array
{
    return $over + ['first_name' => 'Nina', 'last_name' => 'Ortiz', 'email' => 'nina@example.test', 'phone' => '+1 512 555 0101',
        'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1', 'licence_state' => 'TX', 'licence_number' => 'test',
        'pack' => test()->pro->ulid, 'agree' => true];
}

it('shows the join page with the sponsor and the packs', function (): void {
    $this->get("/join/{$this->code}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
        ->component('public/join')->where('sponsor', 'James Smith')->where('packs.0.name', 'Pro')->where('packs.0.price_cents', 15_000));
    $this->get('/join/zzzzzzzz')->assertInertia(fn (Assert $page) => $page->where('sponsor', null)->where('unknownCode', true));
});

it('checks the licence, sends the new agent to pay, and places them under the sponsor once paid', function (): void {
    $this->post("/join/{$this->code}", joinForm(), ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.example/session/1');
    $nina = User::query()->where('email', 'nina@example.test')->sole();
    expect($nina->status)->toBe(UserStatus::Invited)
        ->and($this->gateway->checkouts[0]['successUrl'])->toEndWith('/join/done')
        ->and(DB::table('roster_credentials')->where('user_id', $nina->id)->value('number'))->toBe('test');

    // The payment arrives: placed now, under James, no approval needed.
    $this->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['id' => 'evt_join', 'type' => 'checkout.session.completed', 'data' => ['object' => ['subscription' => 'sub_9', 'customer' => 'cus_9', 'metadata' => [
            'tenant_id' => (string) $this->tenant->id, 'user_id' => (string) $nina->id, 'agent_plan_id' => (string) $this->pro->id, 'price_cents' => '15000']]]]))
        ->assertJson(['processed' => true]);

    expect($nina->fresh()->status)->toBe(UserStatus::Active)
        ->and(app(Sponsorships::class)->sponsorOf($nina->id, CarbonImmutable::today()))->toBe($this->sponsor->id);
});

it('refuses missing details, a used email and an unchosen pack', function (): void {
    $this->post("/join/{$this->code}", joinForm(['agree' => false, 'password_confirmation' => 'different-one-xx']))->assertSessionHasErrors(['agree', 'password']);
    $this->post("/join/{$this->code}", joinForm(['email' => $this->sponsor->email]))->assertSessionHasErrors('email');
    $this->post("/join/{$this->code}", joinForm(['pack' => null]))->assertSessionHasErrors('pack');
    expect(User::query()->where('email', 'nina@example.test')->exists())->toBeFalse();
});

it('gives agents their link on their dashboard', function (): void {
    $this->actingAs($this->sponsor)->get('/agent')->assertInertia(fn (Assert $page) => $page->where('joinLink', fn ($l) => str_ends_with((string) $l, "/join/{$this->code}")));
});
