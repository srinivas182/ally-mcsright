<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Catalogue;
use Modules\Billing\HealthCheck\HealthChecks;
use Modules\Billing\HealthCheck\HealthReportMail;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

const HC = 'http://app.rightally.io';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hq, fn () => User::factory()->hqStaff()->create());
    app()->instance('connect.dns', fn (string $host): array => match ($host) {
        'intranet.example.com' => ['10.0.0.5'],
        default => ['93.184.216.34'],
    });
    Package::query()->create(['name' => 'Brokerage', 'monthly_cents' => 29_900, 'yearly_cents' => 299_000, 'ailly_conversations' => 500, 'features' => [], 'active' => true]);
    Package::query()->create(['name' => 'Brokerage Plus', 'monthly_cents' => 49_900, 'yearly_cents' => 499_000, 'ailly_conversations' => 1000, 'features' => [], 'active' => true]);
    AgentPlan::query()->create(['key' => 'core', 'name' => 'Agent', 'list_cents' => 2_000, 'floor_cents' => 1_500, 'features' => [], 'sort' => 1, 'active' => true]);
    app(Catalogue::class)->saveTiers([['min_paying_agents' => 1, 'percent' => '20']]);
});

/** @return array<string, mixed> */
function healthForm(array $over = []): array
{
    return array_replace([
        'brokerage' => 'Oak Realty', 'contact_name' => 'Dana Smith', 'email' => 'dana@oak.test', 'state' => 'TX', 'agents' => 20,
        'answers' => ['lead_reply' => 'day', 'file_check' => 'after_closing', 'payout_days' => 'week', 'past_clients' => 'some'],
        'who_pays' => 'brokerage',
        'tools' => [
            ['key' => 'follow_up_boss', 'cost' => '69', 'per_agent' => '1'],
            ['key' => 'dotloop', 'cost' => '300'],
            ['key' => 'showings', 'cost' => '100'],
            ['key' => 'other', 'cost' => '500'],
            ['key' => '', 'cost' => ''],
        ],
        'other_name' => 'Ylopo',
    ], $over);
}

function healthSite(): string
{
    return '<!doctype html><html lang="en"><head><title>Oak Realty | Austin homes for sale</title>'
        .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0e7490">'
        .'<meta name="description" content="Oak Realty helps Austin families buy and sell homes, with local agents and every MLS listing.">'
        .'<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"RealEstateAgent","name":"Oak Realty"}]}</script></head>'
        .'<body><h1>Oak Realty</h1><a href="/homes-for-sale">Search homes</a><a href="tel:+15125550100">Call</a><a href="/privacy">Privacy</a>'
        .'<img src="a.jpg" alt="Our office"><p>Equal Housing Opportunity</p></body></html>';
}

/** @return string the report token from the redirect */
function submitHealth(array $over = [], ?UploadedFile $logo = null): string
{
    $form = healthForm($over);
    if ($logo !== null) {
        $form['logo'] = $logo;
    }
    $r = test()->post(HC.'/health-check', $form)->assertRedirect();

    return (string) str((string) $r->headers->get('Location'))->afterLast('/');
}

it('shows an indexable questionnaire with FAQ markup and no sales call', function (): void {
    $this->get(HC.'/health-check')->assertOk()
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('<link rel="canonical" href="https://app.rightally.io/health-check">', false)
        ->assertSee('"@type":"FAQPage"', false)
        ->assertSee('Free Brokerage Health Check');
    // Only on the RightAlly host.
    $this->onTenantHost('acme');
    $this->get('http://acme.rightally.app/health-check')->assertNotFound();
});

it('scores the brokerage, compares savings honestly and emails a private link', function (): void {
    Http::fake([
        'https://oak.test/' => Http::response(healthSite(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://oak.test/robots.txt' => Http::response("User-agent: *\nAllow: /\nSitemap: https://oak.test/sitemap.xml", 200, ['Content-Type' => 'text/plain']),
        '*' => Http::response('', 404),
    ]);
    $token = submitHealth(['website_url' => 'oak.test', 'website_ok' => '1', 'contact_ok' => '1'], UploadedFile::fake()->image('logo.png', 200, 80));
    expect($token)->toHaveLength(40);

    $row = DB::connection(Connections::control())->table('health_checks')->sole();
    expect($row->token_hash)->toBe(hash('sha256', $token))->and((string) json_encode($row))->not->toContain($token);
    Notification::assertSentOnDemand(HealthReportMail::class, fn (HealthReportMail $m, array $channels, object $n): bool => $m->url === 'https://app.rightally.io/health-check/r/'.$token && $n->routes['mail'] === 'dana@oak.test');

    $savings = json_decode((string) $row->savings, true);
    // Follow Up Boss ($69 × 20) and dotloop are replaced; showings and "Ylopo" stay in the total.
    expect($savings['now_cents'])->toBe(228_000)->and($savings['kept_cents'])->toBe(60_000)
        ->and(array_column($savings['replaced'], 'name'))->toBe(['Follow Up Boss', 'dotloop'])
        ->and(array_column($savings['kept'], 'name'))->toBe(['ShowingTime or showing service', 'Ylopo'])
        ->and($savings['options'][0])->toMatchArray(['package' => 'Brokerage', 'agents_cents' => 40_000, 'after_cents' => 129_900, 'saves_cents' => 98_100])
        ->and($savings['best'])->toBe(0);

    $website = json_decode((string) $row->website, true);
    $ok = array_column($website['checks'], 'ok', 'key');
    expect($website['status'])->toBe('done')->and($ok)->toMatchArray(['https' => true, 'mobile' => true, 'schema' => true, 'search' => true, 'fair_housing' => true,
        'sitemap' => true, 'robots' => true, 'faq' => false, 'llms' => false, 'spanish' => false])
        ->and($row->brand_color)->toBe('#0e7490'); // no colour picked: the site's own colour is used

    $scores = json_decode((string) $row->scores, true);
    expect(array_column($scores['areas'], 'status', 'key'))->toMatchArray(['lead_reply' => 'urgent', 'file_check' => 'urgent', 'payout_days' => 'urgent', 'past_clients' => 'fix'])
        ->and(array_column($scores['areas'], 'score', 'key')['website'])->toBe($website['score']);

    $this->get(HC.'/health-check/r/'.$token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Save $981')->assertSee('Kept.')->assertSee('Add a short FAQ');
    expect(DB::connection(Connections::control())->table('health_checks')->value('viewed_at'))->not->toBeNull();
    $this->get(HC.'/health-check/r/'.str_repeat('x', 40))->assertNotFound();
});

it('says so when RightAlly would cost more, and keeps agent earnings out of the savings', function (): void {
    $token = submitHealth(['who_pays' => 'agents', 'tools' => [['key' => 'mls', 'cost' => '200']]]);
    $savings = json_decode((string) DB::connection(Connections::control())->table('health_checks')->value('savings'), true);
    expect($savings['options'][0])->toMatchArray(['agents_cents' => 0, 'saves_cents' => -29_900])
        ->and($savings['agent_plan'])->toEqual(['name' => 'Agent', 'list_cents' => 2_000, 'earn_cents' => 800]); // 20% of $15 + $5 markup
    $this->get(HC.'/health-check/r/'.$token)->assertSee('RightAlly would not lower your software bill')->assertSee('earns about $8 a month');
});

it('checks only public https sites the broker manages, and turns bots away', function (): void {
    Http::fake();
    $this->post(HC.'/health-check', healthForm(['website_url' => 'oak.test']))->assertSessionHasErrors('website_ok');
    $this->post(HC.'/health-check', healthForm(['fax' => '555']))->assertStatus(422);

    submitHealth(['website_url' => 'https://intranet.example.com', 'website_ok' => '1']);
    $website = json_decode((string) DB::connection(Connections::control())->table('health_checks')->value('website'), true);
    expect($website)->toMatchArray(['status' => 'failed', 'message' => 'That address points to a private network.']);
    Http::assertNothingSent();
});

it('follows redirects only to checked public addresses', function (): void {
    Http::fake([
        'https://oak.test/' => Http::response('', 301, ['Location' => 'https://intranet.example.com/']),
        '*' => Http::response('', 404),
    ]);
    submitHealth(['website_url' => 'oak.test', 'website_ok' => '1']);
    $website = json_decode((string) DB::connection(Connections::control())->table('health_checks')->value('website'), true);
    expect($website['status'])->toBe('failed');
    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), 'intranet'));
});

it('previews RightAlly in the broker\'s colours in English or Spanish, and the broker can delete the report', function (): void {
    $token = submitHealth(['brand_color' => '#0e7490'], UploadedFile::fake()->image('logo.png', 200, 80));
    $this->get(HC."/health-check/r/{$token}/preview")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('background:#0e7490', false)->assertSee('Oak Realty')->assertSee('Sample data')->assertSee('Talk to RightAlly');
    $this->get(HC."/health-check/r/{$token}/preview?lang=es")->assertSee('Hablar con RightAlly')->assertSee('Datos de ejemplo');
    $this->get(HC."/health-check/r/{$token}/logo")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $ulid = (string) DB::connection(Connections::control())->table('health_checks')->value('ulid');
    Storage::disk('local')->assertExists("health-checks/{$ulid}/logo.png");
    $this->post(HC."/health-check/r/{$token}/delete")->assertRedirect('/health-check');
    expect(DB::connection(Connections::control())->table('health_checks')->count())->toBe(0);
    Storage::disk('local')->assertMissing("health-checks/{$ulid}/logo.png");
    $this->get(HC.'/health-check/r/'.$token)->assertNotFound();
});

it('expires reports after 180 days', function (): void {
    $token = submitHealth();
    $this->travel(181)->days();
    $this->get(HC.'/health-check/r/'.$token)->assertNotFound();
    expect(app(HealthChecks::class)->prune())->toBe(1);
});

it('lists checks for HQ staff only, and records who opened a report', function (): void {
    submitHealth(['contact_ok' => '1']);
    $ulid = (string) DB::connection(Connections::control())->table('health_checks')->value('ulid');
    $this->actingAs($this->staff)->get(HC.'/hq/health-checks')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hq/health-checks')
        ->where('checks.0.brokerage', 'Oak Realty')->where('checks.0.contact_ok', true)->where('checks.0.saves_cents', 98_100));
    $this->actingAs($this->staff)->get(HC.'/hq/health-checks/'.$ulid)->assertOk()->assertDontSee('Delete my report');
    $this->actingAs($this->staff)->get(HC.'/hq/health-checks/'.$ulid.'/preview')->assertOk();

    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('http://acme.rightally.app/hq/health-checks')->assertNotFound();
    expect($tenant)->not->toBeNull();
});
