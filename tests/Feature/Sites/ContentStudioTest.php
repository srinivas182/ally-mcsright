<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Ai\ContentStudio;
use Modules\Sites\Ai\DraftsReady;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17C-4: the AI content studio — drafts grounded in MLS figures, number and Fair Housing guards, SEO retry. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea', 'last_name' => 'Broker']);
    $this->agent = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'S'.$i, 'ListingId' => 'M'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 25000, 'LivingArea' => 1800, 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'StreetNumber' => (string) $i,
        'StreetName' => 'Oak', 'DaysOnMarket' => 20 + $i, 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 3));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([ContentStudio::POST => 'ai.site_post', ContentStudio::PAGE => 'ai.site_page', ContentStudio::ASSIST => 'ai.site_assist', ContentStudio::MARKET => 'ai.site_market'] as $f => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
});

function goodPostBody(string $extra = ''): string
{
    return "Homes in Austin, TX are listed at a median of \$450,000 as of June 1, 2026, and most buyers have room to compare options before making an offer. Here is what the current market means for you and how to plan your next move with confidence.\n\n"
        ."## How much do homes cost in Austin right now?\n\nThe median list price is \$450,000. {$extra}See [homes for sale](/homes).\n\n"
        ."## How long do homes take to sell in Austin?\n\nTypical days on market is 22.\n\n## What should buyers do next?\n\n"
        .str_repeat('Get pre-approved, tour homes with an agent, and compare recent listings carefully before you write an offer on any home. ', 50)
        ."\n\n## Frequently asked questions\n\n### Is now a good time to buy?\n\nIt depends on your plans.";
}

it('drafts a blog post from MLS figures, removing invented numbers and Fair Housing risk wording', function (): void {
    $this->ai->respond((string) json_encode(['title' => 'Austin home prices: what buyers should know', 'excerpt' => 'What the Austin market looks like this month.',
        'meta_description' => 'Austin homes have a median list price of $450,000 as of June 1, 2026. See what that means for buyers and sellers this month.',
        'category' => 'Market updates',
        'body' => goodPostBody('Mortgage rates are 6.8% this week. It is a family-friendly area with great parks. ')]));

    $res = $this->actingAs($this->owner)->post('/settings/website/ai/post', ['topic' => 'Austin home prices in June', 'area' => 'austin-tx', 'tone' => 'expert']);
    $post = SitePost::query()->sole();
    $res->assertRedirect('/settings/website/blog/'.$post->ulid);
    expect($post->status)->toBe('draft')->and($post->body)->toContain('$450,000')->not->toContain('6.8%')->not->toContain('family-friendly')
        ->and($post->category)->toBe('Market updates')->and($post->author_user_id)->toBe($this->owner->id);
    $notes = session('ai_notes');
    expect($notes['score'])->toBeGreaterThanOrEqual(80)->and($notes['warnings'])->toHaveCount(2)
        ->and($notes['warnings'][0])->toContain('figure we could not verify (6.8)')->and($notes['warnings'][1])->toContain('Fair Housing');
    $sent = json_encode($this->ai->requests);
    expect($sent)->toContain('Median list price: $450,000')->toContain('as of June 1, 2026');
    $this->get($this->web.'/blog/'.$post->slug)->assertNotFound(); // a draft, never published by AI
    $this->actingAs($this->agent)->post('/settings/website/ai/post', ['topic' => 'Anything'])->assertForbidden();
});

it('rewrites once with the exact fixes when the first draft scores under 80', function (): void {
    $this->ai->respond((string) json_encode(['title' => 'Hi', 'excerpt' => '', 'meta_description' => '', 'body' => 'Too short.']));
    $this->ai->respond((string) json_encode(['title' => 'Austin home prices: what buyers should know', 'excerpt' => 'This month in Austin.',
        'meta_description' => 'Austin homes have a median list price of $450,000 as of June 1, 2026. See what that means for buyers and sellers.', 'body' => goodPostBody()]));
    $this->actingAs($this->owner)->post('/settings/website/ai/post', ['topic' => 'Austin prices', 'area' => 'austin-tx'])->assertRedirect();
    expect($this->ai->requests)->toHaveCount(2)->and(json_encode($this->ai->requests[1]))->toContain('Open with a 25')
        ->and(session('ai_notes')['score'])->toBeGreaterThanOrEqual(80);
});

it('drafts a page as sections and helps edit with rewrite, FAQ, meta and agent bios', function (): void {
    $this->ai->respond((string) json_encode(['title' => 'Selling a home in 78704', 'meta_description' => 'How to sell a home in 78704: pricing, preparing and listing with a local agent who knows the area well.',
        'intro' => 'Selling in 78704 starts with pricing right. Homes have a median list price of $450,000 and typically sit 22 days. A local agent prices, prepares and markets your home.',
        'blocks' => [['heading' => 'How do I price my home?', 'body' => 'Compare recent listings near you. Experts say prices rise 9% next year.']],
        'faq' => [['q' => 'How long does it take to sell?', 'a' => 'Typically 22 days on market.']], 'cta' => ['title' => 'Talk to us', 'body' => '', 'button' => 'Get started']]));
    $this->actingAs($this->owner)->post('/settings/website/ai/page', ['topic' => 'Selling a home in 78704', 'path' => '/sell-78704', 'area' => '78704'])->assertRedirect();
    $page = SitePage::query()->where('path', '/sell-78704')->sole();
    expect($page->status)->toBe('draft')->and(array_column($page->draft_sections, 'type'))->toBe(['text', 'text', 'faq', 'cta'])
        ->and($page->draft_sections[1]['props']['body'])->toBe('Compare recent listings near you.')->and(session('ai_notes')['warnings'])->toHaveCount(1);

    $this->ai->respond((string) json_encode(['result' => 'A clearer version. Buy with 3 steps and save 15%.']));
    $r = $this->actingAs($this->owner)->postJson('/settings/website/ai/assist', ['action' => 'rewrite', 'mode' => 'improve', 'content' => 'Our team helps buyers in Austin.'])->assertOk()->json();
    expect($r['result'])->toBe('A clearer version.')->and($r['warnings'][0])->toContain('(15)');

    $this->ai->respond((string) json_encode(['result' => [['q' => 'Do you help buyers?', 'a' => 'Yes, across Austin.'], ['q' => '', 'a' => 'dropped']]]));
    expect($this->actingAs($this->owner)->postJson('/settings/website/ai/assist', ['action' => 'faq', 'content' => 'We help buyers across Austin.'])->json('result'))
        ->toBe([['q' => 'Do you help buyers?', 'a' => 'Yes, across Austin.']]);

    // Agents get help only on their own website.
    $this->actingAs($this->agent)->post('/settings/my-website');
    $agentSite = Site::query()->where('kind', 'agent')->sole();
    $this->ai->respond((string) json_encode(['result' => 'I help buyers in South Austin find the right home.']));
    $this->actingAs($this->agent)->postJson('/settings/website/ai/assist', ['action' => 'bio', 'site' => $agentSite->ulid, 'profile' => ['name' => 'Jane Doe', 'areas' => 'South Austin']])
        ->assertOk()->assertJson(['result' => 'I help buyers in South Austin find the right home.']);
    $this->actingAs($this->agent)->postJson('/settings/website/ai/assist', ['action' => 'rewrite', 'content' => 'Brokerage text'])->assertUnprocessable();
});

it('explains when AI writing is not available', function (): void {
    AiFeatureRoute::query()->where('feature_key', ContentStudio::POST)->delete();
    $this->actingAs($this->owner)->post('/settings/website/ai/post', ['topic' => 'Anything'])->assertSessionHasErrors(['ai' => 'AI writing is not set up for your account yet. Ask RightAlly support to turn it on.']);
    expect(SitePost::query()->count())->toBe(0);
});

it('drafts monthly market updates for the chosen areas, once per month, and emails owners to review', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/ai/market-updates', ['enabled' => true, 'places' => ['nowhere-tx']])->assertSessionHasErrors('places');
    $this->actingAs($this->owner)->post('/settings/website/ai/market-updates', ['enabled' => true, 'places' => ['austin-tx', 'made-up']])->assertSessionHasNoErrors();
    expect($this->site->refresh()->settings['market_updates'])->toEqual(['enabled' => true, 'places' => ['austin-tx']]); // MySQL reorders JSON keys

    $this->ai->respond((string) json_encode(['title' => 'Austin, TX housing market update, June 2026', 'excerpt' => 'This month in Austin.',
        'meta_description' => 'Austin homes have a median list price of $450,000 as of June 1, 2026. See what that means for buyers and sellers.', 'body' => goodPostBody()]));
    $this->artisan('sites:market-updates')->assertSuccessful();
    $post = SitePost::query()->sole();
    expect($post->slug)->toBe('austin-tx-housing-market-update-june-2026')->and($post->status)->toBe('draft')->and($post->category)->toBe('Market updates');
    Notification::assertSentTo($this->owner, DraftsReady::class, fn (DraftsReady $n): bool => $n->posts[0]['ulid'] === $post->ulid);

    $this->artisan('sites:market-updates')->assertSuccessful(); // same month: nothing new
    expect(SitePost::query()->count())->toBe(1)->and($this->ai->requests)->toHaveCount(1);
});

it('reads figures the way people write them', function (): void {
    expect(ContentStudio::numbers('$450,000 and 12% over 22 days in 2026, $1.2M or 450k'))->toBe(['450000', '12', '22', '2026', '1200000']);
    [$text, $w] = ContentStudio::guard("Median is \$450,000. Rates hit 7.1%. Prices rose 5%. Read 3 tips.\n## A heading", ['450000']);
    expect($text)->toBe("Median is \$450,000. Read 3 tips.\n## A heading")->and($w)->toHaveCount(2);
});
