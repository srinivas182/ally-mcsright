<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Models\Campaign;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Funnels\HomeReports;
use Modules\Sites\Mail\HomeReportMail;
use Modules\Sites\Models\HomeReport;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\SiteSetup;

/* Sprint LG1b: the monthly home-value report for homeowners who asked a seller funnel, and promoting a funnel with
   an ad (own Meta account or RightAlly Boost) that links to the funnel's page and reaches its area. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'H'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 300000 + $i * 20000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1600 + $i * 20, 'StreetNumber' => (string) (100 + $i),
        'StreetName' => 'Elm St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.20 + $i / 100, 'Longitude' => -97.70,
        'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-15', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 5));
    app(ListingSync::class)->run($source);
    Listing::query()->update(['cover_path' => 'listings/h/cover.jpg']);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

function askHomeValue(object $t): SiteFunnel
{
    $t->actingAs($t->owner)->post('/funnels', ['kind' => 'home_value', 'area' => 'Austin, TX']);
    $f = SiteFunnel::query()->sole();
    auth()->logout();
    $t->post($t->web.'/_funnel/'.$f->slug, ['name' => 'Sam Seller', 'email' => 'sam@x.test', 'address' => '12 Oak Ln', 'zip' => '78704', 'beds' => 3, 'sqft' => 1650,
        't' => Crypt::encryptString((string) (time() - 30))])->assertStatus(303);

    return $f;
}

it('sends the homeowner an updated range each month, with how it moved, until they stop it', function (): void {
    askHomeValue($this);
    $report = HomeReport::query()->sole();
    expect($report->only(['address', 'zip', 'beds', 'sqft']))->toBe(['address' => '12 Oak Ln', 'zip' => '78704', 'beds' => 3, 'sqft' => 1650])
        ->and($report->next_on->toDateString())->toBe('2026-07-01')->and($report->last_mid)->toBeGreaterThan(0);
    $this->get($this->web.'/go/'.SiteFunnel::query()->sole()->slug)->assertSee('updated range once a month');

    expect(app(HomeReports::class)->sendDue())->toBe(0); // not due yet
    $first = $report->last_mid;
    Listing::query()->update(['list_price_cents' => DB::raw('list_price_cents + 2000000')]); // asking prices up $20,000
    $this->travelTo(CarbonImmutable::parse('2026-07-01 14:00', 'UTC')); // 9am in Chicago
    expect(app(HomeReports::class)->sendDue())->toBe(1);
    Mail::assertSent(HomeReportMail::class, function (HomeReportMail $m) use ($first): bool {
        $html = $m->render();

        return $m->hasTo('sam@x.test') && $m->firstName === 'Sam' && $m->estimate !== null && $m->change !== null && $m->change > 0
            && $m->estimate['mid'] > $first && count($m->nearby) === 3 && str_contains($html, 'Likely worth today') && str_contains($html, 'Up '.$m->change.'%')
            && str_contains($html, 'Stop these emails') && str_contains($html, 'Equal Housing Opportunity');
    });
    expect($report->fresh()?->next_on->toDateString())->toBe('2026-08-01')->and(app(HomeReports::class)->sendDue())->toBe(0); // once a month

    $stop = app(HomeReports::class)->stopUrl($report);
    $this->post($stop)->assertOk()->assertSee('You\'re unsubscribed', false);
    $this->travelTo(CarbonImmutable::parse('2026-08-01 14:00', 'UTC'));
    expect(app(HomeReports::class)->sendDue())->toBe(0)->and(HomeReport::query()->sole()->unsubscribed_at)->not->toBeNull();
    $this->post(URL::signedRoute('sites.home-reports.stop', ['report' => $report->ulid], null, false).'x')->assertForbidden();
});

it('never sends after an email opt-out', function (): void {
    askHomeValue($this);
    ConsentEvent::query()->create(['contact_id' => Contact::query()->sole()->id, 'channel' => 'email', 'action' => 'opt_out', 'method' => 'test', 'occurred_at' => now()]);
    $this->travelTo(CarbonImmutable::parse('2026-07-01 14:00', 'UTC'));
    expect(app(HomeReports::class)->sendDue())->toBe(0);
    Mail::assertNotSent(HomeReportMail::class);
});

it('promotes a funnel with a Boost ad that links to its page and reaches its area', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost'), true,
        ['ad_account_id' => '999', 'page_id' => '888', 'access_token' => 'ra-system-token', 'margin_percent' => '15'], null, User::factory()->make(['id' => 999999]));
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->add(null, 30_000, 'adjust', 'Test credits', $this->owner);
    Http::fake([
        'graph.facebook.com/v21.0/act_999/campaigns' => Http::response(['id' => 'c1']), 'graph.facebook.com/v21.0/act_999/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/act_999/adcreatives' => Http::response(['id' => 'cr1']), 'graph.facebook.com/v21.0/act_999/ads' => Http::response(['id' => 'a1']),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
    ]);
    $this->actingAs($this->owner)->post('/funnels', ['kind' => 'new_listings', 'area' => '78704']);
    $f = SiteFunnel::query()->sole();

    $this->actingAs($this->owner)->get('/ads/new?funnel='.$f->ulid)->assertInertia(fn (Assert $p) => $p->component('ads/new')
        ->where('funnel.ulid', $f->ulid)->where('funnel.headline', 'New homes for sale in 78704')->where('funnel.error', null)->where('funnels.0.ulid', $f->ulid));
    $this->actingAs($this->ann)->post('/ads', ['pay' => 'boost', 'funnel' => $f->ulid, 'account' => '999', 'page' => '888'])->assertSessionHasErrors('funnel'); // not hers

    $this->actingAs($this->owner)->post('/ads', ['pay' => 'boost', 'funnel' => $f->ulid, 'account' => '999', 'page' => '888', 'headline' => 'New homes for sale in 78704',
        'body' => 'See today\'s homes for sale and get new ones the day they list.', 'daily_budget' => 10, 'days' => 5, 'radius' => 20, 'agree' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect('/ads');
    $c = Campaign::query()->sole();
    expect($c->listing_ulid)->toBeNull()->and($c->site_funnel_id)->toBe($f->id)->and($c->listing_label)->toBe('Funnel: New homes for sale in 78704')
        ->and($c->link)->toStartWith('https://')->toContain('/go/'.$f->slug.'?utm_source=facebook&utm_medium=paid_social&utm_campaign=rightally-')
        ->and((float) $c->latitude)->toEqualWithDelta(30.23, 0.001)->and($c->status)->toBe('active');
    $this->actingAs($this->owner)->get('/funnels')->assertInertia(fn (Assert $p) => $p->where('funnels.0.status', 'live')->where('funnels.0.url', fn (?string $u) => $u !== null)); // the page offers "Promote with an ad"
});
