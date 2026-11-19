<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Content\Cadence;
use Modules\Marketing\Content\Publisher;
use Modules\Marketing\Content\Similarity;
use Modules\Marketing\Content\Topics;
use Modules\Marketing\Content\Writer;
use Tests\Fixtures\FakeAiProvider;

/*
| MK3: the HQ AI content desk — checked drafts each night, a person approves every one, publishing tells search
| engines and shares it; 3 a week to start, more only from real indexing numbers; never two alike in a state.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io', 'marketing.hosts' => ['rightally.io']]);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Writer::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    Http::fake(['api.indexnow.org/*' => Http::response('', 200), 'hooks.zapier.com/*' => Http::response('', 200)]);
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/content/desks', ['state' => 'TX', 'mode' => 'live', 'cities' => 'Houston, Austin'])->assertSessionHasNoErrors();
    $this->desk = DB::connection(Connections::control())->table('content_desks')->sole();
});

function cdDraft(string $topic, string $extra = '', array $override = []): string
{
    // Distinct wording per topic, so drafts are not lookalikes of each other.
    $words = fn (int $n, string $seed): string => implode(' ', array_map(fn (int $k): string => strtr(substr(md5($seed.$k), 0, 7), '0123456789', 'ghijklmnop'), range(1, $n)));
    $sections = [];
    foreach (['Licence first', 'Plan the money', 'Pick your tools', 'Hire and train'] as $i => $h) {
        $sections[] = ['heading' => $h, 'body' => $words(60, $topic.$extra.$i)];
    }

    return (string) json_encode($override + [
        'title' => 'Open a brokerage in Texas: '.$topic,
        'description' => str_pad('What it takes to open and run a brokerage in Texas, step by step, for '.$topic.'.', 130, '.'),
        'answer' => $words(45, 'answer'.$topic.$extra),
        'sections' => $sections,
        'faq' => [['q' => 'Do I need a broker licence?', 'a' => 'Yes, from TREC.'], ['q' => 'How long does it take?', 'a' => 'Usually three to six months.'], ['q' => 'Can I start alone?', 'a' => 'Yes.']],
        'facts' => [['fact' => 'TREC issues broker licences.', 'source' => 'https://www.trec.texas.gov/'], ['fact' => 'Brokers need 4 years of experience.', 'source' => 'https://www.trec.texas.gov/apply-for-a-license'],
            ['fact' => 'E&O cover is required.', 'source' => 'https://www.trec.texas.gov/errors-omissions']],
    ]);
}

it('writes checked drafts for the morning review, 3 a week to start and never more than one a day', function (): void {
    $this->ai->respond(cdDraft('owners'));
    $this->artisan('content:desk')->assertSuccessful();
    $this->artisan('content:desk')->assertSuccessful(); // same day: nothing more
    $drafts = DB::connection(Connections::control())->table('content_drafts')->get();
    expect($drafts)->toHaveCount(1)->and($drafts[0]->status)->toBe('review')->and($drafts[0]->city)->toBe('Houston');

    foreach (['teams', 'offices'] as $t) {
        $this->travel(1)->days();
        $this->ai->respond(cdDraft($t, 'x'.$t));
        $this->artisan('content:desk');
    }
    $this->travel(1)->days();
    $this->artisan('content:desk'); // fourth day in the week: level 1 allows 3 a week
    expect(DB::connection(Connections::control())->table('content_drafts')->where('status', 'review')->count())->toBe(3)
        ->and(Cadence::allowance($this->desk))->toBe(['week' => 3, 'day' => 1]);
});

it('rewrites a draft that breaks the rules, then skips the day rather than publish it', function (): void {
    $this->ai->respond(cdDraft('owners', '', ['title' => 'The cheapest way to run a brokerage']))
        ->respond(cdDraft('owners', '', ['facts' => []]))
        ->respond(cdDraft('owners', '', ['description' => 'too short']));
    $this->artisan('content:desk');
    $d = DB::connection(Connections::control())->table('content_drafts')->sole();
    expect($d->status)->toBe('skipped')->and((string) $d->note)->toContain('120 to 155');
});

it('never gives two desks the same topic in a city, and refuses lookalikes', function (): void {
    $other = (object) ['id' => 999, 'tenant_id' => 5, 'state' => 'TX', 'cities' => json_encode(['Houston'])];
    $a = Topics::next($this->desk, Topics::HQ);
    $b = Topics::next($other, Topics::HQ);
    expect([$a['city'], $a['topic'], $a['angle']])->not->toBe([$b['city'], $b['topic'], $b['angle']]);

    $text = str_repeat('Running a brokerage in Houston means planning licences, money and agents carefully. ', 10);
    expect(Similarity::score($text, $text))->toBe(1.0)->and(Similarity::score($text, 'Something completely different about Austin homes and markets today.'))->toBe(0.0);
});

it('publishes an approved draft, tells search engines and shares it; test mode only saves a draft', function (): void {
    $services = app(OutsideServices::class);
    $services->save(0, 'social_share', 'zapier', ['webhook_url' => 'https://hooks.zapier.com/hooks/catch/1/abc'], [], null, $this->staff);
    $services->setMode(0, 'social_share', 'test', $this->staff);
    app()->instance('connect.dns', fn (string $host): array => ['93.184.216.34']);
    $this->ai->respond(cdDraft('owners'));
    $this->artisan('content:desk');
    $ulid = (string) DB::connection(Connections::control())->table('content_drafts')->value('ulid');

    $this->actingAs($this->staff)->post("http://app.rightally.io/hq/content/drafts/{$ulid}/approve", ['title' => 'Running a brokerage in Houston, Texas'])->assertSessionHasNoErrors();
    $d = DB::connection(Connections::control())->table('content_drafts')->sole();
    expect($d->status)->toBe('published')->and((string) $d->published_url)->toStartWith('https://rightally.io/tx/');
    $this->get((string) $d->published_url)->assertOk()->assertSee('Running a brokerage in Houston, Texas');
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'api.indexnow.org') && $r['urlList'] === [(string) $d->published_url]);
    $this->get('http://rightally.io/indexnow-key.txt')->assertOk()->assertSee(Publisher::indexNowKey());
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'hooks.zapier.com') && $r['url'] === (string) $d->published_url);
});

it('moves up a level only from real Search Console numbers, and back down on a warning', function (): void {
    $db = DB::connection(Connections::control());
    $db->table('content_drafts')->insert(array_map(fn (int $i): array => ['ulid' => (string) Str::ulid(), 'desk_id' => $this->desk->id, 'tenant_id' => 0, 'state' => 'TX',
        'city' => 'Houston', 'kind' => 'article', 'topic' => 't', 'title' => 'T'.$i, 'slug' => 's'.$i, 'description' => 'd', 'body' => 'b', 'status' => 'published',
        'created_at' => now(), 'updated_at' => now()], range(1, 10)));
    $this->travel(29)->days();
    expect(Cadence::review())->toBe([]); // no Search Console numbers: stays at 3 a week
    $db->table('content_index_stats')->insert(['desk_id' => $this->desk->id, 'day' => now()->toDateString(), 'submitted' => 10, 'indexed' => 9, 'warnings' => 0, 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now()]);
    expect(Cadence::review()[0]['to'] ?? null)->toBe(2);
    $this->travel(1)->days();
    $db->table('content_index_stats')->insert(['desk_id' => $this->desk->id, 'day' => now()->toDateString(), 'submitted' => 10, 'indexed' => 9, 'warnings' => 1, 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now()]);
    expect(Cadence::review()[0]['to'] ?? null)->toBe(1);
});

it('keeps the content desk to HQ', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/content')->assertInertia(fn (Assert $p) => $p->component('hq/content')->has('desks', 1));
    $this->onTenantHost('acme');
    $this->actingAs(User::factory()->owner()->create())->get('/hq/content')->assertNotFound();
});
