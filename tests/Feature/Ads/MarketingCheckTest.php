<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Review\AdCheck;
use Modules\Ads\Review\MarketingReviews;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Sync\ListingSync;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* MK3 (specs/ads/20): the marketing check, one-click fixes, AI rewrite, and broker approval. */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['name' => 'Acme Realty'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Deemed reliable.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $feed->records = [['ListingKey' => 'K1', 'ListingId' => 'MLS100', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 425000, 'OriginalListPrice' => 425000,
        'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1850, 'StreetNumber' => '12', 'StreetName' => 'Oak St', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-20', 'ModificationTimestamp' => '2026-05-20T00:00:00Z']];
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

it('marks Fair Housing, missing brokerage, wrong MLS facts, promises and financing terms, with fixes', function (): void {
    $r = app(AdCheck::class)->run('Just listed! Family-friendly 4 bed, 2 bath home, 2,100 sq ft, $450k. #1 agent in Austin. Only 3% down!', 'flyer', 'MLS100');
    $rules = array_count_values(array_column($r['findings'], 'rule'));
    expect($r['ready'])->toBeFalse()
        ->and($rules['fair_housing'])->toBe(1)->and($rules['brokerage_name'])->toBe(1)->and($rules['claims'])->toBe(1)->and($rules['financing'])->toBe(1)
        ->and($rules['equal_housing'])->toBe(1)->and($rules['square_feet'])->toBe(1)
        ->and($rules['mls'])->toBe(3) // price, beds, square feet
        ->and(collect($r['findings'])->firstWhere('text', '4 bed')['why'])->toBe('The ad says 4 bed; the MLS says 3 bedrooms.')
        ->and($r['fixed'])->toContain('spacious 4 bed')->toContain('Acme Realty')->toContain('Equal Housing Opportunity')
        ->and($r['listing']['address'])->toContain('12 Oak St');

    $clean = app(AdCheck::class)->run('Acme Realty presents 12 Oak St: 3 bedrooms, 2 baths, approx. 1,850 sq ft, $425,000. Big shady backyard.', 'social', 'MLS100');
    expect($clean['findings'])->toBe([])->and($clean['ready'])->toBeTrue();
    expect(app(AdCheck::class)->run('Acme Realty: new listing', 'ad', 'NOPE')['findings'][0]['rule'])->toBe('mls');
    expect(DB::table('crm_fair_housing_log')->where('channel', 'ads')->count())->toBe(1);
});

it('checks from the page, rewrites with AI for a credit, and runs the check again', function (): void {
    assertPageContract($this->actingAs($this->agent)->get('/marketing-check'), 'ads/marketing-check');
    $this->actingAs($this->agent)->post('/marketing-check', ['body' => 'Great for kids! Call Acme Realty.', 'kind' => 'social'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/marketing-check')->assertInertia(fn (Assert $p) => $p->where('result.ready', false)->where('result.findings.0.rule', 'fair_housing'));

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => MarketingReviews::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.ad_compliance']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    app(CreditLedger::class)->add($this->agent->id, 100, 'adjust', 'Test credits', $this->owner);
    $ai->respond(json_encode(['text' => 'A big backyard with room to play. Call Acme Realty.']));
    $this->actingAs($this->agent)->post('/marketing-check', ['body' => 'Great for kids! Call Acme Realty.', 'kind' => 'social', 'rewrite' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/marketing-check')->assertInertia(fn (Assert $p) => $p->where('result.ready', true)->where('result.rewritten', true)
        ->where('result.input.body', 'A big backyard with room to play. Call Acme Realty.'));
    expect($ai->requests[0]->userMessage)->toContain('Great for kids!');
});

it('sends marketing for approval and lets the broker approve or send it back', function (): void {
    $this->actingAs($this->agent)->post('/marketing-check/settings', ['approval_required' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/marketing-check/settings', ['approval_required' => true])->assertRedirect();
    $pdf = UploadedFile::fake()->createWithContent('flyer.pdf', "%PDF-1.4\n%%EOF");
    $this->actingAs($this->agent)->post('/marketing-check/submit', ['body' => 'Safe neighborhood! Acme Realty.', 'kind' => 'flyer', 'title' => 'Oak St flyer', 'file' => $pdf])
        ->assertSessionHasNoErrors();
    $ulid = (string) DB::table('ads_reviews')->value('ulid');

    $this->actingAs($this->owner)->get('/marketing-check')->assertInertia(fn (Assert $p) => $p->where('approval_required', true)->where('reviewer', true)
        ->where('queue.0.title', 'Oak St flyer')->where('queue.0.has_file', true)->where('queue.0.findings.0.rule', 'fair_housing'));
    $this->actingAs($this->owner)->get("/marketing-check/{$ulid}/file")->assertOk();
    $this->actingAs(User::factory()->create())->get("/marketing-check/{$ulid}/file")->assertNotFound(); // another agent
    $this->actingAs($this->agent)->post("/marketing-check/{$ulid}/decide", ['status' => 'approved'])->assertForbidden();
    $this->actingAs($this->owner)->post("/marketing-check/{$ulid}/decide", ['status' => 'changes'])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post("/marketing-check/{$ulid}/decide", ['status' => 'changes', 'note' => 'Describe the street, not safety.'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/marketing-check')->assertInertia(fn (Assert $p) => $p->where('mine.0.status', 'changes')->where('mine.0.note', 'Describe the street, not safety.')
        ->where('queue', []));
});
