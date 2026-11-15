<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections as DbConnections;
use Modules\Payouts\Balances;
use Modules\Transactions\Connect\DealImporter;
use Modules\Transactions\Models\Deal;

/* Sprint C5a-3: close there, paid here — a deal that closes in a connected transaction app is paid in RightAlly
   automatically (split, fees, cap, revenue share); the broker can override or reverse it here; changes there pay
   it again. */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    $this->sponsor = User::factory()->create(['first_name' => 'Sam']);
    app(Sponsorships::class)->set($this->agent, $this->sponsor, CarbonImmutable::parse('2026-01-01'));
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'Standard', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'post_split' => [['code' => 'tx_fee', 'name' => 'Transaction fee', 'calc' => 'flat', 'amount' => 39_500]],
        'revenue_share' => ['basis' => 'gci', 'levels' => ['10', '10']]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('skyslope'), true, ['client_id' => 'c', 'client_secret' => 's'], null, User::factory()->make(['id' => 999999]));
    $this->connection = Connection::query()->create(['app_key' => 'skyslope', 'label' => 'SkySlope', 'status' => 'connected', 'credentials' => [], 'settings' => [],
        'connected_by' => $this->owner->id]);
});

/** One deal from the other app, through the shared import (as every transaction app does). */
function synced(array $changes = []): Deal
{
    $c = test()->connection->fresh()->data();
    $deal = ['id' => 'sale:S1', 'type' => 'buyer', 'status' => 'closed', 'agent_id' => null, 'agent_email' => 'jane@acme.test', 'street' => '44 Pecan Dr', 'city' => 'Austin',
        'state_code' => 'TX', 'postal_code' => '78745', 'mls_number' => null, 'price_cents' => 45_000_000, 'gci_cents' => 1_000_000, 'contract_date' => '2026-11-01',
        'closing_date' => '2026-11-20', 'cancel_reason' => null, 'parties' => [], 'dates' => []];

    return app(DealImporter::class)->deal($c, test()->owner, array_merge($deal, $changes), 'SkySlope')['deal'];
}

function finalFor(Deal $deal): ?Calculation
{
    return Calculation::query()->where('deal_ref', $deal->ulid)->where('status', 'final')->orderByDesc('version')->first();
}

it('pays a deal that closes there on the agent\'s plan, revenue share into the wallet, the agent\'s own pay outside', function (): void {
    $deal = synced();

    $final = finalFor($deal);
    $row = $final?->result['agents'][0];
    expect($deal->fresh()?->money_status)->toBe('paid')->and($deal->fresh()?->agent_paid_outside)->toBeTrue()
        ->and($row['company_cents'])->toBe(200_000)->and($row['agent_net_cents'])->toBe(760_500) // $8,000 less the $395 fee
        ->and(app(RevenueShareEngine::class)->wallet($this->sponsor)->cents)->toBe(100_000)
        ->and(app(Balances::class)->for($this->agent)['pending_cents'])->toBe(0) // paid outside: nothing "on its way" here
        ->and($this->connection->fresh()?->settings['pay_from'])->toBe('2026-11-15');

    // Running the import again changes nothing.
    synced();
    expect(Calculation::query()->where('deal_ref', $deal->ulid)->count())->toBe(2); // pending + final, once
});

it('leaves deals closed before the pay-from date as history', function (): void {
    $deal = synced(['closing_date' => '2026-10-01']);

    expect(finalFor($deal))->toBeNull()->and($deal->fresh()?->money_status)->toBeNull()
        ->and(app(RevenueShareEngine::class)->wallet($this->sponsor)->cents)->toBe(0);
});

it('pays again when the deal changes there, and reverses when it is reopened there', function (): void {
    $deal = synced();
    synced(['gci_cents' => 1_200_000]);

    expect(finalFor($deal)?->result['agents'][0]['company_cents'])->toBe(240_000)
        ->and(app(RevenueShareEngine::class)->wallet($this->sponsor)->cents)->toBe(120_000)
        ->and(Calculation::query()->where('deal_ref', $deal->ulid)->where('status', 'reversed')->count())->toBe(1);

    synced(['status' => 'under_contract', 'gci_cents' => 1_200_000]);
    expect(finalFor($deal))->toBeNull()->and($deal->fresh()?->money_status)->toBe('reversed')
        ->and(app(RevenueShareEngine::class)->wallet($this->sponsor)->cents)->toBe(0);
});

it('lets the broker override split, fees, cap and revenue share, and reverse, in RightAlly only', function (): void {
    $deal = synced();
    $this->actingAs($this->agent)->post('/deals/'.$deal->ulid.'/pay/reverse', ['reason' => 'Testing it'])->assertForbidden();

    $this->actingAs($this->owner)->post('/deals/'.$deal->ulid.'/pay/override', ['agent_split_percent' => '90', 'waive_fees' => true, 'no_revenue_share' => true,
        'reason' => 'Top producer incentive'])->assertSessionHasNoErrors();
    $row = finalFor($deal)?->result['agents'][0];
    expect($row['company_cents'])->toBe(100_000)->and($row['agent_net_cents'])->toBe(900_000)
        ->and(app(RevenueShareEngine::class)->wallet($this->sponsor)->cents)->toBe(0)
        ->and($deal->fresh()?->pay_overrides)->toBe(['agent_split_percent' => '90', 'waive_fees' => true, 'no_revenue_share' => true, 'reason' => 'Top producer incentive']);

    $this->actingAs($this->owner)->post('/deals/'.$deal->ulid.'/pay/reverse', ['reason' => 'Paid by mistake'])->assertSessionHasNoErrors();
    expect(finalFor($deal))->toBeNull()->and($deal->fresh()?->money_status)->toBe('reversed');

    synced(); // unchanged there: stays reversed
    expect(finalFor($deal))->toBeNull();

    $this->actingAs($this->owner)->post('/deals/'.$deal->ulid.'/pay/again')->assertSessionHasNoErrors();
    expect(finalFor($deal))->not->toBeNull()->and($deal->fresh()?->money_status)->toBe('paid');

    $this->actingAs($this->owner)->get('/deals/'.$deal->ulid)->assertInertia(fn (Assert $p) => $p->where('deal.money_status', 'paid')->where('can.managePay', true));
});

it('never guesses money: a closed deal without a GCI waits for the broker, then is paid', function (): void {
    $deal = synced(['gci_cents' => 0]);
    expect($deal->fresh()?->money_status)->toBe('needs_gci')->and(finalFor($deal))->toBeNull();
    $this->actingAs($this->owner)->get('/deals/bring-over-files')->assertInertia(fn (Assert $p) => $p->where('waiting.0.status', 'needs_gci')
        ->where('connections.0.pay_from', '2026-11-15'));

    $this->actingAs($this->owner)->post('/deals/'.$deal->ulid.'/pay/gci', ['gci' => '9,500'])->assertSessionHasNoErrors();
    expect($deal->fresh()?->money_status)->toBe('paid')->and(finalFor($deal)?->result['gci_cents'])->toBe(950_000);
});

it('pays agents through RightAlly payouts when the brokerage chooses, and lets the owner move the pay-from date', function (): void {
    $this->actingAs($this->owner)->post('/deals/pay-settings', ['synced_commission' => 'payouts'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/deals/pay-from', ['connection' => $this->connection->ulid, 'pay_from' => '2026-09-01'])->assertSessionHasNoErrors();
    expect($this->connection->fresh()?->settings['pay_from'])->toBe('2026-09-01')
        ->and(DB::connection(DbConnections::cell())->table('tx_settings')->value('synced_commission'))->toBe('payouts');

    $deal = synced(['closing_date' => '2026-10-01']); // after the new date: paid
    expect($deal->fresh()?->money_status)->toBe('paid')->and($deal->fresh()?->agent_paid_outside)->toBeFalse()
        ->and(app(Balances::class)->for($this->agent)['pending_cents'])->toBe(760_500); // on its way until the money is recorded
});
