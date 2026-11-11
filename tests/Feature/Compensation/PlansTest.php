<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Enums\PlanStatus;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Compensation\Exceptions\NoPlanForAgent;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\PlanResolver;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

const SPLIT_80 = ['split' => ['mode' => 'percentage', 'agent_percent' => '80']];
const SPLIT_90 = ['split' => ['mode' => 'percentage', 'agent_percent' => '90']];

beforeEach(function (): void {
    app(TenantContext::class)->set(Tenant::factory()->create());
    $this->plans = app(ManagePlans::class);
});

function publishedPlan(array $rules = SPLIT_80, string $from = '2026-01-01', string $name = 'Standard 80/20'): Plan
{
    $plan = test()->plans->create(PlanType::Commission, $name, $rules);
    test()->plans->publish($plan->versions()->sole(), CarbonImmutable::parse($from));

    return $plan->fresh();
}

it('has onboarding and commission plans, each with valid rules (legacy rule 1)', function (): void {
    $onboarding = $this->plans->create(PlanType::Onboarding, 'Joining fees', ['fees' => [
        ['code' => 'joining_fee', 'name' => 'Joining fee', 'calc' => 'flat', 'amount' => 25_000, 'payable_to' => 'brokerage'],
    ]]);

    expect($onboarding->type)->toBe(PlanType::Onboarding)
        ->and(fn () => $this->plans->create(PlanType::Onboarding, 'Bad', SPLIT_80))->toThrow(ValidationException::class)
        ->and(fn () => $this->plans->create(PlanType::Commission, 'Bad', ['fees' => []]))->toThrow(ValidationException::class);
});

it('keeps exactly one default commission plan (legacy rule 2)', function (): void {
    $a = publishedPlan(name: 'A');
    $b = publishedPlan(SPLIT_90, name: 'B');
    $onboarding = $this->plans->create(PlanType::Onboarding, 'Joining', ['fees' => []]);
    $unpublished = $this->plans->create(PlanType::Commission, 'Draft only', SPLIT_80);

    $this->plans->setDefault($a);
    $this->plans->setDefault($b);

    expect(Plan::query()->where('is_default', true)->pluck('name')->all())->toBe(['B'])
        ->and(fn () => $this->plans->setDefault($onboarding))->toThrow(ValidationException::class)
        ->and(fn () => $this->plans->setDefault($unpublished))->toThrow(ValidationException::class)
        ->and(fn () => $this->plans->archive($b->fresh()))->toThrow(ValidationException::class);
});

it('validates the revenue share basis (legacy rule 5)', function (): void {
    $this->plans->create(PlanType::Commission, 'RS', SPLIT_80 + ['revenue_share' => ['basis' => 'gci']]);

    expect(fn () => $this->plans->create(PlanType::Commission, 'RS bad', SPLIT_80 + ['revenue_share' => ['basis' => 'sales']]))
        ->toThrow(ValidationException::class);
});

it('never changes a published version; changes create a new version', function (): void {
    $plan = publishedPlan();
    $v1 = $plan->latestPublished();

    expect(fn () => $v1->update(['rules' => SPLIT_90]))->toThrow(LogicException::class)
        ->and(fn () => $v1->delete())->toThrow(LogicException::class);

    $v2 = $this->plans->saveDraft($plan, SPLIT_90);
    expect($v2->version)->toBe(2)->and($v2->status)->toBe(VersionStatus::Draft);

    $this->plans->publish($v2, CarbonImmutable::parse('2026-07-01'), note: 'Raised to 90/10');
    expect($v1->fresh()?->status)->toBe(VersionStatus::Superseded)
        ->and($v1->fresh()?->rules)->toBe(SPLIT_80);
});

it('refuses to publish a version that starts before the current one', function (): void {
    $plan = publishedPlan(from: '2026-06-01');
    $draft = $this->plans->saveDraft($plan, SPLIT_90);

    $this->plans->publish($draft, CarbonImmutable::parse('2026-05-01'));
})->throws(ValidationException::class);

it('uses the version in force on the closing date', function (): void {
    $plan = publishedPlan(from: '2026-01-01');
    $this->plans->publish($this->plans->saveDraft($plan, SPLIT_90), CarbonImmutable::parse('2026-07-01'));
    $this->plans->setDefault($plan->fresh());
    $resolver = app(PlanResolver::class);

    expect($resolver->for(7, CarbonImmutable::parse('2026-06-30'))->versionNumber)->toBe(1)
        ->and($resolver->for(7, CarbonImmutable::parse('2026-07-01'))->versionNumber)->toBe(2);

    $result = app(CommissionCalculator::class)->calculate(
        new DealSideInput(Money::of(1_000_000), 30_000_000, CarbonImmutable::parse('2026-06-15'), [new AgentShare(7)]),
        $resolver->asClosure(),
    );
    expect($result->agents[0]->companyDollar->cents)->toBe(200_000);
});

it('uses the agent\'s assignment, ending the previous one the day before', function (): void {
    $standard = publishedPlan(name: 'Standard');
    $senior = publishedPlan(SPLIT_90, name: 'Senior');
    $this->plans->setDefault($standard);

    $this->plans->assign(7, $senior, CarbonImmutable::parse('2026-03-01'));
    $this->plans->assign(7, $standard, CarbonImmutable::parse('2026-09-01'));
    $resolver = app(PlanResolver::class);

    expect($resolver->for(7, CarbonImmutable::parse('2026-02-01'))->planName)->toBe('Standard')
        ->and($resolver->for(7, CarbonImmutable::parse('2026-05-01'))->planName)->toBe('Senior')
        ->and($resolver->for(7, CarbonImmutable::parse('2026-10-01'))->planName)->toBe('Standard')
        ->and($resolver->for(99, CarbonImmutable::parse('2026-05-01'))->planName)->toBe('Standard')
        ->and(fn () => $this->plans->assign(7, $senior, CarbonImmutable::parse('2026-08-01')))->toThrow(ValidationException::class)
        ->and(fn () => $this->plans->archive($senior))->not->toThrow(ValidationException::class);
});

it('cannot archive a plan agents are still on', function (): void {
    $plan = publishedPlan();
    $this->plans->assign(7, $plan, CarbonImmutable::today());

    $this->plans->archive($plan);
})->throws(ValidationException::class);

it('explains when no plan applies', function (): void {
    app(PlanResolver::class)->for(7, CarbonImmutable::today());
})->throws(NoPlanForAgent::class);

it('keeps plans inside their brokerage', function (): void {
    publishedPlan(name: 'Acme plan');
    $other = Tenant::factory()->create();

    $names = app(TenantContext::class)->runAs($other, fn () => Plan::query()->pluck('name')->all());

    expect($names)->toBe([])->and(Plan::query()->where('status', PlanStatus::Active)->count())->toBe(1);
});
