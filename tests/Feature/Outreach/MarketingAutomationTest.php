<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\Video\Bumpers;
use Modules\Ads\Studio\Video\FakeVideoMaker;
use Modules\Ads\Studio\Video\ServiceVideoMaker;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Sync\ListingSync;
use Modules\Outreach\MarketReportMail;
use Modules\Outreach\MarketReports;
use Modules\Sites\Funnels\HomeReports;
use Modules\Sites\Mail\HomeReportMail;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
| MK3 (C22, C23, D09): branded video intros and outros; Shotstack or Creatomate as the video renderer; "homes like
| yours that sold" and "since you bought" in the home report; and monthly market report emails by area.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    Mail::fake();
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Agent', 'phone' => '+15125550123']);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['phone' => '(512) 555-0100', 'area' => 'Austin'], $this->owner), true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information deemed reliable but not guaranteed.', 'sold_allowed' => true, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $home = fn (int $i, array $o = []): array => $o + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 25000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800 + $i * 20, 'StreetNumber' => (string) (100 + $i),
        'StreetName' => 'Elm St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75,
        'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'DaysOnMarket' => 10 + $i,
        'ModificationTimestamp' => sprintf('2026-05-20T00:%02d:00Z', $i)];
    $feed->records = [...array_map(fn (int $i): array => $home($i), range(1, 6)),
        ...array_map(fn (int $i): array => $home($i, ['StandardStatus' => 'Closed', 'ClosePrice' => 420000 + $i * 10000, 'CloseDate' => '2026-0'.(2 + $i % 3).'-15']), range(7, 10)),
        $home(11, ['StandardStatus' => 'Closed', 'ClosePrice' => 999000, 'CloseDate' => '2025-01-15'])]; // too old
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

it('adds homes like yours that sold, and how much the value moved since they bought, to the home report and dashboard', function (): void {
    $m = app(ManageContacts::class);
    $pam = $m->create(['first_name' => 'Pam', 'last_name' => 'Past', 'email' => 'pam@x.test'], $this->agent);
    $m->update($pam, ['lifecycle' => 'past_client', 'address_line1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704'], $this->agent);
    Deal::query()->create(['type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $this->agent->id, 'contact_id' => $pam->id, 'street' => '12 Oak St',
        'city' => 'Austin', 'state_code' => 'TX', 'postal_code' => '78704', 'price_cents' => 35_000_000, 'closed_on' => '2021-06-15', 'created_by' => $this->agent->id]);

    $report = app(HomeReports::class)->start($pam, null, '12 Oak St', '78704', 3, 1850, null, $this->agent->id);
    expect($report->bought_price)->toBe(350000)->and($report->bought_on->toDateString())->toBe('2021-06-15');
    $report->forceFill(['next_on' => '2026-06-01'])->save();
    expect(app(HomeReports::class)->sendDue())->toBe(1);
    Mail::assertSent(HomeReportMail::class, function (HomeReportMail $mail): bool {
        $html = $mail->render();

        return count($mail->sold) === 3 && $mail->soldRange['count'] === 4 && $mail->bought['year'] === '2021' && $mail->bought['change'] > 0
            && str_contains($html, 'Homes like yours that sold recently') && str_contains($html, 'Sold for $') && str_contains($html, 'since you bought in 2021 for $350,000')
            && ! str_contains($html, '999,000');
    });

    $url = URL::signedRoute('sites.home-reports.dashboard', ['report' => $report->ulid], null, false);
    expect((string) $this->get('http://acme.rightally.app'.$url)->assertOk()->getContent())->toContain('Homes like yours that sold')->toContain('since you bought in 2021');

    // When the MLS doesn't allow sold data on display, the sales are not shown.
    RulesProfile::query()->update(['sold_allowed' => false]);
    expect((string) $this->get('http://acme.rightally.app'.$url)->getContent())->not->toContain('Sold for $');
});

it('sends monthly market report emails by area to the chosen people, never after an opt-out, with a one-click stop', function (): void {
    $m = app(ManageContacts::class);
    $pam = $m->create(['first_name' => 'Pam', 'last_name' => 'Past', 'email' => 'pam@x.test'], $this->agent);
    $m->update($pam, ['lifecycle' => 'past_client'], $this->agent);
    $sue = $m->create(['first_name' => 'Sue', 'last_name' => 'Sphere', 'email' => 'sue@x.test'], $this->agent);
    $m->update($sue, ['lifecycle' => 'sphere'], $this->agent);
    $out = $m->create(['first_name' => 'Ola', 'last_name' => 'Out', 'email' => 'ola@x.test'], $this->agent);
    $m->update($out, ['lifecycle' => 'sphere'], $this->agent);
    app(Consent::class)->setEmail($out, false, 'agent');
    $m->create(['first_name' => 'Lee', 'last_name' => 'Lead', 'email' => 'lee@x.test'], $this->agent);

    assertPageContract($this->actingAs($this->agent)->get('/outreach/market-reports'), 'outreach/market-reports');
    $this->actingAs($this->agent)->post('/outreach/market-reports', ['place' => '78704', 'audience' => MarketReports::PAST_AND_SPHERE])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('outreach_market_reports')->count())->toBe(2); // not the opted-out one, not the lead
    $this->actingAs($this->agent)->post('/outreach/market-reports', ['place' => 'nowhere-tx', 'audience' => MarketReports::PAST_AND_SPHERE])->assertSessionHasErrors('place');

    $this->travel(1)->days();
    expect(app(MarketReports::class)->sendDue())->toBe(2)->and(app(MarketReports::class)->sendDue())->toBe(0); // monthly
    Mail::assertSent(MarketReportMail::class, function (MarketReportMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('pam@x.test') && (int) $mail->stats['active'] === 6 && str_contains($html, 'Homes for sale') && str_contains($html, 'Source: MLS listings')
            && str_contains($html, 'Equal Housing Opportunity') && $mail->homes !== [] && str_contains($mail->stopUrl, '/market-reports/');
    });
    $this->actingAs($this->agent)->get('/outreach/market-reports')->assertInertia(fn (Assert $p) => $p->where('reports.0.people', 2)->where('reports.0.sent', 2));

    $ulid = (string) DB::connection(Connections::cell())->table('outreach_market_reports')->where('contact_id', $pam->id)->value('ulid');
    auth()->guard('web')->logout();
    $stop = URL::signedRoute('outreach.market-reports.stop', ['report' => $ulid], null, false);
    $this->get('http://acme.rightally.app'.$stop)->assertOk()->assertSee('Stop the monthly market report?');
    $this->post('http://acme.rightally.app'.$stop)->assertOk()->assertSee('You will not get more market reports');
    $this->post('http://acme.rightally.app/market-reports/'.$ulid.'/stop')->assertForbidden(); // unsigned
    expect(DB::connection(Connections::cell())->table('outreach_market_reports')->where('contact_id', $pam->id)->value('unsubscribed_at'))->not->toBeNull();
});

it('starts and ends videos with the agent\'s brand when they ask for it', function (): void {
    $renderer = new FakeRenderer;
    app()->instance(StudioRenderer::class, $renderer);
    $dir = sys_get_temp_dir().'/mk3-'.uniqid();
    mkdir($dir);
    $kit = ['details' => ['display_name' => 'Pat Agent', 'phone' => '(512) 555-0123', 'email' => 'pat@x.test', 'licence_line' => 'TX #123456', 'disclaimer' => 'Equal Housing Opportunity',
        'video_bumpers' => ''], 'colors' => ['primary' => '#1F4D3A', 'paper' => '#FFFFFF'], 'fonts' => ['heading' => 'Playfair Display', 'body' => 'Inter'], 'logo' => null,
        'brokerage' => 'Acme Realty'];
    expect(Bumpers::wrap($kit, ['a.png'], [3.0], $dir, 1080, 1920))->toBe([['a.png'], [3.0], false]);

    $kit['details']['video_bumpers'] = 'yes';
    [$frames, $seconds, $added] = Bumpers::wrap($kit, ['a.png', 'b.png'], [3.0, 3.0], $dir, 1920, 1080);
    expect($added)->toBeTrue()->and($frames)->toHaveCount(4)->and($seconds)->toBe([Bumpers::INTRO, 3.0, 3.0, Bumpers::OUTRO])
        ->and($renderer->pages[0])->toContain('Pat Agent')->toContain('Acme Realty')->toContain('#1F4D3A')
        ->and($renderer->pages[1])->toContain('(512) 555-0123')->toContain('TX #123456')->toContain('Equal Housing Opportunity');
});

it('renders videos with Shotstack or Creatomate when HQ chooses one, and falls back to our own renderer', function (): void {
    $own = new FakeVideoMaker;
    $maker = new ServiceVideoMaker($own);
    $dir = sys_get_temp_dir().'/mk3v-'.uniqid();
    mkdir($dir);
    file_put_contents($f1 = $dir.'/1.png', 'png-1');
    file_put_contents($f2 = $dir.'/2.png', 'png-2');

    // Off: our own renderer.
    $maker->make([$f1, $f2], [3.0, 3.0], null, $dir.'/a.mp4');
    expect($own->made)->toHaveCount(1);

    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'video', 'provider' => 'shotstack', 'mode' => 'test',
        'test_keys' => Crypt::encryptString('{"api_key":"ss-key"}'), 'live_keys' => null, 'test_contacts' => '', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake([
        'api.shotstack.io/edit/stage/render' => Http::response(['response' => ['id' => 'r-1']], 201),
        'api.shotstack.io/edit/stage/render/r-1' => Http::sequence()->push(['response' => ['status' => 'rendering']])->push(['response' => ['status' => 'done', 'url' => 'https://cdn.shotstack.example/r-1.mp4']]),
        'cdn.shotstack.example/*' => Http::response('MP4-FROM-SHOTSTACK'),
    ]);
    $maker->make([$f1, $f2], [3.0, 4.0], null, $dir.'/b.mp4', 1080, 1920);
    expect((string) file_get_contents($dir.'/b.mp4'))->toBe('MP4-FROM-SHOTSTACK')->and($own->made)->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBe([]); // the frames were removed afterwards
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.shotstack.io/edit/stage/render' && $r->hasHeader('x-api-key', 'ss-key')
        && count($r['timeline']['tracks'][0]['clips']) === 2 && $r['timeline']['tracks'][0]['clips'][1]['start'] === 2.4 && $r['output']['size'] === ['width' => 1080, 'height' => 1920]);

    DB::connection(Connections::control())->table('outside_services')->update(['provider' => 'creatomate']);
    $down = false;
    Http::fake(function (HttpRequest $r) use (&$down) {
        return match (true) {
            $down => Http::response([], 500),
            $r->url() === 'https://api.creatomate.com/v1/renders' => Http::response([['id' => 'c-1', 'status' => 'planned']], 202),
            str_contains($r->url(), 'api.creatomate.com/v1/renders/c-1') => Http::response(['status' => 'succeeded', 'url' => 'https://cdn.creatomate.example/c-1.mp4']),
            default => Http::response('MP4-FROM-CREATOMATE'),
        };
    });
    $maker->make([$f1, $f2], [3.0, 3.0], null, $dir.'/c.mp4');
    expect((string) file_get_contents($dir.'/c.mp4'))->toBe('MP4-FROM-CREATOMATE');

    $down = true;
    $maker->make([$f1, $f2], [3.0, 3.0], null, $dir.'/d.mp4');
    expect($own->made)->toHaveCount(2); // the provider failed: our own renderer made it
});
