<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Sites\Content\MediaLibrary;
use Modules\Sites\Content\Pages;
use Modules\Sites\Content\Posts;
use Modules\Sites\Content\SeoCheck;
use Modules\Sites\Models\SiteMedia;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\SiteSetup;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17C-3a: page builder with drafts and versions, media library, blog, menus, redirects, SEO/AEO check. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea', 'last_name' => 'Broker']);
    $this->agent = User::factory()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['tagline' => 'Boutique homes in Austin'], $this->owner);
    SitePost::query()->delete(); // these tests start with an empty blog (TP2 adds three starter posts)
    $sites->publish($this->site, true, $this->owner);
});

function cmsJpeg(int $w = 2000, int $h = 1500): UploadedFile
{
    return UploadedFile::fake()->image('kitchen.jpg', $w, $h);
}

it('builds a page from sections as a private draft, then publishes it with a version that can be restored', function (): void {
    $this->actingAs($this->agent)->post('/settings/website/pages', ['path' => '/buyers', 'title' => 'Buyers'])->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/website/pages', ['path' => '/homes', 'title' => 'Homes'])->assertSessionHasErrors('path');
    $this->actingAs($this->owner)->post('/settings/website/pages', ['path' => '/buyers', 'title' => 'Buyer guide'])->assertRedirect();
    $page = SitePage::query()->where('path', '/buyers')->sole();
    assertPageContract($this->actingAs($this->owner)->get('/settings/website/pages/'.$page->ulid.'/edit'), 'settings/website-editor');
    $this->get($this->web.'/buyers')->assertNotFound(); // not published yet

    $sections = [
        ['type' => 'text', 'id' => 'answer', 'props' => ['title' => 'Buying a home in Austin', 'body' => 'Most buyers in Austin close in 30 to 45 days. Start with a pre-approval, then tour homes with an agent who knows the neighborhoods you like.']],
        ['type' => 'testimonials', 'id' => 'love', 'props' => ['title' => 'What clients say', 'items' => [['quote' => '<script>alert(1)</script>Best agent ever', 'name' => 'Pat', 'detail' => 'Bought in 78704', 'rating' => 9]]]],
        ['type' => 'video', 'id' => 'tour', 'props' => ['title' => 'Tour', 'url' => 'https://youtu.be/dQw4w9WgXcQ?t=3']],
        ['type' => 'video', 'id' => 'bad', 'props' => ['url' => 'https://evil.example/video']],
        ['type' => 'stats', 'id' => 'nums', 'hidden' => true, 'props' => ['items' => [['value' => '25+', 'label' => 'Years']]]],
        ['type' => 'nope', 'props' => []],
    ];
    $res = $this->actingAs($this->owner)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => $sections])->assertOk()->json();
    expect(array_column($res['sections'], 'type'))->toBe(['text', 'testimonials', 'video', 'video', 'stats'])
        ->and($res['sections'][1]['props']['items'][0]['quote'])->toBe('alert(1)Best agent ever')->and($res['sections'][1]['props']['items'][0]['rating'])->toBe(5)
        ->and($res['sections'][2]['props']['url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')->and($res['sections'][3]['props']['url'])->toBeNull()
        ->and($res['check']['score'])->toBeInt()->and($res['preview_url'])->toContain('/_preview/'.$page->ulid);

    $preview = (string) $this->get($this->web.parse_url($res['preview_url'], PHP_URL_PATH).'?'.parse_url($res['preview_url'], PHP_URL_QUERY))->assertOk()->getContent();
    expect($preview)->toContain('Most buyers in Austin close in 30 to 45 days')->toContain('★★★★★')->not->toContain('<script>alert(1)')
        ->toContain('data-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0"')->not->toContain('<iframe')
        ->not->toContain('Years'); // hidden section

    $this->actingAs($this->owner)->post('/settings/website/pages/'.$page->ulid.'/publish')->assertRedirect();
    $live = (string) $this->get($this->web.'/buyers')->assertOk()->getContent();
    expect($live)->toContain('Most buyers in Austin')->and($page->refresh()->draft_sections)->toBeNull();

    // A second version, then bring back the first.
    $this->actingAs($this->owner)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => [['type' => 'text', 'props' => ['title' => 'Version two', 'body' => 'Changed.']]]]);
    $this->actingAs($this->owner)->post('/settings/website/pages/'.$page->ulid.'/publish');
    expect((string) $this->get($this->web.'/buyers')->getContent())->toContain('Version two');
    $versions = app(Pages::class)->versions($page->refresh());
    expect($versions)->toHaveCount(2);
    $this->actingAs($this->owner)->post('/settings/website/pages/'.$page->ulid.'/restore', ['version' => $versions[1]['id']])->assertRedirect();
    expect($page->refresh()->draft_sections[0]['props']['title'])->toBe('Buying a home in Austin');
    expect((string) $this->get($this->web.'/buyers')->getContent())->toContain('Version two'); // still live until published

    $home = SitePage::query()->where('path', '/')->sole();
    expect(fn () => app(Pages::class)->delete($home, $this->owner))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->delete('/settings/website/pages/'.$page->ulid)->assertRedirect('/settings/website');
    $this->get($this->web.'/buyers')->assertNotFound();
});

it('stores uploads as WebP in three sizes with alt text, and keeps images that are in use', function (): void {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('GD without WebP');
    }
    $this->actingAs($this->owner)->postJson('/settings/website/media', ['file' => cmsJpeg(), 'alt' => ''])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson('/settings/website/media', ['file' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'), 'alt' => 'Nope'])->assertUnprocessable();
    $up = $this->actingAs($this->owner)->postJson('/settings/website/media', ['file' => cmsJpeg(), 'alt' => 'Kitchen with marble island'])->assertCreated()->json('media');
    $m = SiteMedia::query()->sole();
    expect($up['ulid'])->toBe($m->ulid)->and($m->width)->toBe(2000)->and($m->alt)->toBe('Kitchen with marble island');
    foreach ([400, 800, 1600] as $w) {
        Storage::disk('public')->assertExists("{$m->path}-{$w}.webp");
        expect(getimagesizefromstring((string) Storage::disk('public')->get("{$m->path}-{$w}.webp"))[0])->toBe($w);
    }
    $small = app(MediaLibrary::class)->store($this->site, cmsJpeg(300, 200), 'Small', $this->owner);
    expect(getimagesizefromstring((string) Storage::disk('public')->get("{$small->path}-1600.webp"))[0])->toBe(300); // never upscaled

    // Used as the home page hero: shown with its alt text, and cannot be deleted.
    $home = SitePage::query()->where('path', '/')->sole();
    $sections = $home->sections;
    $sections[0]['props']['image'] = $m->ulid;
    $sections[] = ['type' => 'gallery', 'id' => 'g', 'props' => ['title' => 'Inside', 'images' => [$m->ulid]]];
    app(Pages::class)->saveDraft($home, $sections, $this->owner);
    app(Pages::class)->publish($home->refresh(), $this->owner);
    $html = (string) $this->get($this->web.'/')->getContent();
    expect($html)->toContain(MediaLibrary::urlFor($m, 'hero'))->toContain('alt="Kitchen with marble island"');
    $this->actingAs($this->owner)->deleteJson('/settings/website/media/'.$m->ulid)->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson('/settings/website/media/'.$small->ulid)->assertOk();
    Storage::disk('public')->assertMissing("{$small->path}-400.webp");
    assertPageContract($this->actingAs($this->owner)->get('/settings/website/media'), 'settings/website-media');
});

it('publishes blog posts safely with a table of contents, Article data, scheduling, categories, RSS, sitemap and llms.txt', function (): void {
    $body = "Closing costs in Austin are usually 2% to 4% of the price for buyers. Here is what they include, who pays what, and how to lower them before you sign anything at all.\n\n"
        ."## What are closing costs?\n\nFees for the loan, title and taxes. <script>alert(1)</script>\n\n## How much are closing costs in Austin?\n\nSee [homes for sale](/homes) and [bad](javascript:alert(1)).\n\n### Lender fees\n\nOrigination.\n\n![Title office](https://cdn.example/title.jpg)";
    $this->actingAs($this->agent)->post('/settings/website/blog', ['title' => 'Closing costs in Austin', 'body' => $body, 'status' => 'published'])->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/website/blog', ['title' => 'How much are closing costs in Austin?', 'body' => $body, 'category' => 'Buying', 'status' => 'published',
        'excerpt' => 'What buyers pay at closing in Austin, and how to lower it.'])->assertRedirect();
    $post = SitePost::query()->sole();
    expect($post->slug)->toBe('how-much-are-closing-costs-in-austin')->and($post->author_user_id)->toBe($this->owner->id);

    $html = (string) $this->get($this->web.'/blog/'.$post->slug)->assertOk()->getContent();
    expect($html)->toContain('<h2 id="what-are-closing-costs">What are closing costs?</h2>')->toContain('href="#how-much-are-closing-costs-in-austin"')
        ->toContain('"@type":"BlogPosting"')->toContain('"author":{"@type":"Person","name":"Bea Broker"}')->toContain('"articleSection":"Buying"')
        ->toContain('<meta property="og:type" content="article">')->not->toContain('<script>alert(1)')->not->toContain('javascript:')
        ->toContain('<a href="/homes">homes for sale</a>')->toContain('<img loading="lazy" decoding="async" src="https://cdn.example/title.jpg" alt="Title office"');
    expect((string) $this->get($this->web.'/blog')->getContent())->toContain('href="/blog/'.$post->slug.'"')->toContain('href="/blog/category/buying"');
    expect((string) $this->get($this->web.'/blog/category/buying')->assertOk()->getContent())->toContain('How much are closing costs in Austin?');
    $this->get($this->web.'/blog/category/selling')->assertNotFound();
    expect((string) $this->get($this->web.'/blog/feed.xml')->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->getContent())
        ->toContain('<link>http://acme.rightally.site/blog/how-much-are-closing-costs-in-austin</link>');
    expect((string) $this->get($this->web.'/sitemap.xml')->getContent())->toContain('<loc>http://acme.rightally.site/blog/how-much-are-closing-costs-in-austin</loc>');
    expect((string) $this->get($this->web.'/llms.txt')->getContent())->toContain('## Articles')->toContain('[How much are closing costs in Austin?](http://acme.rightally.site/blog/how-much-are-closing-costs-in-austin)');
    expect((string) $this->get($this->web.'/')->getContent())->toContain('href="/blog">Blog</a>');

    // Scheduled and draft posts stay private (a signed preview shows them).
    $this->actingAs($this->owner)->post('/settings/website/blog', ['title' => 'Spring market preview', 'body' => 'Soon.', 'status' => 'published', 'published_at' => '2026-06-03T12:00:00Z']);
    $this->actingAs($this->owner)->post('/settings/website/blog', ['title' => 'Unfinished', 'body' => 'Draft.', 'status' => 'draft']);
    $this->get($this->web.'/blog/spring-market-preview')->assertNotFound();
    $this->get($this->web.'/blog/unfinished')->assertNotFound();
    $preview = (string) app(SiteSetup::class)->previewPostUrl(SitePost::query()->where('slug', 'unfinished')->sole());
    $this->get($this->web.parse_url($preview, PHP_URL_PATH).'?'.parse_url($preview, PHP_URL_QUERY))->assertOk()->assertSee('Draft.')->assertHeader('X-Robots-Tag', 'noindex');
    $this->travelTo(CarbonImmutable::parse('2026-06-03 12:01', 'UTC'));
    $this->get($this->web.'/blog/spring-market-preview')->assertOk();

    $this->actingAs($this->owner)->post('/settings/website/blog', ['title' => 'How much are closing costs in Austin?', 'body' => 'Dup', 'slug' => $post->slug])->assertSessionHasErrors('slug');
    assertPageContract($this->actingAs($this->owner)->get('/settings/website/blog'), 'settings/website-blog');
    assertPageContract($this->actingAs($this->owner)->get('/settings/website/blog/'.$post->ulid), 'settings/website-post');
    $check = $this->actingAs($this->owner)->postJson('/settings/website/blog/check', ['title' => 'Short', 'body' => 'Tiny.'])->assertOk()->json('check');
    expect($check['score'])->toBeLessThan(40)->and(collect($check['checks'])->firstWhere('key', 'answer')['ok'])->toBeFalse();
});

it('scores pages for search and AI answers with exact fixes', function (): void {
    $good = SeoCheck::page('Buying a home in Austin', 'Everything first-time buyers need to know about buying a home in Austin, from pre-approval to closing day.', [
        ['type' => 'hero', 'props' => ['headline' => 'Buy in Austin', 'subhead' => 'Most buyers in Austin close in 30 to 45 days. Start with a pre-approval, then tour homes with an agent who knows the area.']],
        ['type' => 'text', 'props' => ['title' => 'Steps', 'body' => str_repeat('Useful words about buying a home here. ', 30)]],
        ['type' => 'faq', 'props' => ['items' => [['q' => 'How long does it take?', 'a' => '30 to 45 days.']]]],
        ['type' => 'cta', 'props' => ['title' => 'Talk to us', 'link' => '/contact']],
    ], false, 'Acme Realty');
    $bad = SeoCheck::page('Hi', null, [['type' => 'hero', 'props' => ['headline' => 'A']], ['type' => 'hero', 'props' => ['headline' => 'B']]], true, 'Acme');
    expect($good['score'])->toBe(100)
        ->and($bad['score'])->toBeLessThan(30)
        ->and(collect($bad['checks'])->firstWhere('key', 'h1')['message'])->toBe('There are 2 main headings; keep one hero per page.')
        ->and(collect($bad['checks'])->firstWhere('key', 'description')['message'])->toStartWith('Add a search description');
    expect(Posts::render("## A?\n\n## A?")['toc'])->toBe([['id' => 'a', 'text' => 'A?', 'level' => 2], ['id' => 'a-2', 'text' => 'A?', 'level' => 2]]);
});

it('sends moved addresses to their new pages, only when nothing else is there', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/redirects', ['from' => '/about-us.html', 'to' => '/about'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/website/redirects', ['from' => '/x', 'to' => 'javascript:alert(1)'])->assertSessionHasErrors('to');
    $this->actingAs($this->owner)->post('/settings/website/redirects', ['from' => '/loop', 'to' => '/loop'])->assertSessionHasErrors('to');
    $this->actingAs($this->owner)->post('/settings/website/redirects/import', ['lines' => "https://oldsite.com/listings/123.html /homes\n/team https://brokerage.example/people\nnot-a-line\n/about /contact"]);

    $this->get($this->web.'/about-us.html')->assertStatus(301)->assertRedirect($this->web.'/about');
    $this->get($this->web.'/listings/123.html')->assertStatus(301)->assertRedirect($this->web.'/homes');
    $this->get($this->web.'/team')->assertRedirect('https://brokerage.example/people');
    $this->get($this->web.'/about')->assertOk(); // a real page wins over a redirect
    expect(DB::table('site_redirects')->where('from_path', '/about-us.html')->value('hits'))->toBe(1)
        ->and(DB::table('site_redirects')->count())->toBe(4);
});

it('lets the brokerage set the main and footer menus', function (): void {
    $page = $this->actingAs($this->owner)->get('/settings/website');
    $page->assertInertia(fn (Assert $p) => $p->where('menus.custom', false)->where('menus.main.0.url', '/')->has('redirects'));
    $this->actingAs($this->owner)->post('/settings/website/menus', ['main' => [['label' => 'Search', 'url' => '/homes'], ['label' => 'Bad', 'url' => 'javascript:x']], 'footer' => []])
        ->assertSessionHasErrors();
    $this->actingAs($this->owner)->post('/settings/website/menus', ['main' => [['label' => 'Search homes', 'url' => '/homes'], ['label' => 'Our story', 'url' => '/about']],
        'footer' => [['label' => 'Instagram', 'url' => 'https://instagram.com/acme']]])->assertSessionHasNoErrors();
    $html = (string) $this->get($this->web.'/')->getContent();
    expect($html)->toMatch('#<a href="/homes"\s*>Search homes</a>#')->toContain('href="https://instagram.com/acme">Instagram</a>')->not->toContain('>Home value</a>');
});
