<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Compensation\Enums\VersionStatus;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\PlanReader;
use Tests\Fixtures\FakeAiProvider;

/* CP1 (specs/compensation/03): a draft commission plan from the brokerage's plan document. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => PlanReader::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.plan_reader']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    app(CreditLedger::class)->add($this->owner->id, 100, 'adjust', 'Test credits', $this->owner);
});

it('turns a plan document into a draft plan with the sentence behind every number', function (): void {
    $text = "ACME REALTY INDEPENDENT CONTRACTOR COMPENSATION PLAN\nAgents receive 80% of each commission side and the brokerage retains 20% until the agent has paid \$18,000 "
        .'in company dollar during their anniversary year. After capping, a $250 transaction fee applies to each side. A 6% franchise royalty is deducted from gross commission '
        .'before the split, up to $3,000 per year. Each transaction carries a $395 fee to the agent. Leases are paid 90/10.';
    $this->ai->respond(json_encode(['name' => '80/20 with $18,000 cap', 'summary' => 'You keep 80% until you pay $18,000 a year, then 100% less a $250 fee.',
        'agent_percent' => '80', 'flat_fee_dollars' => null, 'cap_dollars' => 18000, 'cap_starts' => 'anniversary', 'cap_start_month_day' => null,
        'post_cap_fee' => ['kind' => 'flat', 'amount' => 250],
        'pre_split' => [['name' => 'Franchise royalty', 'kind' => 'percent', 'amount' => 6, 'payable_to' => 'franchisor', 'annual_cap_dollars' => 3000]],
        'post_split' => [['name' => 'Transaction fee', 'kind' => 'flat', 'amount' => 395, 'payable_to' => 'brokerage', 'annual_cap_dollars' => null]],
        'deal_classes' => [['class' => 'lease', 'agent_percent' => '90'], ['class' => 'timeshare', 'agent_percent' => '50']],
        'evidence' => [['rule' => 'Split', 'quote' => 'Agents receive 80% of each commission side']], 'unclear' => ['Mentions a team split but no percentage']]));

    $this->actingAs($this->agent)->post('/compensation/plans/read', ['text' => $text])->assertForbidden();
    $this->actingAs($this->owner)->post('/compensation/plans/read', ['text' => $text])->assertSessionHasNoErrors();

    $plan = Plan::query()->sole();
    $version = $plan->versions()->sole();
    expect($plan->name)->toBe('80/20 with $18,000 cap (draft from document)')->and($version->status)->toBe(VersionStatus::Draft)
        ->and($version->rules['split'])->toBe(['mode' => 'percentage', 'agent_percent' => '80'])
        ->and($version->rules['cap'])->toBe(['amount_cents' => 1_800_000, 'anchor' => 'anniversary'])
        ->and($version->rules['post_cap'])->toBe(['fee' => ['calc' => 'flat', 'amount' => 25_000]])
        ->and($version->rules['pre_split'][0])->toBe(['code' => 'franchise_royalty', 'name' => 'Franchise royalty', 'calc' => 'percent', 'amount' => '6', 'payable_to' => 'franchisor', 'annual_cap_cents' => 300_000])
        ->and($version->rules['post_split'][0]['amount'])->toBe(39_500)
        ->and($version->rules['deal_classes'])->toBe(['lease' => ['split' => ['mode' => 'percentage', 'agent_percent' => '90']]])
        ->and($this->ai->requests[0]->userMessage)->toContain('6% franchise royalty');

    $this->actingAs($this->owner)->get('/compensation/plans')->assertInertia(fn (Assert $p) => $p->where('reading.evidence.0.quote', 'Agents receive 80% of each commission side')
        ->where('reading.unclear.0', 'Mentions a team split but no percentage'));
});

it('asks for the words when a PDF has no text, and never publishes a plan on its own', function (): void {
    $scan = UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n%%EOF");
    $this->actingAs($this->owner)->post('/compensation/plans/read', ['file' => $scan])->assertSessionHasErrors('file');
    $this->actingAs($this->owner)->post('/compensation/plans/read', ['text' => 'Too short'])->assertSessionHasErrors('file');

    [$rules, $unclear] = PlanReader::rules(['agent_percent' => null, 'flat_fee_dollars' => null]);
    expect($rules['split']['agent_percent'])->toBe('100')->and($unclear[0])->toContain('No split or flat fee was found');
    expect(Plan::query()->count())->toBe(0);
});
