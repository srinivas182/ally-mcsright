<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Access\Team;
use Modules\Core\CustomFields\CustomFields;
use Modules\Crm\Models\Lead;
use Modules\Insights\AnomalyMail;
use Modules\Insights\AskYourNumbers;
use Modules\Insights\ReportMail;
use Modules\Transactions\Models\Deal;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00')); // a Monday
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->ben = User::factory()->create(['first_name' => 'Ben']);
    $this->lee = User::factory()->create(['first_name' => 'Lee']);
    $team = Team::query()->create(['name' => 'Lee Team', 'leader_user_id' => $this->lee->id]);
    DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'user_id' => $this->ann->id, 'joined_at' => now()]);
    $mk = fn (User $a, string $status, ?string $closed, int $price, int $gci): Deal => Deal::query()->create(['type' => 'buyer', 'status' => $status, 'agent_user_id' => $a->id,
        'street' => "{$price} Elm", 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => $price * 100, 'gci_cents' => $gci * 100, 'closed_on' => $closed, 'closing_date' => $closed]);
    $this->d1 = $mk($this->ann, 'closed', '2026-02-01', 400000, 12000);
    $mk($this->ann, 'closed', '2026-09-01', 600000, 18000);
    $mk($this->ben, 'closed', '2026-05-01', 300000, 9000);
    $mk($this->ben, 'under_contract', null, 900000, 27000);
});

it('builds reports with filters, grouping, custom fields and CSV export', function (): void {
    app(CustomFields::class)->define('deal', ['label' => 'Lender', 'type' => 'text'], $this->owner);
    app(CustomFields::class)->save('deal', $this->d1->id, ['lender' => 'Chase'], $this->owner);

    $this->actingAs($this->owner)->postJson('/insights/reports/run', ['subject' => 'deals', 'columns' => ['street', 'agent', 'price', 'gci', 'field.lender'],
        'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed'], ['column' => 'price', 'op' => 'over', 'value' => 350000]]])
        ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('rows.1.agent', $this->ann->name)->assertJsonPath('hidden', [])
        ->assertJson(fn ($json) => $json->where('rows', fn ($rows) => $rows[1]['field.lender'] === 'Chase')->etc());

    $this->actingAs($this->owner)->postJson('/insights/reports/run', ['subject' => 'deals', 'columns' => ['agent', 'price', 'gci'],
        'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed']], 'group_by' => 'agent'])
        ->assertJsonPath('groups.0.key', $this->ann->name)->assertJsonPath('groups.0.count', 2)->assertJsonPath('groups.0.sums.gci', 3000000);
    $this->actingAs($this->owner)->postJson('/insights/reports/run', ['subject' => 'deals', 'columns' => ['price'], 'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed']],
        'group_by' => 'month:closed_on'])->assertJsonCount(3, 'groups');
    $this->actingAs($this->owner)->postJson('/insights/reports/run', ['subject' => 'agents', 'columns' => ['name', 'sides_ytd', 'gci_ytd']])
        ->assertJsonPath('rows.0.name', $this->ann->name)->assertJsonPath('rows.0.sides_ytd', 2)->assertJsonPath('rows.0.gci_ytd', 3000000);
    $this->actingAs($this->owner)->postJson('/insights/reports/run', ['subject' => 'deals', 'filters' => [['column' => 'salary', 'op' => 'is', 'value' => 1]]])->assertStatus(422);

    $csv = $this->actingAs($this->owner)->post('/insights/reports/export', ['subject' => 'deals', 'columns' => ['street', 'price'], 'name' => 'All deals']);
    $csv->assertOk();
    expect($csv->getContent())->toContain('Address,Price')->toContain('"400000 Elm",400000.00');
});

it('applies each person\'s own access, even on a shared report', function (): void {
    app(Roles::class)->all();
    app(Roles::class)->assign($this->lee, Role::query()->where('template_key', 'team_lead')->firstOrFail(), $this->owner);
    $this->actingAs($this->owner)->post('/insights/reports', ['name' => 'Closed', 'definition' => ['subject' => 'deals', 'columns' => ['street', 'agent', 'gci'],
        'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed']]], 'shared' => true])->assertSessionHasNoErrors();

    $lee = $this->lee->fresh();
    $this->actingAs($lee)->get('/insights/reports')->assertInertia(fn (Assert $page) => $page->component('insights/reports')->where('saved.0.name', 'Closed')->where('saved.0.mine', false));
    $this->actingAs($lee)->postJson('/insights/reports/run', ['subject' => 'deals', 'columns' => ['street', 'agent', 'gci'], 'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed']]])
        ->assertJsonPath('total', 2)->assertJsonPath('hidden', ['GCI'])->assertJsonMissingPath('rows.0.gci');
    $this->actingAs($lee)->postJson('/insights/reports/run', ['subject' => 'deals', 'filters' => [['column' => 'gci', 'op' => 'over', 'value' => 1]]])->assertStatus(422);
    // An agent with no reporting role sees only their own deals.
    $this->actingAs($this->ben)->postJson('/insights/reports/run', ['subject' => 'deals', 'columns' => ['street']])->assertJsonPath('total', 2);
});

it('emails scheduled reports with the spreadsheet, as their owner sees them', function (): void {
    Notification::fake();
    $this->actingAs($this->owner)->post('/insights/reports', ['name' => 'Weekly closings', 'definition' => ['subject' => 'deals', 'columns' => ['street']], 'schedule' => 'weekly'])->assertSessionHasNoErrors();
    $this->artisan('insights:daily')->assertSuccessful();
    Notification::assertSentTo($this->owner, ReportMail::class, fn (ReportMail $m) => $m->name === 'Weekly closings' && $m->result['total'] === 4);
    Notification::fake();
    $this->artisan('insights:daily')->assertSuccessful();
    Notification::assertNotSentTo($this->owner, ReportMail::class); // once a week
});

it('answers plain-English questions with the real rows behind them', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => AskYourNumbers::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond(json_encode(['subject' => 'deals', 'columns' => ['agent', 'price', 'nonsense'], 'filters' => [['column' => 'status', 'op' => 'is', 'value' => 'closed']],
        'group_by' => 'agent', 'answer_intro' => 'Closed volume by agent this year.']));
    $this->actingAs($this->owner)->postJson('/insights/reports/ask', ['question' => 'who closed the most volume?'])->assertOk()
        ->assertJsonPath('intro', 'Closed volume by agent this year.')->assertJsonPath('definition.columns', ['agent', 'price'])
        ->assertJsonPath('result.groups.0.key', $this->ann->name)->assertJsonPath('result.groups.0.sums.price', 100000000);

    $ai->respond(json_encode(['subject' => 'payroll']));
    $this->actingAs($this->owner)->postJson('/insights/reports/ask', ['question' => 'payroll?'])->assertJsonPath('result', null);
});

it('alerts owners to big changes once', function (): void {
    Notification::fake();
    foreach (range(1, 13) as $i) {
        $lead = Lead::query()->create(['source' => 'zillow', 'channel' => 'portal', 'status' => 'new']);
        Lead::query()->whereKey($lead->id)->update(['created_at' => $i === 13 ? now()->subDays(5) : now()->subDays(40)]);
    }
    $this->artisan('insights:daily')->assertSuccessful();
    Notification::assertSentTo($this->owner, AnomalyMail::class, fn (AnomalyMail $m) => str_contains($m->text, 'Leads from zillow fell from 12 to 1'));
    Notification::fake();
    $this->artisan('insights:daily')->assertSuccessful();
    Notification::assertNothingSentTo($this->owner);
});
