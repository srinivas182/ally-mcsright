<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Recruiting\Models\MlsAgent;
use Tests\Fixtures\FakeAiProvider;

/*
| AR3 (specs/network/03): the AI networking assistant. It reads what a moving client needs (never who they are),
| finds network agents and the top local agents by side from the brokerage's MLS data, and drafts a first message
| the agent sends themselves.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->pine = $this->onTenantHost('pine');
    $pineOwner = User::factory()->owner()->create();
    $rosa = User::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Diaz']);
    $this->actingAs($pineOwner)->post('/settings/network', ['enabled' => true, 'default_fee_percent' => '25']);
    $this->actingAs($rosa)->post('/network/profile', ['areas' => ['Austin, TX'], 'sides' => 'buyers', 'languages' => ['English', 'Spanish'], 'state_code' => 'TX']);
    $this->acme = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->actingAs($this->owner)->post('/settings/network', ['enabled' => true, 'default_fee_percent' => '25']);

    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $auth = MlsAuthorization::query()->create(['tenant_id' => $this->acme->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'office_mls_ids' => ['OURS'],
        'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, ['idx', 'agent_production'], null, null, User::factory()->hqStaff()->make());
    $n = 0;
    // Lee lists 3 homes in Austin, Kim 1; Kim is the buyer's agent on 2. One old sale doesn't count.
    foreach ([['LEE', 'KIM', '2026-05-01'], ['LEE', 'KIM', '2026-06-01'], ['LEE', null, '2026-07-01'], ['KIM', null, '2026-07-15'], ['OLD', null, '2025-01-01']] as [$l, $b, $d]) {
        $n++;
        Listing::query()->create(['mls_source_id' => $source->id, 'listing_key' => 'N'.$n, 'standard_status' => 'Closed', 'street_number' => (string) $n, 'street_name' => 'Elm St',
            'city' => 'Austin', 'state_or_province' => 'TX', 'postal_code' => '78704', 'list_price_cents' => 50_000_000, 'close_price_cents' => 50_000_000, 'close_date' => $d,
            'list_agent_mls_id' => $l, 'list_agent_name' => ucfirst(strtolower($l)).' Lister', 'list_office_mls_id' => 'O'.$l, 'list_office_name' => ucfirst(strtolower($l)).' Realty',
            'buyer_agent_mls_id' => $b, 'modification_timestamp' => now()]);
    }
    // Buyer agents' names come from the agent database (the listing only has their MLS id).
    MlsAgent::query()->create(['mls_source_id' => $source->id, 'agent_mls_id' => 'KIM', 'name' => 'Kim Buyerson', 'office_name' => 'Kim Realty', 'refreshed_at' => now()]);
});

function fakeNetworkAi(string $feature, string $credit): FakeAiProvider
{
    $model = AiModel::factory()->create(['capabilities' => ['chat', 'json']]);
    AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    app(ProviderRegistry::class)->fake($model->provider->key, $ai = new FakeAiProvider);

    return $ai;
}

it('reads what the client needs and finds network agents and the top local agents by side', function (): void {
    fakeNetworkAi('network.match', 'ai.network_match')->respond('{"side": "seller", "area": "Austin, TX", "price_band": "about $500k", "timing": null, "language": null, "needs": null, "unclear": []}');
    $this->actingAs($this->ann)->post('/network/assistant', ['words' => 'My clients want to sell their house in Austin, about $500k.'])->assertRedirect('/network?tab=assistant');

    $this->actingAs($this->ann)->get('/network?tab=assistant')->assertInertia(fn (Assert $page) => $page->where('assistant.criteria.side', 'seller')
        ->has('assistant.found.network', 0) // Rosa helps buyers only
        ->where('assistant.found.local.0.name', 'Lee Lister')->where('assistant.found.local.0.sides', 3)->where('assistant.found.local.0.office', 'Lee Realty')
        ->where('assistant.found.local.1.name', 'Kim Buyerson')->has('assistant.found.local', 2));
});

it('finds buyer-side agents and drafts a first message the agent sends themselves, without client details', function (): void {
    fakeNetworkAi('network.match', 'ai.network_match')->respond('{"side": "buyer", "area": "Austin", "price_band": null, "timing": "this spring", "language": "Spanish", "needs": null, "unclear": []}');
    $this->actingAs($this->ann)->post('/network/assistant', ['words' => 'Buyers moving to Austin this spring, they prefer Spanish.']);
    $this->actingAs($this->ann)->get('/network?tab=assistant')->assertInertia(fn (Assert $page) => $page->where('assistant.found.network.0.name', 'Rosa Diaz')
        ->where('assistant.found.local.0.name', 'Kim Buyerson')->where('assistant.found.local.0.sides', 2));

    $ai = fakeNetworkAi('network.intro', 'ai.network_intro');
    $ai->respond('{"message": "Hi Kim, I\'m Ann Agent with Acme. I have clients buying in Austin this spring who prefer Spanish. I\'d offer a usual referral fee, agreed in writing. Open to a quick call?"}');
    $key = (string) session('network.assistant.found.local.0.key');
    $this->actingAs($this->ann)->post('/network/assistant/intro', ['key' => $key])->assertRedirect('/network?tab=assistant');
    $this->actingAs($this->ann)->get('/network?tab=assistant')->assertInertia(fn (Assert $page) => $page->where("assistant.intros.{$key}", fn (string $m): bool => str_starts_with($m, 'Hi Kim')));
    $this->actingAs($this->ann)->post('/network/assistant/intro', ['key' => 'nope'])->assertNotFound();

    // A draft with Fair Housing wording is not shown.
    $ai->respond('{"message": "Hi Kim, a family-friendly area would suit them. Call?"}');
    session()->put('network.assistant.intros', []);
    $this->actingAs($this->ann)->post('/network/assistant/intro', ['key' => $key])->assertSessionHasErrors('intro');
});

it('asks for more when the description is too short', function (): void {
    $this->actingAs($this->ann)->post('/network/assistant', ['words' => 'Denver'])->assertSessionHasErrors('words');
});
