<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Contracts\ProviderAdapter;
use Modules\AI\Data\ProviderRequest;
use Modules\AI\Data\ProviderResponse;
use Modules\AI\Evals\Checks;
use Modules\AI\Evals\EvalRunner;
use Modules\AI\Evals\EvalSet;
use Modules\AI\Evals\PromptLock;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Models\AiProvider;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| AQ1 (specs/ai/08): every AI prompt has a test set (or is on a list that may only shrink), every set is sound,
| prompt changes need a new version, and live runs score each prompt and say in plain words what failed.
*/

it('keeps every test set sound: complete cases, every placeholder supplied, example answers passing', function (): void {
    $problems = [];
    foreach (EvalSet::all() as $set) {
        $problems = array_merge($problems, app(EvalRunner::class)->offline($set));
    }

    expect(EvalSet::all())->not->toBeEmpty()->and($problems)->toBe([]);
});

it('never lets the list of untested prompts grow', function (): void {
    $baseline = array_values(array_filter(array_map('trim', file(base_path('modules/AI/evals.untested.txt')) ?: []), fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#')));
    $tested = array_map(fn (EvalSet $s): string => $s->prompt, EvalSet::all());
    $untested = array_values(array_diff(EvalSet::promptKeys(), $tested));

    expect(array_values(array_diff($untested, $baseline)))->toBe([], 'New prompts need a test set in modules/{Module}/evals/.')
        ->and(array_values(array_intersect($baseline, $tested)))->toBe([], 'Remove prompts that now have a test set from evals.untested.txt.');
});

it('needs a new version and a refreshed lock whenever a tested prompt changes', function (): void {
    expect(PromptLock::problems(app(PromptRegistry::class)))->toBe([]);
});

it('catches Fair Housing words, invented prices and contact details, and broken limits', function (): void {
    $source = 'Listing: 3 bed at $629,000. Call 512-555-0100.';
    expect(Checks::failures('{"draft": "A 3 bed at $629,000. Call 512-555-0100."}', $source, ['json_keys' => ['draft'], 'fields' => ['draft' => ['max_chars' => 80]]]))->toBe([])
        ->and(Checks::failures('{"draft": "Perfect for families, only $599k! Email me at a@b.test"}', $source, []))->toBe([
            'Fair Housing: says "perfect for families".',
            'Gives contact details that weren\'t provided: a@b.test.',
            'States a dollar amount that wasn\'t provided: $599k.',
        ])
        ->and(Checks::failures('{"reply": "Hello there friend, how are you"}', '', ['language' => 'es', 'fields' => ['reply' => ['max_chars' => 10]]]))
        ->toBe(['"reply" is too long (31 characters; at most 10).', 'Should be in Spanish.']);
});

it('runs the test sets live in HQ within the budget, scoring each prompt and showing what failed', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $provider = AiProvider::factory()->create(['key' => 'llm']);
    $model = AiModel::factory()->for($provider, 'provider')->create();
    AiFeatureRoute::query()->create(['feature_key' => 'ads.listing_copy', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.ads']);
    // A model that answers well except once, when it writes "perfect for families".
    app(ProviderRegistry::class)->fake('llm', new class implements ProviderAdapter
    {
        private int $n = 0;

        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            $this->n++;

            $price = str_contains($request->userMessage, '$629,000') ? ' Offered at $629,000.' : '';

            return new ProviderResponse($this->n === 2 ? '{"headline": "Elm St home", "body": "Perfect for families!"}'
                : '{"headline": "3 bed home", "body": "Lovely home with a big yard.'.$price.' See the photos and book a showing."}', 400, 60);
        }
    });

    $run = app(EvalRunner::class)->live($hq, 1_000_000_000, ['ads.listing_copy'], $staff->id);

    expect($run['cases'])->toBe(3)->and($run['passed'])->toBe(2)->and($run['status'])->toBe('failures')
        ->and(EvalRunner::latestScores()['ads.listing_copy'])->toMatchArray(['cases' => 3, 'passed' => 2, 'version' => '1']);
    $this->actingAs($staff)->get('http://app.rightally.io/hq/ai/quality')->assertInertia(fn (Assert $page) => $page->component('hq/ai/quality')
        ->where('sets', fn ($sets) => collect($sets)->firstWhere('prompt', 'ads.listing_copy')['score'] === 67
            && str_contains(implode(' ', collect($sets)->firstWhere('prompt', 'ads.listing_copy')['failures'][0]['why']), 'perfect for families'))
        ->where('untested', fn ($u) => collect($u)->contains('diagnostics.ping')));
    expect(DB::connection()->table('ai_eval_results')->where('passed', false)->count())->toBe(1);

    $this->actingAs($staff)->post('http://app.rightally.io/hq/ai/quality/run')->assertRedirect();
});

it('stops at the budget', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $provider = AiProvider::factory()->create(['key' => 'llm']);
    $model = AiModel::factory()->for($provider, 'provider')->create(['input_cost_per_mtok_cents' => 1_000_000]);
    AiFeatureRoute::query()->create(['feature_key' => 'ads.listing_copy', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.ads']);
    app(ProviderRegistry::class)->fake('llm', new class implements ProviderAdapter
    {
        public function send(AiProvider $provider, ProviderRequest $request): ProviderResponse
        {
            return new ProviderResponse('{"headline": "Home", "body": "A home."}', 400_000, 10);
        }
    });

    $run = app(EvalRunner::class)->live($hq, 1, ['ads.listing_copy']);

    expect($run['status'])->toBe('budget_stopped')->and($run['cases'])->toBe(1);
});
