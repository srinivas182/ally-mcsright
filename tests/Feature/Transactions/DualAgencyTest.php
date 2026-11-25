<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Transactions\DualAgency;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/*
| DA1: a dual deal (the brokerage on both sides) is the seller side plus a linked buyer side, each with its own
| commission and agents; it goes under contract only with the signed consent, and moves both sides together.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->andy = User::factory()->create(['first_name' => 'Andy', 'last_name' => 'Lee']);
    $this->maria = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Chen']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function dualDeal(string $state = 'CA', ?User $buyerAgent = null): Deal
{
    test()->actingAs(test()->owner)->post('/deals', ['type' => 'dual', 'street' => '5 Bay St', 'city' => 'Town', 'state_code' => $state, 'price' => '500000',
        'agent' => test()->andy->ulid, 'buyer_agent' => $buyerAgent?->ulid])->assertSessionHasNoErrors();

    return Deal::query()->where('type', 'dual')->latest('id')->firstOrFail();
}

function contractForm(array $over = []): array
{
    return $over + ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'buyer_gci' => '12500', 'contract_date' => '2026-06-01', 'closing_date' => '2026-07-01'];
}

it('makes a linked buyer side with its own agent, and no second checklist', function (): void {
    $dual = dualDeal('CA', $this->maria);
    $buyer = DualAgency::other($dual);
    expect($buyer)->not->toBeNull()->and($buyer->type)->toBe('buyer')->and($buyer->agent_user_id)->toBe($this->maria->id)
        ->and($buyer->paired_deal_id)->toBe($dual->id)->and($dual->paired_deal_id)->toBe($buyer->id)
        ->and(Item::query()->where('deal_id', $buyer->id)->count())->toBe(0)
        ->and(Item::query()->where('deal_id', $dual->id)->where('label', DualAgency::CONSENT_ITEM)->value('required'))->toBeTruthy();

    $this->actingAs($this->owner)->get("/deals/{$dual->ulid}")->assertInertia(fn (Assert $p) => $p->where('dual.side', 'seller')
        ->where('dual.other.ulid', $buyer->ulid)->where('dual.other.agent', $this->maria->name)->where('dual.consent', false));
    $this->actingAs($this->maria)->get("/deals/{$buyer->ulid}")->assertInertia(fn (Assert $p) => $p->where('dual.side', 'buyer'));
});

it('goes under contract only with the consent and both commissions, and pays each side on its own', function (): void {
    $dual = dualDeal('CA', $this->maria);
    $buyer = DualAgency::other($dual);
    $this->actingAs($this->owner)->post("/deals/{$dual->ulid}/status", contractForm())->assertSessionHasErrors('status');

    Item::query()->where('deal_id', $dual->id)->where('label', DualAgency::CONSENT_ITEM)->update(['status' => 'submitted']);
    $this->actingAs($this->owner)->post("/deals/{$dual->ulid}/status", contractForm(['buyer_gci' => null]))->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->post("/deals/{$dual->ulid}/status", contractForm())->assertSessionHasNoErrors();

    $dual->refresh();
    $buyer->refresh();
    expect($dual->status)->toBe('under_contract')->and($buyer->status)->toBe('under_contract')
        ->and($dual->gci_cents)->toBe(1_500_000)->and($buyer->gci_cents)->toBe(1_250_000)
        ->and($buyer->calculation_id)->not->toBe($dual->calculation_id);
    // Andy: 80% of the $15,000 seller side; Maria: 80% of the $12,500 buyer side.
    expect(Calculation::query()->find($dual->calculation_id)->result['agents'][0]['agent_net_cents'])->toBe(1_200_000)
        ->and(Calculation::query()->find($buyer->calculation_id)->result['agents'][0]['agent_net_cents'])->toBe(1_000_000);

    // The buyer side moves only with the dual deal.
    $this->actingAs($this->owner)->post("/deals/{$buyer->ulid}/status", ['status' => 'fell_through', 'reason' => 'Buyer walked'])->assertSessionHasErrors('status');
    expect($buyer->fresh()->status)->toBe('under_contract');

    // Closing the dual deal closes both sides.
    Item::query()->where('deal_id', $dual->id)->update(['status' => 'approved']);
    $this->actingAs($this->owner)->post("/deals/{$dual->ulid}/status", ['status' => 'closed'])->assertSessionHasNoErrors();
    expect($dual->fresh()->status)->toBe('closed')->and($buyer->fresh()->status)->toBe('closed');
});

it('warns when one agent is on both sides in a state that does not allow it', function (): void {
    $tx = dualDeal('TX');
    $this->actingAs($this->owner)->get("/deals/{$tx->ulid}")->assertInertia(fn (Assert $p) => $p->where('dual.warning', fn ($w) => str_contains((string) $w, 'intermediary')));

    $twoAgents = dualDeal('TX', $this->maria);
    $this->actingAs($this->owner)->get("/deals/{$twoAgents->ulid}")->assertInertia(fn (Assert $p) => $p->where('dual.warning', null));

    $ca = dualDeal('CA');
    $this->actingAs($this->owner)->get("/deals/{$ca->ulid}")->assertInertia(fn (Assert $p) => $p->where('dual.warning', null));
});
