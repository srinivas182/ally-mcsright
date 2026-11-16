<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\StudioMonthlyReport;
use Modules\Ads\Studio\StudioResults;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Lead;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;

/* Sprint SA4: Ally Studio results — each post's numbers read daily from Facebook and Instagram, leads from post links,
   a month by format, style and time of day, the team view, what Autopilot learns, and the monthly report. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    Notification::fake();
    Storage::fake('public');
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret', 'listings.cdn_url' => 'https://cdn.acme.test']);
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['timezone' => 'America/Chicago'])->save();
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Agent']);
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'kind' => 'mls',
        'state_code' => null, 'number' => 'AGT1', 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = [['ListingKey' => 'A1', 'ListingId' => 'MLS1', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 501000, 'BedroomsTotal' => 3,
        'StreetNumber' => '201', 'StreetName' => 'Pine Rd', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75,
        'ListAgentMlsId' => 'AGT1', 'ListOfficeName' => 'Acme Realty', 'ModificationTimestamp' => '2026-05-20T00:01:00Z']];
    app(ListingSync::class)->run($source);
    Listing::query()->update(['cover_path' => 'listings/a1/cover']);
    $img = imagecreatetruecolor(16, 10);
    ob_start();
    imagewebp($img);
    Storage::disk('public')->put('listings/a1/cover-1600.webp', (string) ob_get_clean());
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->home = Listing::query()->sole();

    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true, ['client_id' => '123', 'client_secret' => 'app-secret', 'verify_token' => 'vt'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_pages'), true, [], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('google_business'), true, [], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('youtube'), true, [], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('linkedin'), true, ['client_id' => 'li-id', 'client_secret' => 'li-secret'], null, $hq);

    Http::fake([
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::response(['access_token' => 'long-user', 'expires_in' => 5184000]),
        'graph.facebook.com/v21.0/me?*' => Http::response(['name' => 'Olive', 'email' => 'olive@fb.test']),
        'graph.facebook.com/v21.0/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'Acme Realty', 'access_token' => 'page-111',
            'instagram_business_account' => ['id' => '17841', 'username' => 'acmerealty']]]]),
        'graph.facebook.com/v21.0/111/feed' => Http::response(['id' => '111_555']),
        'graph.facebook.com/v21.0/17841/media' => Http::response(['id' => 'cont-1']),
        'graph.facebook.com/v21.0/17841/media_publish' => Http::response(['id' => 'ig-9']),
        'graph.facebook.com/v21.0/ig-9*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-at', 'refresh_token' => 'g-rt', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'olive@gmail.test']),
        'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [['name' => 'accounts/42', 'accountName' => 'Acme']]]),
        'mybusinessbusinessinformation.googleapis.com/v1/accounts/42/locations*' => Http::response(['locations' => [['name' => 'locations/7', 'title' => 'Acme Realty Austin']]]),
        'mybusiness.googleapis.com/v4/accounts/42/locations/7/localPosts' => Http::response(['name' => 'accounts/42/locations/7/localPosts/p1', 'searchUrl' => 'https://local.google.com/place?p1']),
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'li-at', 'expires_in' => 5184000]),
        'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'li-jane', 'name' => 'Jane Agent']),
        'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:77']),
    ]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->add(null, 100_000, 'adjust', 'Test credits', $this->owner);
    $app = app(AppCatalog::class)->find('studio_brokerage');
    app(AppRegistry::class)->save($app, true, [], null, $hq);
    app(AppRegistry::class)->savePrice($app, 29900, [], $hq);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_brokerage/addon')->assertSessionHasNoErrors();
});

function resultsConnect(User $as, string $app): Connection
{
    $start = test()->actingAs($as)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', $app)->latest('id')->firstOrFail();
}

/** A posted post with one place and its numbers (as the daily read leaves them). */
function postedWith(User $by, array $post, array $metrics, string $channel = 'instagram', int $connection = 1): SocialPost
{
    $p = SocialPost::query()->create(['user_id' => $by->id, 'text' => $post['text'] ?? 'A post', 'status' => 'posted', 'post_at' => $post['at'] ?? now(),
        'image_url' => 'https://cdn.acme.test/x.jpg'] + $post);
    $p->forceFill(['posted_at' => $post['at'] ?? now()])->save();
    $t = SocialTarget::query()->create(['post_id' => $p->id, 'connection_id' => $connection, 'channel' => $channel, 'target_id' => '17841', 'name' => '@acmerealty',
        'status' => 'posted']);
    $t->forceFill(['external_id' => 'ext-'.$p->id, 'url' => 'https://www.instagram.com/p/'.$p->id, 'done_at' => $post['at'] ?? now()])->save();
    DB::connection(Connections::cell())->table('ads_social_metrics')->insert($metrics + ['tenant_id' => test()->tenant->id, 'target_id' => $t->id, 'fetched_at' => now(),
        'created_at' => now(), 'updated_at' => now()]);

    return $p;
}

it('reads each recent post\'s numbers from Facebook and Instagram once a day', function (): void {
    Http::fake([
        'graph.facebook.com/v21.0/111_555?*' => Http::response(['reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2]]),
        'graph.facebook.com/v21.0/111_555/insights*' => Http::response(['data' => [['name' => 'post_total_media_view_unique', 'values' => [['value' => 840]]],
            ['name' => 'post_clicks', 'values' => [['value' => 31]]]]]),
        'graph.facebook.com/v21.0/ig-77/insights*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 1200]]], ['name' => 'likes', 'values' => [['value' => 90]]],
            ['name' => 'saved', 'values' => [['value' => 14]]], ['name' => 'shares', 'values' => [['value' => 6]]]]]),
    ]);
    resultsConnect($this->owner, 'facebook_pages');
    $places = collect(app(SocialPosts::class)->places($this->owner))->pluck('key')->all();
    $this->actingAs($this->owner)->post('/social', ['listing' => $this->home->ulid, 'text' => 'Bright 3 bed on Pine Rd.', 'places' => $places])->assertSessionHasNoErrors();
    SocialTarget::query()->where('channel', 'instagram')->update(['external_id' => 'ig-77']);
    $this->travel(1)->days();
    $this->artisan('ads:social-results')->assertSuccessful();
    $rows = DB::connection(Connections::cell())->table('ads_social_metrics')->orderBy('target_id')->get();
    expect($rows)->toHaveCount(2)
        ->and((array) $rows[0])->toMatchArray(['reach' => 840, 'likes' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 31])
        ->and((array) $rows[1])->toMatchArray(['reach' => 1200, 'likes' => 90, 'saves' => 14, 'shares' => 6]);
    $calls = count(Http::recorded());
    $this->artisan('ads:social-results')->assertSuccessful();
    expect(count(Http::recorded()))->toBe($calls); // read at most once a day
});

it('shows a month by format, style and time of day with leads from post links, and the team view for the brokerage', function (): void {
    $design = Design::query()->create(['user_id' => $this->owner->id, 'batch' => 'b1', 'moment' => 'just_listed', 'collection' => 'noir', 'variant' => 'noir',
        'content' => ['headline' => '201 Pine Rd'], 'status' => 'ready']);
    $a = postedWith($this->owner, ['format' => 'carousel', 'studio_design_id' => $design->id, 'at' => CarbonImmutable::parse('2026-06-02 14:30', 'UTC')],
        ['reach' => 1000, 'likes' => 80, 'comments' => 10, 'shares' => 5, 'saves' => 5, 'clicks' => 20]);
    postedWith($this->owner, ['format' => 'photo', 'at' => CarbonImmutable::parse('2026-06-03 23:00', 'UTC')], ['reach' => 500, 'likes' => 10, 'clicks' => 2]);
    postedWith($this->jane, ['format' => 'reel', 'at' => CarbonImmutable::parse('2026-06-04 14:30', 'UTC')], ['reach' => 3000, 'likes' => 150, 'shares' => 30]);
    Lead::query()->create(['contact_id' => null, 'source' => 'website', 'channel' => 'social', 'utm' => ['utm_campaign' => 'rightally-'.strtolower($a->ulid)]]);

    $r = app(StudioResults::class)->month($this->owner, '2026-06');
    expect($r['totals'])->toBe(['posts' => 2, 'reach' => 1500, 'interactions' => 110, 'clicks' => 22, 'leads' => 0, 'rate' => 7.3])
        ->and(collect($r['formats'])->pluck('key')->all())->toBe(['carousel', 'photo'])
        ->and(collect($r['styles'])->firstWhere('key', 'Noir')['posts'])->toBe(1)
        ->and(collect($r['times'])->pluck('key')->sort()->values()->all())->toBe(['Evening', 'Morning'])
        ->and($r['top'][0]['ulid'])->toBe($a->ulid);

    $this->actingAs($this->owner)->get('/studio/results?month=2026-06&team=1')->assertInertia(fn (Assert $p) => $p->component('studio/results')
        ->where('ready', true)->where('can_team', true)->where('team', true)->where('report.totals.posts', 3)->has('agents', 2));
    $this->actingAs($this->owner)->get('/studio/results?month=2026-06')->assertInertia(fn (Assert $p) => $p->where('team', false)->where('report.totals.posts', 2));
});

it('learns the style and the time that do best once enough posts have numbers, and sends the monthly report', function (): void {
    $styles = ['noir' => 8, 'gallery' => 2];
    foreach (range(1, 8) as $i) {
        $style = $i % 2 === 0 ? 'noir' : 'gallery';
        $d = Design::query()->create(['user_id' => $this->owner->id, 'batch' => 'b'.$i, 'moment' => 'just_listed', 'collection' => $style, 'variant' => $style,
            'content' => [], 'status' => 'ready']);
        $at = CarbonImmutable::parse('2026-05-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT).' '.($style === 'noir' ? '23:00' : '14:30'), 'UTC');
        postedWith($this->owner, ['format' => 'carousel', 'studio_design_id' => $d->id, 'at' => $at], ['reach' => 1000, 'likes' => $styles[$style] * 10]);
    }
    expect(app(StudioResults::class)->learned($this->owner))->toBe(['style' => 'noir', 'times' => ['18:00', '09:30'], 'measured' => 8]);
    expect(app(StudioResults::class)->learned($this->jane)['style'])->toBeNull(); // not enough of her own posts

    $this->artisan('ads:studio-reports')->assertSuccessful();
    Notification::assertSentTo($this->owner, StudioMonthlyReport::class, fn (StudioMonthlyReport $n): bool => $n->report['month'] === '2026-05' && $n->report['totals']['posts'] === 8
        && str_contains((string) $n->toMail($this->owner)->actionUrl, '/studio/results?month=2026-05'));
    Notification::assertNotSentTo($this->jane, StudioMonthlyReport::class);
});
