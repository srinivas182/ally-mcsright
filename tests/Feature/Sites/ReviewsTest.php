<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Social\GoogleBusiness;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Reviews\RealSatisfied;
use Modules\Sites\Reviews\Review;
use Modules\Sites\SiteSetup;

/* Sprint C8a: reviews from Google Business Profile and RealSatisfied, kept as written with their source and date,
   shown on the websites, Google replies posted back, and hidden only for abuse, personal data or falsehood. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['name' => 'Acme Realty'], $this->owner), true, $this->owner);
    $hq = User::factory()->make(['id' => 999999]);
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gs']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('google_business'), true, [], null, $hq);
    $this->gbp = Connection::query()->create(['app_key' => 'google_business', 'owner_user_id' => null, 'label' => 'Acme', 'status' => 'connected', 'connected_by' => $this->owner->id,
        'credentials' => ['access_token' => 'g-tok', 'refresh_token' => 'r', 'expires_at' => (string) (time() + 3000)], 'settings' => []]);
});

function gbpFakes(): void
{
    Http::fake([
        'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [['name' => 'accounts/1']]]),
        'mybusinessbusinessinformation.googleapis.com/v1/accounts/1/locations*' => Http::response(['locations' => [['name' => 'locations/2', 'title' => 'Acme Realty']]]),
        'mybusiness.googleapis.com/v4/accounts/1/locations/2/reviews?*' => Http::response(['reviews' => [
            ['name' => 'accounts/1/locations/2/reviews/r1', 'reviewer' => ['displayName' => 'Rosa Diaz'], 'starRating' => 'FIVE', 'comment' => 'Acme found us the perfect home!',
                'createTime' => '2026-05-02T10:00:00Z'],
            ['name' => 'accounts/1/locations/2/reviews/r2', 'reviewer' => ['displayName' => 'Ann', 'isAnonymous' => true], 'starRating' => 'TWO', 'comment' => 'Slow to call back.',
                'createTime' => '2026-05-10T10:00:00Z', 'reviewReply' => ['comment' => 'Sorry Ann, we\'re fixing that.']],
            ['name' => 'not/a/review', 'starRating' => 'FIVE']]]),
        'mybusiness.googleapis.com/v4/accounts/1/locations/2/reviews/r2/reply' => Http::response(['comment' => 'ok']),
    ]);
}

it('brings in Google reviews as written, shows them on the website with their source, and posts replies back to Google', function (): void {
    gbpFakes();
    app(GoogleBusiness::class)->sync($this->gbp->data());
    expect(Review::query()->count())->toBe(2);
    app(GoogleBusiness::class)->sync($this->gbp->data()); // daily: still two
    $r2 = Review::query()->where('external_id', 'accounts/1/locations/2/reviews/r2')->sole();
    expect(Review::query()->count())->toBe(2)->and($r2->only(['source', 'author', 'rating', 'user_id', 'reply']))
        ->toBe(['source' => 'google', 'author' => null, 'rating' => 2, 'user_id' => null, 'reply' => 'Sorry Ann, we\'re fixing that.']);

    $page = SitePage::query()->where('path', '/about')->first() ?? null;
    $this->actingAs($this->owner)->post('/settings/website/pages', ['path' => '/kind-words', 'title' => 'Kind words'])->assertRedirect();
    $page = SitePage::query()->where('path', '/kind-words')->sole();
    $this->actingAs($this->owner)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => [['type' => 'testimonials', 'props' => ['title' => 'Reviews', 'from_reviews' => true]]]])->assertOk();
    $this->actingAs($this->owner)->post('/settings/website/pages/'.$page->ulid.'/publish')->assertRedirect();
    $this->get($this->web.'/kind-words')->assertOk()->assertSee('Acme found us the perfect home!')->assertSee('Google review · May 2026')
        ->assertSee('Slow to call back.')->assertDontSee('AggregateRating', false); // no self-serving review markup

    $this->actingAs($this->owner)->get('/reviews?who=brokerage')->assertInertia(fn (Assert $p) => $p->component('reviews/index')->where('brokerage', true)
        ->where('summary.sources.0.average', 3.5)->has('reviews', 2));
    $this->actingAs($this->owner)->post('/reviews/'.$r2->ulid.'/reply', ['reply' => 'Perfect for families like yours.'])->assertSessionHasErrors('reply');
    $this->actingAs($this->owner)->post('/reviews/'.$r2->ulid.'/reply', ['reply' => 'Thank you Ann. We now call back within the hour.'])->assertSessionHasNoErrors();
    expect($r2->refresh()->reply)->toBe('Thank you Ann. We now call back within the hour.');
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), 'reviews/r2/reply') && $r['comment'] === 'Thank you Ann. We now call back within the hour.');
    $this->actingAs($this->jane)->post('/reviews/'.$r2->ulid.'/reply', ['reply' => 'Hi'])->assertNotFound(); // the brokerage's, not hers
});

it('hides a review only for abuse, personal data or falsehood, by the owner, with the reason kept; low ratings stay', function (): void {
    gbpFakes();
    app(GoogleBusiness::class)->sync($this->gbp->data());
    $r2 = Review::query()->where('rating', 2)->sole();
    $this->actingAs($this->jane)->post('/reviews/'.$r2->ulid.'/hide', ['reason' => 'abuse'])->assertForbidden();
    $this->actingAs($this->owner)->post('/reviews/'.$r2->ulid.'/hide', ['reason' => 'low_rating'])->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->post('/reviews/'.$r2->ulid.'/hide', ['reason' => 'personal_data'])->assertSessionHasNoErrors();
    expect($r2->refresh()->only(['hidden', 'hidden_reason', 'hidden_by']))->toBe(['hidden' => true, 'hidden_reason' => 'personal_data', 'hidden_by' => $this->owner->id]);
    app(GoogleBusiness::class)->sync($this->gbp->data());
    expect($r2->refresh()->hidden)->toBeTrue(); // stays hidden after the next sync
    $this->actingAs($this->owner)->post('/reviews/'.$r2->ulid.'/show')->assertSessionHasNoErrors();
    expect($r2->refresh()->hidden)->toBeFalse();
});

it('brings in an agent\'s RealSatisfied reviews from their public feed by vanity key', function (): void {
    $rss = '<?xml version="1.0"?><rss version="2.0" xmlns:realsatisfied="https://www.realsatisfied.com/rss"><channel><title>Jane</title>'
        .'<item><title>Sam B.</title><description><![CDATA[<p>Jane was <b>amazing</b> from start to finish.</p>]]></description><pubDate>Tue, 12 May 2026 10:00:00 +0000</pubDate>'
        .'<guid>rs-1</guid><realsatisfied:satisfaction>100</realsatisfied:satisfaction><realsatisfied:customer_name>Sam B.</realsatisfied:customer_name></item>'
        .'<item><title>Lee</title><description>Good.</description><pubDate>Wed, 13 May 2026 10:00:00 +0000</pubDate><guid>rs-2</guid></item></channel></rss>';
    Http::fake(['rss.realsatisfied.com/rss/agent/Jane-Doe' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml']),
        'rss.realsatisfied.com/rss/agent/*' => Http::response('', 404)]);
    expect(fn () => app(RealSatisfied::class)->verify([], ['vanity_key' => 'Nobody-Here']))->toThrow(ValidationException::class)
        ->and(app(RealSatisfied::class)->verify([], ['vanity_key' => 'https://www.realsatisfied.com/Jane-Doe']))->toBe('RealSatisfied: Jane-Doe');
    $c = Connection::query()->create(['app_key' => 'realsatisfied', 'owner_user_id' => $this->jane->id, 'label' => 'RealSatisfied', 'status' => 'connected', 'connected_by' => $this->jane->id,
        'credentials' => [], 'settings' => ['vanity_key' => 'Jane-Doe']]);
    $out = app(RealSatisfied::class)->sync($c->data());
    expect($out['updated'])->toBe(2);
    $rs = Review::query()->where('external_id', 'rs-1')->sole();
    expect($rs->only(['source', 'user_id', 'author', 'rating', 'text']))->toBe(['source' => 'realsatisfied', 'user_id' => $this->jane->id, 'author' => 'Sam B.', 'rating' => 5,
        'text' => 'Jane was amazing from start to finish.'])
        ->and(Review::query()->where('external_id', 'rs-2')->value('rating'))->toBeNull();
    $this->actingAs($this->jane)->get('/reviews')->assertInertia(fn (Assert $p) => $p->where('summary.sources.0.label', 'RealSatisfied')->where('summary.sources.0.average', 5)
        ->where('reviews.0.can_reply', false));
});
