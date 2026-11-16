<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Social\LinkedIn;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialPosts;
use Modules\Ads\Social\SocialTarget;
use Modules\AI\Assistant\YourAiAssistant;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/* Sprint C7d-1: social posting — write once, post now or scheduled to Facebook Pages, Instagram, LinkedIn and Google
   Business Profile; once per place, link tags per network, Fair Housing checked, name and brokerage added, only
   your own listings (with MLS attribution), agents never on the brokerage's Pages; and through Your AI Assistant. */

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
});

function socialConnect(User $as, string $app): Connection
{
    $start = test()->actingAs($as)->post('/apps/'.$app.'/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    $home = (string) test()->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    test()->actingAs($as)->get($home)->assertRedirect();

    return Connection::query()->where('app_key', $app)->latest('id')->firstOrFail();
}

it('posts a listing now to the Page, Instagram and Google, once each, tagged per network, with the brokerage and MLS attribution', function (): void {
    $fb = socialConnect($this->owner, 'facebook_pages');
    expect($fb->settings['pages'])->toBe([['id' => '111', 'name' => 'Acme Realty']])->and($fb->settings['instagram'])->toBe([['id' => '17841', 'name' => '@acmerealty', 'page' => '111']])
        ->and(json_decode($fb->credentials['pages'], true))->toBe(['111' => 'page-111'])->and((string) DB::table('connect_connections')->where('id', $fb->id)->value('credentials'))->not->toContain('page-111');
    socialConnect($this->owner, 'google_business');

    $page = $this->actingAs($this->owner)->get('/social?listing='.$this->home->ulid)->assertOk();
    $page->assertInertia(fn (Assert $p) => $p->component('ads/social')->has('places', 3)->where('start.listing', $this->home->ulid)
        ->where('start.text', fn ($t): bool => str_starts_with((string) $t, 'Just listed: 201 Pine Rd'))->where('start.image', fn ($u): bool => str_ends_with((string) $u, '.jpg')));
    $places = collect(app(SocialPosts::class)->places($this->owner))->pluck('key')->all();

    $this->actingAs($this->owner)->post('/social', ['listing' => $this->home->ulid, 'text' => 'Bright 3 bed on Pine Rd with a big yard.', 'places' => $places])->assertSessionHasNoErrors();
    $post = SocialPost::query()->sole();
    expect($post->status)->toBe('posted')
        ->and($post->text)->toBe("Bright 3 bed on Pine Rd with a big yard.\n\nOlive Owner, {$this->tenant->name}\nListing courtesy of Acme Realty")
        ->and(SocialTarget::query()->orderBy('id')->get(['channel', 'status', 'url'])->toArray())->toBe([
            ['channel' => 'facebook', 'status' => 'posted', 'url' => 'https://www.facebook.com/111_555'],
            ['channel' => 'instagram', 'status' => 'posted', 'url' => 'https://www.instagram.com/p/abc/'],
            ['channel' => 'google', 'status' => 'posted', 'url' => 'https://local.google.com/place?p1'],
        ]);
    $tag = 'utm_medium=social&utm_campaign=rightally-'.strtolower($post->ulid);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/111/feed' && $r['access_token'] === 'page-111'
        && str_contains((string) $r['link'], 'utm_source=facebook&'.$tag) && str_starts_with((string) $r['message'], 'Bright 3 bed'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media' && str_ends_with((string) $r['image_url'], '.jpg')
        && str_contains((string) $r['caption'], 'utm_source=instagram&'.$tag));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media_publish' && $r['creation_id'] === 'cont-1');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'locations/7/localPosts') && $r->hasHeader('Authorization', 'Bearer g-at')
        && str_contains((string) $r['callToAction']['url'], 'utm_source=google&'.$tag) && $r['media'][0]['mediaFormat'] === 'PHOTO');
    Storage::disk('public')->assertExists('social/'.sha1('listings/a1/cover').'.jpg');

    $sent = count(Http::recorded());
    expect(app(SocialPosts::class)->runDue())->toBe(0)->and(count(Http::recorded()))->toBe($sent); // once
});

it('keeps agents to their own accounts and listings, checks Fair Housing and Instagram photos, schedules and cancels', function (): void {
    socialConnect($this->owner, 'facebook_pages');
    $li = socialConnect($this->jane, 'linkedin');
    expect($li->owner_user_id)->toBe($this->jane->id)->and($li->settings['person'])->toBe(['id' => 'li-jane', 'name' => 'Jane Agent']);

    $janes = collect(app(SocialPosts::class)->places($this->jane));
    expect($janes->pluck('channel')->all())->toBe(['linkedin']); // not the brokerage's Page
    $brokeragePage = collect(app(SocialPosts::class)->places($this->owner))->firstWhere('channel', 'facebook')['key'];
    $this->actingAs($this->jane)->post('/social', ['text' => 'Open house Sunday', 'places' => [$brokeragePage]])->assertSessionHasErrors(['places' => 'Choose where to post.']);
    $this->actingAs($this->jane)->post('/social', ['text' => 'Perfect for families! Open house Sunday', 'places' => $janes->pluck('key')->all()])->assertSessionHasErrors('text');
    $ig = collect(app(SocialPosts::class)->places($this->owner))->firstWhere('channel', 'instagram')['key'];
    $this->actingAs($this->owner)->post('/social', ['text' => 'Market update for June', 'places' => [$ig]])->assertSessionHasErrors('image_url');
    $other = Listing::query()->create(['mls_source_id' => $this->home->mls_source_id, 'listing_key' => 'B2', 'standard_status' => 'Active', 'list_agent_mls_id' => 'ZZZ']);
    $this->actingAs($this->jane)->post('/social', ['listing' => $other->ulid, 'text' => 'Look', 'places' => $janes->pluck('key')->all()])->assertSessionHasErrors('listing');

    $in = ['text' => 'Open house Sunday 1-3 (bring a friend) #austin', 'link' => 'https://acme.test/open', 'places' => $janes->pluck('key')->all(), 'post_at' => '2026-06-02T09:00'];
    $this->actingAs($this->jane)->post('/social', $in)->assertSessionHasNoErrors();
    $this->actingAs($this->jane)->post('/social', $in)->assertSessionHasNoErrors();
    [$first, $second] = SocialPost::query()->orderBy('id')->get()->all();
    expect($first->post_at->toIso8601String())->toBe('2026-06-02T14:00:00+00:00') // 9am in Austin
        ->and($first->status)->toBe('scheduled');
    $this->actingAs($this->owner)->post('/social/'.$first->ulid.'/cancel')->assertNotFound(); // only the author
    $this->actingAs($this->jane)->post('/social/'.$first->ulid.'/cancel')->assertSessionHasNoErrors();
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'rest/posts'));

    $this->travelTo(CarbonImmutable::parse('2026-06-02 14:00:30', 'UTC'));
    $this->artisan('ads:social-posts')->assertSuccessful();
    expect($first->fresh()?->status)->toBe('cancelled')->and($second->fresh()?->status)->toBe('posted')
        ->and(SocialTarget::query()->where('post_id', $second->id)->sole()->url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:77');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.linkedin.com/rest/posts' && $r->hasHeader('LinkedIn-Version', LinkedIn::VERSION)
        && $r['author'] === 'urn:li:person:li-jane' && str_starts_with((string) $r['commentary'], 'Open house Sunday 1-3 \(bring a friend\) \#austin')
        && str_contains((string) $r['content']['article']['source'], 'utm_source=linkedin'));
    $this->actingAs($this->jane)->post('/social/'.$second->ulid.'/cancel')->assertSessionHasErrors('post');
});

it('posts a carousel: a Facebook multi-photo post, an Instagram carousel and a LinkedIn multi-image post, in order', function (): void {
    Http::fake([
        'graph.facebook.com/v21.0/111/photos' => Http::sequence()->push(['id' => 'ph-1'])->push(['id' => 'ph-2'])->push(['id' => 'ph-3']),
        'api.linkedin.com/rest/images*' => Http::sequence()->push(['value' => ['uploadUrl' => 'https://li-upload.test/1', 'image' => 'urn:li:image:A']])
            ->push(['value' => ['uploadUrl' => 'https://li-upload.test/2', 'image' => 'urn:li:image:B']])->push(['value' => ['uploadUrl' => 'https://li-upload.test/3', 'image' => 'urn:li:image:C']]),
        'li-upload.test/*' => Http::response('', 201),
        'cdn.acme.test/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    socialConnect($this->owner, 'facebook_pages');
    socialConnect($this->owner, 'linkedin');
    $places = collect(app(SocialPosts::class)->places($this->owner))->whereIn('channel', ['facebook', 'instagram', 'linkedin'])->pluck('key')->all();
    $images = ['https://cdn.acme.test/s/01.jpg', 'https://cdn.acme.test/s/02.jpg', 'https://cdn.acme.test/s/03.jpg'];
    $this->actingAs($this->owner)->post('/social', ['text' => 'Just listed: 201 Pine Rd', 'link' => 'https://acme.test/201-pine', 'images' => [...$images, 'http://bad.test/x.png'],
        'places' => $places])->assertSessionHasErrors('images');
    $this->actingAs($this->owner)->post('/social', ['text' => 'Just listed: 201 Pine Rd', 'link' => 'https://acme.test/201-pine', 'images' => $images, 'places' => $places])
        ->assertSessionHasNoErrors();
    $post = SocialPost::query()->sole();
    expect($post->images)->toBe($images)->and($post->image_url)->toBe($images[0])->and($post->status)->toBe('posted')
        ->and(SocialTarget::query()->pluck('status')->unique()->all())->toBe(['posted']);

    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/111/photos' && ($r->data()['url'] ?? null) === $images[0] && ($r->data()['published'] ?? null) === 'false');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/111/feed' && ($r->data()['attached_media[0]'] ?? null) === '{"media_fbid":"ph-1"}'
        && ($r->data()['attached_media[2]'] ?? null) === '{"media_fbid":"ph-3"}' && str_contains((string) ($r->data()['message'] ?? null), 'https://acme.test/201-pine?utm_source=facebook'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media' && ($r->data()['is_carousel_item'] ?? null) === 'true' && ($r->data()['image_url'] ?? null) === $images[1]);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media' && ($r->data()['media_type'] ?? null) === 'CAROUSEL'
        && ($r->data()['children'] ?? null) === 'cont-1,cont-1,cont-1' && str_starts_with((string) ($r->data()['caption'] ?? null), 'Just listed'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload'
        && ($r->data()['initializeUploadRequest']['owner'] ?? null) === 'urn:li:person:li-jane');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://li-upload.test/2' && $r->method() === 'PUT' && $r->body() === 'JPEGDATA');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.linkedin.com/rest/posts'
        && array_column((array) ($r->data()['content']['multiImage']['images'] ?? []), 'id') === ['urn:li:image:A', 'urn:li:image:B', 'urn:li:image:C']
        && str_contains((string) ($r->data()['commentary'] ?? null), 'utm\\_source=linkedin')); // escaped for LinkedIn's little text
});

it('posts a Reel: an Instagram Reel once processed, a Facebook Page video, and the cover elsewhere', function (): void {
    Http::fake([
        'graph.facebook.com/v21.0/111/videos' => Http::response(['id' => 'vid-7']),
        'graph.facebook.com/v21.0/cont-1?*' => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
    ]);
    Sleep::fake();
    socialConnect($this->owner, 'facebook_pages');
    socialConnect($this->owner, 'google_business');
    $places = collect(app(SocialPosts::class)->places($this->owner))->pluck('key')->all();
    $this->actingAs($this->owner)->post('/social', ['text' => 'Tour 201 Pine Rd', 'video_url' => 'https://cdn.acme.test/v/reel.mov', 'image_url' => 'https://cdn.acme.test/v/reel.jpg',
        'places' => $places])->assertSessionHasErrors('video_url');
    $this->actingAs($this->owner)->post('/social', ['text' => 'Tour 201 Pine Rd', 'link' => 'https://acme.test/201-pine', 'video_url' => 'https://cdn.acme.test/v/reel.mp4',
        'image_url' => 'https://cdn.acme.test/v/reel.jpg', 'places' => $places])->assertSessionHasNoErrors();
    $post = SocialPost::query()->sole();
    expect($post->video_url)->toBe('https://cdn.acme.test/v/reel.mp4')->and($post->status)->toBe('posted')
        ->and(SocialTarget::query()->pluck('status')->unique()->all())->toBe(['posted']);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/111/videos' && ($r->data()['file_url'] ?? null) === 'https://cdn.acme.test/v/reel.mp4'
        && str_contains((string) ($r->data()['description'] ?? ''), 'utm_source=facebook'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media' && ($r->data()['media_type'] ?? null) === 'REELS'
        && ($r->data()['video_url'] ?? null) === 'https://cdn.acme.test/v/reel.mp4' && ($r->data()['share_to_feed'] ?? null) === 'true');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://graph.facebook.com/v21.0/17841/media_publish');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'locations/7/localPosts') && ($r->data()['media'][0]['sourceUrl'] ?? null) === 'https://cdn.acme.test/v/reel.jpg');
    Sleep::assertSleptTimes(1); // waited once for Instagram to finish processing
});

it('uploads Reels and tours to YouTube (private until the API audit), and only videos go there', function (): void {
    Http::fake([
        'www.googleapis.com/upload/youtube/v3/videos*' => Http::sequence()->push([], 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=s1'])
            ->push(['id' => 'yt123']),
        'cdn.acme.test/*' => Http::response('MP4DATA', 200, ['Content-Type' => 'video/mp4']),
    ]);
    $yt = socialConnect($this->jane, 'youtube');
    expect($yt->settings['channel'])->toBe(['id' => 'mine', 'name' => 'YouTube channel']);
    $place = collect(app(SocialPosts::class)->places($this->jane))->firstWhere('channel', 'youtube')['key'];
    $this->actingAs($this->jane)->post('/social', ['text' => 'Just listed', 'places' => [$place]])->assertSessionHasErrors('video_url');
    $tour = 'https://cdn.acme.test/studio/t/tours/x.mp4';
    $this->actingAs($this->jane)->post('/social', ['text' => "Tour: 201 Pine Rd\n\n0:00 Welcome\n0:05 Kitchen", 'link' => 'https://acme.test/201-pine', 'video_url' => $tour,
        'image_url' => 'https://cdn.acme.test/studio/t/tours/x.jpg', 'places' => [$place]])->assertSessionHasNoErrors();
    expect(SocialTarget::query()->sole()->only(['status', 'url']))->toBe(['status' => 'posted', 'url' => 'https://www.youtube.com/watch?v=yt123']);
    Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable')
        && ($r->data()['snippet']['title'] ?? null) === 'Tour: 201 Pine Rd' && str_contains((string) ($r->data()['snippet']['description'] ?? ''), '0:05 Kitchen')
        && ($r->data()['status']['privacyStatus'] ?? null) === 'private' && $r->hasHeader('X-Upload-Content-Type', 'video/mp4'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PUT' && str_contains($r->url(), 'upload_id=s1') && $r->body() === 'MP4DATA');
});

it('shows the failure in one place without stopping the others', function (): void {
    $fb = socialConnect($this->owner, 'facebook_pages');
    socialConnect($this->owner, 'google_business');
    $fb->forceFill(['credentials' => ['access_token' => 'long-user', 'long_lived' => '1', 'pages' => '{}']])->save(); // Page access removed
    $this->actingAs($this->owner)->post('/social', ['text' => 'June market update: prices steady.', 'places' => collect(app(SocialPosts::class)->places($this->owner))
        ->whereIn('channel', ['facebook', 'google'])->pluck('key')->all()])->assertSessionHasNoErrors();
    expect(SocialPost::query()->sole()->status)->toBe('partial')
        ->and(SocialTarget::query()->orderBy('id')->pluck('status')->all())->toBe(['failed', 'posted'])
        ->and(SocialTarget::query()->orderBy('id')->first()?->error)->toBe('RightAlly can no longer post to this Page. Connect Facebook Pages again.');
});

it('posts through Your AI Assistant after a preview and "Do it"', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => YourAiAssistant::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.assistant']);
    app(ProviderRegistry::class)->fake($model->provider->key, $ai = new FakeAiProvider);
    $ai->respond((string) json_encode(['step' => 'skill', 'skill' => 'social_post', 'arguments' => ['listing' => 'pine', 'networks' => ['linkedin']], 'text' => '']));
    $ai->respond((string) json_encode(['step' => 'connect', 'app' => 'linkedin', 'text' => '']));
    $r = $this->actingAs($this->jane)->postJson('/assistant/ask', ['text' => 'Post my Pine Rd listing on LinkedIn'])->assertOk();
    expect(collect($r->json('turns'))->firstWhere('role', 'card')['card'])->toMatchArray(['type' => 'connect', 'page' => '/apps/linkedin']);

    socialConnect($this->jane, 'linkedin');
    $ai->respond((string) json_encode(['step' => 'skill', 'skill' => 'social_post', 'arguments' => ['listing' => 'pine', 'networks' => ['linkedin']], 'text' => '']));
    $r = $this->actingAs($this->jane)->postJson('/assistant/ask', ['text' => 'Post my Pine Rd listing on LinkedIn'])->assertOk();
    $card = collect($r->json('turns'))->firstWhere('role', 'card');
    expect($card['card']['preview']['where'])->toBe(['LinkedIn: Jane Agent'])->and($card['card']['preview']['text'])->toStartWith('Just listed: 201 Pine Rd')
        ->and(SocialPost::query()->count())->toBe(0);
    $this->actingAs($this->jane)->postJson('/assistant/turns/'.$card['ulid'].'/do')->assertOk();
    expect(SocialPost::query()->sole()->status)->toBe('posted');
});
