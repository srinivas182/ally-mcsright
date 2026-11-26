<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Sites\Console\RefreshStarterContent;
use Modules\Sites\Content\DemoPhotos;
use Modules\Sites\Content\StarterSite;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\Sections;
use Modules\Sites\SiteSetup;

/*
| TP2: a new website starts finished, like a good template: Home, Buy, Sell, Communities, About and Contact with
| licensed demo photos, plus three blog posts. Websites made before can add what they are missing in one click.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
});

it('starts a website with seven pages (Join us included) full of sections and photos, and three blog posts', function (): void {
    $sites = app(SiteSetup::class);
    $site = $sites->create('estate', ['area' => 'Greater Houston', 'state' => 'TX', 'tagline' => 'Homes across Houston'], $this->owner);
    $sites->publish($site, true, $this->owner);

    $pages = SitePage::query()->where('site_id', $site->id)->orderBy('position')->get();
    expect($pages->pluck('path')->all())->toBe(['/', '/buy', '/sell', '/communities', '/about', '/join-us', '/contact'])
        ->and($pages->every(fn (SitePage $p): bool => $p->in_menu && $p->meta_description !== null))->toBeTrue();
    $home = $pages->first();
    expect(array_column($home->sections, 'type'))->toBe(['hero', 'listings', 'split', 'features', 'neighbourhoods', 'banner', 'testimonials', 'faq', 'cta'])
        ->and($home->sections[0]['props']['image'])->toStartWith('demo:')
        ->and($home->sections[4]['props']['places'])->toContain('houston-tx');
    expect(SitePost::query()->where('site_id', $site->id)->count())->toBe(3);

    // The pages render with the demo photos (from Pexels until the server has stored them).
    $html = (string) $this->get($this->web.'/')->assertOk()->getContent();
    expect($html)->toContain('Find your place in Greater Houston')->toContain('https://images.pexels.com/photos/')->toContain('class="banner"')
        ->toContain('split-photo');
    foreach (['/buy', '/sell', '/communities', '/about', '/contact', '/blog', '/blog/first-time-buyer-guide'] as $path) {
        $this->get($this->web.$path)->assertOk();
    }
    // The menu leads to Sell (which leads to the home value page) instead of listing "Home value" too.
    expect($html)->toContain('href="/sell"')->toContain('href="/communities"');

    // Nothing here claims what the brokerage hasn't said: no invented reviews.
    expect(json_encode($pages->pluck('sections')->all()))->not->toContain('"quote"');
});

it('stores demo photos once in every size and serves our copy after that', function (): void {
    Http::fake(['images.pexels.com/*' => Http::response((function (): string {
        $im = imagecreatetruecolor(60, 40);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    })(), 200, ['Content-Type' => 'image/jpeg'])]);
    $ref = DemoPhotos::pick('kitchen');
    expect(DemoPhotos::url($ref, 'hero'))->toStartWith('https://images.pexels.com/');

    $this->artisan('sites:demo-photos')->assertSuccessful();
    $id = substr($ref, 5);
    Storage::disk('public')->assertExists(["demo-photos/{$id}-400.webp", "demo-photos/{$id}-800.webp", "demo-photos/{$id}-1600.webp"]);
    expect(DemoPhotos::url($ref, 'hero'))->toContain("demo-photos/{$id}-1600.webp");

    $count = count(Http::recorded());
    $this->artisan('sites:demo-photos')->assertSuccessful();
    expect(count(Http::recorded()))->toBe($count); // nothing downloaded twice
});

it('only accepts demo photos that exist, and offers them in the editor marked as demos', function (): void {
    $clean = Sections::clean([['type' => 'banner', 'props' => ['title' => 'Hi', 'image' => 'demo:1']], ['type' => 'split', 'props' => ['title' => 'Hi', 'image' => DemoPhotos::pick('living')]]]);
    expect($clean[0]['props']['image'])->toBeNull()->and($clean[1]['props']['image'])->toStartWith('demo:');

    $site = app(SiteSetup::class)->create('modern', ['area' => 'Austin'], $this->owner);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->sole();
    $this->actingAs($this->owner)->get('/settings/website/pages/'.$page->ulid.'/edit')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('media', fn ($m): bool => collect($m)->contains(fn ($x): bool => ($x['demo'] ?? false) === true && str_starts_with($x['ulid'], 'demo:'))));
});

it('lets a website made before add the standard pages it is missing, without touching its own', function (): void {
    $site = app(SiteSetup::class)->create('modern', ['area' => 'Austin'], $this->owner);
    SitePage::query()->where('site_id', $site->id)->whereNotIn('path', ['/', '/about', '/contact'])->delete();
    SitePost::query()->where('site_id', $site->id)->delete();
    SitePage::query()->where('site_id', $site->id)->where('path', '/about')->update(['title' => 'Our story']);

    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->where('starter_missing', ['/buy', '/sell', '/communities', '/join-us']));
    $this->actingAs($this->owner)->post('/settings/website/starter-pages')->assertRedirect();
    expect(SitePage::query()->where('site_id', $site->id)->pluck('path')->sort()->values()->all())->toBe(['/', '/about', '/buy', '/communities', '/contact', '/join-us', '/sell'])
        ->and(SitePage::query()->where('site_id', $site->id)->where('path', '/about')->value('title'))->toBe('Our story')
        ->and(SitePost::query()->where('site_id', $site->id)->count())->toBe(3);
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->where('starter_missing', []));
});

it('uses the brokerage\'s own cities for Explore, and its city skyline when there is one', function (): void {
    expect(StarterSite::places(['state' => 'FL']))->toBe(['miami-fl', 'tampa-fl', 'orlando-fl', 'jacksonville-fl']);
    $communities = collect(StarterSite::pages(['area' => 'Greater Austin']))->firstWhere('path', '/communities');
    $hero = $communities['sections'][0]['props']['image'];
    expect(DemoPhotos::find((int) substr($hero, 5))['group'])->toBe('city_austin');
});

it('replaces the old number strip on websites that still show it unchanged, and leaves edited ones alone (QW1)', function (): void {
    $site = app(SiteSetup::class)->create('estate', ['area' => 'Austin'], $this->owner);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->firstOrFail();
    $sections = (array) $page->sections;
    foreach ($sections as $i => $s) {
        if (($s['id'] ?? '') === 'promise') {
            $sections[$i] = ['type' => 'stats', 'id' => 'promise', 'props' => ['title' => '', 'items' => [['value' => '1 search', 'label' => 'for every home on the MLS']]]];
        }
    }
    $page->forceFill(['sections' => $sections])->save();
    expect(RefreshStarterContent::refresh())->toBe(1)
        ->and(collect($page->fresh()->sections)->firstWhere('id', 'promise')['type'])->toBe('features')
        ->and(RefreshStarterContent::refresh())->toBe(0);
});
