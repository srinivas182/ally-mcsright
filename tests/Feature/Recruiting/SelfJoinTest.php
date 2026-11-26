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
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\PlanAssignment;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Recruiting\Http\MyNetworkController;
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
    return $over + ['first_name' => 'Nina', 'last_name' => 'Ortiz', 'email' => 'nina@example.test', 'phone' => '+15125550101',
        'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1', 'licence_state' => 'TX', 'licence_number' => 'test',
        'pack' => test()->pro->ulid, 'agree' => true];
}

it('shows the join page with the sponsor and the packs', function (): void {
    $this->get("/join/{$this->code}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
        ->component('public/join')->where('sponsor.name', 'James Smith')->where('packs.0.name', 'Pro')->where('packs.0.price_cents', 15_000));
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
    $this->post("/join/{$this->code}", joinForm(['email' => 'nina@', 'phone' => '555-01']))->assertSessionHasErrors(['email', 'phone']);
    expect(User::query()->where('email', 'nina@example.test')->exists())->toBeFalse();
});

it('gives agents their link on their dashboard', function (): void {
    $this->actingAs($this->sponsor)->get('/agent')->assertInertia(fn (Assert $page) => $page->where('joinLink', fn ($l) => str_ends_with((string) $l, "/join/{$this->code}")));
});

it('lets the owner set the join page: words, video and extra states', function (): void {
    DB::connection(Connections::control())->table('idx_states')->insertOrIgnore(['code' => 'FL', 'name' => 'Florida', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->owner)->post('/recruiting/join-page', ['headline' => 'Grow with us', 'pitch' => 'Top split.', 'reasons' => "Great tools\nGreat team",
        'video' => 'javascript:alert(1)', 'extra_states' => ['FL']])->assertSessionHasErrors('video');
    $this->actingAs($this->owner)->post('/recruiting/join-page', ['headline' => 'Grow with us', 'pitch' => 'Top split.', 'reasons' => "Great tools\nGreat team",
        'video' => 'https://youtu.be/dQw4w9WgXcQ', 'extra_states' => ['FL']])->assertRedirect();

    $this->get("/join/{$this->code}")->assertInertia(fn (Assert $page) => $page
        ->where('page.headline', 'Grow with us')->where('page.reasons', ['Great tools', 'Great team'])
        ->where('page.video', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')->where('states', ['FL' => 'Florida']));
    $this->actingAs($this->sponsor)->get('/recruiting/join-page')->assertForbidden();
});

it('makes each agent a join code people can read, from their name (AG1)', function (): void {
    expect($this->code)->toBe('jamessmith');
    $twin = User::factory()->create(['first_name' => 'James', 'last_name' => 'Smith']);
    expect(app(SelfJoin::class)->codeFor($twin))->toBe('jamessmith2')
        ->and(app(SelfJoin::class)->codeFor(User::factory()->create(['first_name' => 'Al', 'last_name' => 'Ng'])))->toMatch('/^alng\d{2}$/');
});

it('counts link opens once per visitor, and shows the agent their network numbers (AG1)', function (): void {
    $this->get("/join/{$this->code}");
    $this->get("/join/{$this->code}"); // same visitor: counted once
    $this->post("/join/{$this->code}", joinForm(), ['X-Inertia' => 'true']); // started (waiting for payment)

    $this->actingAs($this->sponsor)->get('/my-network')->assertOk()->assertInertia(fn (Assert $page) => $page->component('recruiting/my-network')
        ->where('joins.opens', 1)->where('joins.started', 1)->where('joins.joined', 0)
        ->where('direct', 0)->where('closed.deals', 0)->where('revenue_share_cents', 0)
        ->where('link', fn ($l) => str_ends_with((string) $l, '/join/jamessmith'))
        ->where('paid_cents', 0)->where('waiting_cents', 0)->where('revenue_share_on', false)->missing('tree'));
    $this->actingAs($this->owner)->get('/my-network')->assertOk();

    // AG1b: the tree has its own tab.
    $nina = User::query()->where('email', 'nina@example.test')->sole();
    $nina->forceFill(['status' => UserStatus::Active])->save();
    app(Sponsorships::class)->set($nina, $this->sponsor, CarbonImmutable::parse('2026-05-01'));
    $this->actingAs($this->sponsor)->get('/my-network/tree')->assertOk()->assertInertia(fn (Assert $page) => $page->component('recruiting/network-tree')
        ->where('direct', 1)->where('tree.0.name', 'Nina Ortiz')->where('tree.0.level', 1));
});

it('sends the short link on the website on to the join page, keeping the agent (AG1)', function (): void {
    $this->get("/join/{$this->code}")->assertOk(); // the back office join page still answers
    $response = app(MyNetworkController::class)->fromWebsite(app(SelfJoin::class), $this->code);
    expect($response->getTargetUrl())->toEndWith("/join/{$this->code}");
    expect(app(MyNetworkController::class)->fromWebsite(app(SelfJoin::class), 'nobody12')->getTargetUrl())->toEndWith('/join');
});

it('lets a new agent pick revenue share or a flat fee, told in plain words, and places them on it (AG4)', function (): void {
    $plans = app(ManagePlans::class);
    $share = $plans->create(PlanType::Commission, 'Revenue share', ['split' => ['mode' => 'percentage', 'agent_percent' => '90'],
        'cap' => ['amount_cents' => 1_600_000, 'anchor' => 'anniversary'], 'post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 50_000]],
        'revenue_share' => ['basis' => 'company_dollar', 'levels' => ['20', '20', '20', '20', '20']]]);
    $plans->publish($share->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $flat = $plans->create(PlanType::Commission, 'Flat fee', ['split' => ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => 50_000]]]]);
    $plans->publish($flat->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($share->fresh());

    // Not offered yet: no choice, they join on the default plan.
    $this->get("/join/{$this->code}")->assertInertia(fn (Assert $page) => $page->where('plans', []));

    $this->actingAs($this->owner)->post("/compensation/plans/{$share->ulid}/join", ['offered' => true])->assertSessionHasNoErrors();
    $this->post("/compensation/plans/{$flat->ulid}/join", ['offered' => true])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get("/join/{$this->code}")->assertInertia(fn (Assert $page) => $page->has('plans', 2)
        ->where('plans.0.name', 'Revenue share')
        ->where('plans.0.lines', ['You keep 90% of each commission; the brokerage keeps 10%.',
            'The brokerage\'s share stops at $16,000 a year (your cap). Your year starts on the day you join.',
            'After you cap, you pay $500 a deal for the rest of that year.', 'Earn revenue share on the agents you bring in, 5 levels deep.'])
        ->where('plans.1.lines', ['You keep 100% of each commission, less a flat $500 per deal.']));

    $this->post("/join/{$this->code}", joinForm(['plan' => null]))->assertSessionHasErrors('plan');
    $this->post("/join/{$this->code}", joinForm(['plan' => $flat->ulid]), ['X-Inertia' => 'true'])->assertStatus(409);
    $nina = User::query()->where('email', 'nina@example.test')->sole();
    app(SelfJoin::class)->place($nina->id);

    expect(PlanAssignment::query()->where('agent_user_id', $nina->id)->sole()->plan_id)->toBe($flat->id);
});

it('only offers a published plan to new agents, and stops when it is archived (AG4)', function (): void {
    $plans = app(ManagePlans::class);
    $draft = $plans->create(PlanType::Commission, 'Draft plan', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $this->actingAs($this->owner)->post("/compensation/plans/{$draft->ulid}/join", ['offered' => true])->assertSessionHasErrors('plan');
    $plans->publish($draft->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $this->post("/compensation/plans/{$draft->ulid}/join", ['offered' => true])->assertSessionHasNoErrors();
    expect(app(SelfJoin::class)->plans())->toHaveCount(1);
    $plans->archive($draft->fresh());
    expect(app(SelfJoin::class)->plans())->toBe([]);
});
