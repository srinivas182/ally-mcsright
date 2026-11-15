<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\Lead;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\AillyWeek;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\SiteSetup;
use Modules\Sites\Visitors\ClosingBrief;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17D-2: AIlly insights — closing brief, brokerage answers, unanswered questions, hot alerts, audit, results. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    app(LeadRouting::class)->saveRule(['name' => 'Jane', 'method' => 'agent', 'agent_ids' => [$this->jane->id]], $this->owner);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'B'.$i, 'ListingId' => 'M'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 20000, 'LivingArea' => 1800, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704',
        'StreetNumber' => (string) $i, 'StreetName' => 'Elm', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 5));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([Assistant::FEATURE => 'ai.ailly', ClosingBrief::FEATURE => 'ai.closing_brief'] as $f => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    AillySettings::save($this->site, ['enabled' => true, 'gate' => 'booking'], $this->owner);
});

function insChat(): string
{
    return (string) test()->postJson(test()->web.'/_ailly/start', ['vid' => 'vid_insights_000000', 'page' => '/homes'])->json('conversation');
}

function insSay(string $c, string $text, array $ai = []): TestResponse
{
    if ($ai !== []) {
        test()->ai->respond((string) json_encode($ai + ['on_topic' => true, 'next_step' => 'none', 'lead_score' => 40, 'answered' => true]));
    }

    return test()->postJson(test()->web.'/_ailly/message', ['conversation' => $c, 'message' => $text]);
}

function insContact(string $c, array $extra = []): void
{
    test()->postJson(test()->web.'/_ailly/contact', ['conversation' => $c, 'name' => 'Maria Lopez', 'email' => 'maria@example.test', 'vid' => 'vid_insights_000000'] + $extra)
        ->assertJson(['ok' => true]);
}

it('writes a closing brief from the chat and journey, with homes to send next, and shows it on the lead', function (): void {
    $c = insChat();
    insSay($c, 'We are pre-approved for 480k and want 3 beds near 78704 by August', ['reply' => 'Great, I can help with that.', 'lead_score' => 60]);
    $this->ai->respond((string) json_encode(['summary' => 'Pre-approved buyer looking for a 3-bed home in 78704, moving by August.', 'budget' => 'Up to $480,000',
        'timeline' => 'By August', 'financing' => 'Pre-approved', 'must_haves' => ['3 bedrooms', 'Near 78704', 'a family-friendly street'], 'concerns' => [],
        'best_time' => 'Not shared yet', 'opening_line' => 'Hi Maria, I saw you want a 3-bed near 78704 by August.',
        'checklist' => ['Send three 3-bed homes under $480,000 in 78704.', 'Offer showings this weekend.'], 'search' => ['q' => '78704', 'max_price' => 480000, 'beds' => 3]]));

    insContact($c, ['want' => 'call', 'when' => 'evenings']);

    $lead = Lead::query()->latest('id')->firstOrFail();
    $journey = $this->actingAs($this->owner)->getJson('/leads/'.$lead->ulid.'/journey')->assertOk()->json('journey');
    expect($journey['brief'])->toMatchArray(['ai' => true, 'budget' => 'Up to $480,000', 'financing' => 'Pre-approved', 'timeline' => 'By August'])
        ->and($journey['brief']['must_haves'])->toBe(['3 bedrooms', 'Near 78704']) // Fair Housing wording removed
        ->and($journey['brief']['send_next'])->toHaveCount(3)
        ->and($journey['brief']['send_next'][0]['price'])->toBeLessThanOrEqual(480000)
        ->and($journey['chat'])->toContain('Visitor: We are pre-approved for 480k');
});

it('falls back to a brief written from the facts when the AI is unavailable', function (): void {
    $c = insChat();
    insSay($c, 'Looking for homes', ['reply' => 'Happy to help.']);
    AiFeatureRoute::query()->where('feature_key', ClosingBrief::FEATURE)->delete();
    insContact($c);

    $lead = Lead::query()->latest('id')->firstOrFail();
    expect($this->actingAs($this->owner)->getJson('/leads/'.$lead->ulid.'/journey')->json('journey.brief'))
        ->toMatchArray(['ai' => false, 'budget' => 'Not shared yet'])->toHaveKey('checklist');
});

it('answers with the brokerage\'s own answers for free, and collects questions it couldn\'t answer', function (): void {
    $this->actingAs($this->owner)->post('/settings/ailly/answers', ['question' => 'What are your office hours?', 'answer' => 'We are open 9am to 6pm, Monday to Saturday.'])
        ->assertSessionHasNoErrors();
    $c = insChat();
    insSay($c, 'what time is your office open, hours?')->assertJson(['reply' => 'We are open 9am to 6pm, Monday to Saturday.']);
    expect($this->ai->requests)->toHaveCount(0);

    insSay($c, 'Do you charge a buyer agency fee?', ['reply' => 'An agent can explain our fees on a quick call.', 'answered' => false, 'next_step' => 'book_call']);
    insSay(insChat(), 'do you charge a buyer agency fee', ['reply' => 'An agent can explain our fees.', 'answered' => false]);
    expect(json_encode($this->ai->requests[0]))->toContain('We are open 9am to 6pm');

    $this->actingAs($this->owner)->get('/settings/ailly')->assertInertia(fn (Assert $p) => $p->where('open_questions.0.times', 2)
        ->where('answers.0.hits', 1));
    $this->actingAs($this->owner)->post('/settings/ailly/answers', ['question' => 'Do you charge a buyer agency fee?', 'answer' => 'Our buyer agency fee is agreed with you up front.']);
    $this->actingAs($this->owner)->get('/settings/ailly')->assertInertia(fn (Assert $p) => $p->where('open_questions', [])->has('answers', 2));
});

it('alerts the lead\'s agent right away when a hot visitor shares details, once', function (): void {
    $c = insChat();
    insSay($c, 'Pre-approved, want to buy next month', ['reply' => 'Great!', 'lead_score' => 85]);
    $this->ai->respond((string) json_encode(['summary' => 'Ready buyer.', 'checklist' => ['Call today.']]));
    insContact($c);
    insSay($c, 'Thanks', ['reply' => 'You are welcome.', 'lead_score' => 90]);

    Notification::assertSentToTimes($this->jane, LeadAlert::class, 2); // the new-lead alert, and one hot-lead alert
    Notification::assertSentTo($this->jane, LeadAlert::class, fn (LeadAlert $n): bool => str_contains($n->subject, 'Hot lead from your website chat'));
});

it('keeps an audit of what the safety check removed, and shows results with cost per lead', function (): void {
    $c = insChat();
    insSay($c, 'Is the area nice?', ['reply' => 'It is a family-friendly area with many homes for sale. Want to see some?']);
    insContact($c, ['want' => 'call']);
    insChat();

    $csv = $this->actingAs($this->owner)->get('/settings/ailly/audit?month=2026-06')->assertOk()->streamedContent();
    expect($csv)->toContain('family-friendly')->toContain('Removed a sentence with Fair Housing risk wording');

    $this->actingAs($this->owner)->get('/settings/ailly?days=30')->assertInertia(fn (Assert $p) => $p->where('results.chats', 2)->where('results.leads', 1)
        ->where('results.calls', 1)->where('results.lead_rate', 50)->where('results.flagged', 1)->where('by_page.0', ['page' => '/homes', 'chats' => 2, 'leads' => 1, 'rate' => 50])
        ->where('results.cost_per_lead_cents', fn ($v) => $v !== null && $v > 0));
    $this->actingAs($this->owner)->get('/settings/ailly?outcome=call')->assertInertia(fn (Assert $p) => $p->has('conversations', 1));
    $this->actingAs($this->jane)->get('/settings/ailly/audit')->assertForbidden();
});

it('emails owners their AIlly week with questions to answer', function (): void {
    insSay(insChat(), 'Do you help with rentals in Round Rock?', ['reply' => 'An agent can help with that.', 'answered' => false]);
    app(TenantContext::class)->forget();
    $this->artisan('ailly:weekly')->assertSuccessful();

    Notification::assertSentTo($this->owner, AillyWeek::class, fn (AillyWeek $n): bool => $n->chats === 1 && $n->questions === ['Do you help with rentals in Round Rock?']);
});
