<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Entitlements;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Content\Cadence;
use Modules\Marketing\Content\Publisher;
use Modules\Marketing\Content\Writer;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/*
| MK4: the AI blog writer add-on — a brokerage's own local posts, each carrying the owner's words or MLS figures, never
| the same topic as another brokerage in the city, never like anything else in the state, 3 a week to start and
| never more than 1 a day, every post approved by the broker and published with its author named.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Writer::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
});

/** A brokerage with a live website and the blog writer on (Austin, Texas). */
function bwBrokerage(string $slug, string $name, string $words = 'Our east side has older brick homes on big lots, and sellers there often ask us about foundation checks before listing.'): array
{
    $tenant = test()->onTenantHost($slug);
    $owner = User::factory()->owner()->create();
    $sites = app(SiteSetup::class);
    $site = $sites->create('modern', ['name' => $name, 'area' => 'Austin', 'state' => 'TX', 'licence' => '9001234'], $owner);
    SitePost::query()->where('site_id', $site->id)->delete(); // these tests start with an empty blog (TP2 adds three starter posts)
    $sites->publish($site, true, $owner);
    test()->actingAs($owner)->post('/settings/blog-writer', ['mode' => 'live', 'state' => 'TX', 'cities' => 'Austin', 'words' => $words])->assertSessionHasNoErrors();

    return [$tenant, $owner];
}

function bwDraft(string $seed, array $override = []): string
{
    $words = fn (int $n, string $s): string => implode(' ', array_map(fn (int $k): string => strtr(substr(md5($s.$k), 0, 7), '0123456789', 'ghijklmnop'), range(1, $n)));
    $sections = [];
    foreach (['Before you list', 'What buyers notice', 'Pricing it right', 'When to move'] as $i => $h) {
        $sections[] = ['heading' => $h, 'body' => $words(60, $seed.$i)];
    }

    return (string) json_encode($override + [
        'title' => 'Selling an older Austin home: '.$seed,
        'description' => str_pad('What Austin sellers of older homes should check before listing, in plain steps, for '.$seed.'.', 130, '.'),
        'answer' => $words(45, 'answer'.$seed),
        'sections' => $sections,
        'faq' => [['q' => 'Should I fix the foundation first?', 'a' => 'Get it checked first.'], ['q' => 'How long do homes take to sell?', 'a' => 'Ask us for today\'s numbers.'], ['q' => 'Do I need staging?', 'a' => 'Often it helps.']],
        'facts' => [['fact' => 'Older brick homes on big lots on the east side.', 'source' => 'your-words'], ['fact' => 'Sellers ask about foundation checks.', 'source' => 'your-words'],
            ['fact' => 'Texas sellers fill in a disclosure notice.', 'source' => 'https://www.trec.texas.gov/forms']],
    ]);
}

it('writes a post with the owner\'s own words, and publishes it on their website under their name once approved', function (): void {
    [$tenant, $owner] = bwBrokerage('acme', 'Acme Realty');
    $this->ai->respond(bwDraft('acme'));
    $this->artisan('content:tenant-desks')->assertSuccessful();

    $draft = DB::connection(Connections::control())->table('content_drafts')->sole();
    expect($draft->status)->toBe('review')->and((int) $draft->tenant_id)->toBe($tenant->id)->and($draft->city)->toBe('Austin');
    expect(serialize($this->ai->requests[0]))->toContain('foundation checks'); // the owner's words reach the writer

    app(TenantContext::class)->set($tenant);
    $this->actingAs($owner)->get('/settings/blog-writer')->assertInertia(fn (Assert $p) => $p->component('settings/blog-writer')
        ->where('included', true)->has('drafts', 1)->where('desks.0.level', 1));
    $this->actingAs($owner)->post('/settings/blog-writer/drafts/'.$draft->ulid.'/approve')->assertSessionHasNoErrors();

    $post = SitePost::query()->sole();
    expect($post->status)->toBe('published')->and($post->body)->toContain('Written for Acme Realty (licence 9001234)')->and($post->category)->toBe('Local guides');
    $url = (string) DB::connection(Connections::control())->table('content_drafts')->value('published_url');
    expect($url)->toEndWith('/blog/'.$post->slug);
    $host = (string) parse_url($url, PHP_URL_HOST);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'indexnow') && $r['key'] === Publisher::indexNowKey($host) && $r['urlList'] === [$url]);
    $this->get('http://'.$host.'/indexnow-key.txt')->assertOk()->assertSee(Publisher::indexNowKey($host));
});

it('never gives two brokerages in one city the same topic, and never publishes a lookalike', function (): void {
    [$a] = bwBrokerage('acme', 'Acme Realty');
    [$b] = bwBrokerage('pine', 'Pine Homes', 'We sell many new-build homes in the north suburbs, where buyers compare builder upgrades.');
    $this->ai->respond(bwDraft('first'))->respond(bwDraft('first'))->respond(bwDraft('first'))->respond(bwDraft('first'));
    $this->artisan('content:tenant-desks')->assertSuccessful();

    $drafts = DB::connection(Connections::control())->table('content_drafts')->orderBy('id')->get();
    expect($drafts)->toHaveCount(2)->and($drafts[0]->status)->toBe('review')
        ->and($drafts[1]->status)->toBe('skipped')->and((string) $drafts[1]->note)->toContain('too like something already written');
    $bookings = DB::connection(Connections::control())->table('content_bookings')->where('city', 'Austin')->get();
    expect($bookings->pluck('topic')->map(fn ($t, $i) => $t.'/'.$bookings[$i]->angle)->unique())->toHaveCount(2); // different topic or angle each

    // Each brokerage sees only its own drafts.
    app(TenantContext::class)->set($b);
    $owner = User::query()->where('kind', 'owner')->sole();
    $this->actingAs($owner)->get('/settings/blog-writer')->assertInertia(fn (Assert $p) => $p->where('drafts', fn ($d) => collect($d)->every(fn ($x) => $x['status'] === 'skipped')));
    $this->actingAs($owner)->post('/settings/blog-writer/drafts/'.$drafts[0]->ulid.'/approve')->assertSessionHasErrors('draft');
    expect(SitePost::query()->count())->toBe(0);
});

it('skips a day when there is nothing unique to say, and keeps to 3 a week and 1 a day', function (): void {
    [$tenant, $owner] = bwBrokerage('acme', 'Acme Realty', '');
    $this->artisan('content:tenant-desks')->assertSuccessful();
    $d = DB::connection(Connections::control())->table('content_drafts')->sole();
    expect($d->status)->toBe('skipped')->and((string) $d->note)->toContain('your own words');
    expect($this->ai->requests)->toHaveCount(0); // no AI spent on a post that could only be generic

    $this->actingAs($owner)->post('/settings/blog-writer', ['mode' => 'live', 'state' => 'TX', 'cities' => 'Austin', 'ideas' => "Why east side lots sell fast\nWhat to fix before listing",
        'words' => 'We walk every listing with a builder first.'])->assertSessionHasNoErrors();
    foreach (range(1, 5) as $day) {
        $this->ai->respond(bwDraft('day'.$day));
        $this->artisan('content:tenant-desks');
        $this->artisan('content:tenant-desks'); // twice a day still writes at most one
        $this->travel(1)->days();
    }
    $written = DB::connection(Connections::control())->table('content_drafts')->where('status', 'review')->orderBy('id')->get();
    expect($written)->toHaveCount(3)->and($written[0]->title)->toContain('Selling an older Austin home');
    expect(DB::connection(Connections::control())->table('content_bookings')->where('topic', 'like', 'idea-%')->count())->toBe(2); // ideas first, each once

    // Even at the top level a brokerage site is held to 1 a day.
    $desk = DB::connection(Connections::control())->table('content_desks')->sole();
    $desk->level = 3;
    expect(Cadence::allowance($desk))->toBe(['week' => 7, 'day' => 1]);
});

it('is an extra: only brokerages that have it can turn it on, and only the broker approves', function (): void {
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $package = Package::query()->create(['name' => 'Core', 'audience' => 'brokerage', 'monthly_cents' => 9_900, 'yearly_cents' => 0, 'features' => [], 'active' => true]);
    $tenant->forceFill(['billing_package_id' => $package->id])->save();
    app(TenantContext::class)->set($tenant->fresh());
    $this->actingAs($owner)->get('/settings/blog-writer')->assertInertia(fn (Assert $p) => $p->where('included', false));
    $this->actingAs($owner)->post('/settings/blog-writer', ['mode' => 'live', 'state' => 'TX', 'cities' => 'Austin'])->assertSessionHasErrors('desk');

    $package->forceFill(['features' => ['content.blog']])->save();
    app(Entitlements::class)->forget(); // a new request would see it at once
    $this->actingAs($owner)->post('/settings/blog-writer', ['mode' => 'test', 'state' => 'TX', 'cities' => 'Austin'])->assertSessionHasNoErrors();
    $staffAgent = User::factory()->create();
    $this->actingAs($staffAgent)->post('/settings/blog-writer/drafts/01J00000000000000000000000/reject')->assertForbidden();
});
