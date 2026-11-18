<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Marketing\Website;

/*
| SG1 (specs/marketing/01): RightAlly's own website — starter pages in English and Spanish, live prices, SEO and AEO
| (canonical, hreflang, JSON-LD, sitemap, robots, llms.txt), drafts, preview, publish, versions and the blog.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->package = Package::query()->create(['name' => 'Team', 'audience' => 'brokerage', 'monthly_cents' => 49_900, 'yearly_cents' => 499_000, 'features' => [], 'active' => true]);
    $this->artisan('marketing:starter')->assertSuccessful();
});

function site(string $path): string
{
    return 'http://rightally.io'.$path;
}

it('publishes the starter pages with search and AI-answer markup, and keeps the comparison as a draft', function (): void {
    $html = $this->get(site('/'))->assertOk()->assertHeader('Cache-Control', 'max-age=300, public')->getContent();
    expect($html)->toContain('<h1>Everything your brokerage runs on, in one place</h1>')
        ->toContain('<link rel="canonical" href="https://rightally.io/">')
        ->toContain('hreflang="es" href="https://rightally.io/es"')
        ->toContain('"@type":"FAQPage"')->toContain('"@type":"SoftwareApplication"')->toContain('"price":"499.00"')
        ->toContain('https://app.rightally.io/start')->not->toContain('<script>alert');

    $this->get(site('/es/pricing'))->assertOk()->assertSee('<html lang="es">', false)->assertSee('$499')->assertSee('?package='.$this->package->ulid, false);
    $this->get(site('/compare'))->assertNotFound();
    $this->get(site('/no-such-page'))->assertNotFound()->assertSee('noindex', false);
    $this->get('http://www.rightally.io/pricing')->assertRedirect('https://rightally.io/pricing')->assertStatus(301);

    // The signup page arrives with that package already chosen.
    $this->get('http://app.rightally.io/start?package='.$this->package->ulid)->assertOk()->assertViewHas('chosen', $this->package->ulid);
    $this->get('http://app.rightally.io/start?package=../../x')->assertOk()->assertViewHas('chosen', null);
});

it('gives search engines and AI answer engines a sitemap, robots rules and llms.txt', function (): void {
    expect($this->get(site('/sitemap.xml'))->assertOk()->getContent())->toContain('<loc>https://rightally.io/pricing</loc>')->toContain('<loc>https://rightally.io/es</loc>')
        ->not->toContain('/compare');
    expect($this->get(site('/robots.txt'))->assertOk()->getContent())->toContain("User-agent: GPTBot\nAllow: /")->toContain('User-agent: ClaudeBot')
        ->toContain('Sitemap: https://rightally.io/sitemap.xml');
    expect($this->get(site('/llms.txt'))->assertOk()->getContent())->toContain('# RightAlly')->toContain('Team: $499.00 a month')->toContain('https://rightally.io/security');
});

it('lets HQ edit a draft, preview it privately, publish it, and put back an earlier version', function (): void {
    $page = app(Website::class)->page('en', 'pricing');
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/website')->assertInertia(fn (Assert $p) => $p->component('hq/website/index')->has('pages', 7));
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/website/pages/'.$page->ulid)->assertInertia(fn (Assert $p) => $p->component('hq/website/page')
        ->where('checks', [])->has('versions', 1)->where('page.preview', fn (string $u): bool => str_contains($u, 'rightally.io/preview/')));

    $blocks = json_decode((string) $page->blocks, true);
    $blocks[0]['heading'] = 'Prices everyone can see';
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/website/pages/'.$page->ulid, ['locale' => 'en', 'slug' => 'pricing', 'title' => $page->title,
        'description' => $page->description, 'blocks' => $blocks])->assertSessionHasNoErrors();
    $this->get(site('/pricing'))->assertDontSee('Prices everyone can see'); // still a draft

    $preview = URL::temporarySignedRoute('marketing.preview', now()->addHour(), ['ulid' => $page->ulid]);
    $this->get($preview)->assertOk()->assertSee('Prices everyone can see')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(site('/preview/'.$page->ulid))->assertForbidden();

    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/pages/'.$page->ulid.'/publish')->assertSessionHasNoErrors();
    $this->get(site('/pricing'))->assertSee('Prices everyone can see');

    $first = DB::connection(Connections::control())->table('marketing_page_versions')->where('page_id', $page->id)->orderBy('id')->value('id');
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/pages/'.$page->ulid.'/restore/'.$first)->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/pages/'.$page->ulid.'/publish');
    $this->get(site('/pricing'))->assertSee('Simple, public prices');

    $home = app(Website::class)->page('en', '');
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/pages/'.$home->ulid.'/unpublish')->assertSessionHasErrors('page');
});

it('refuses unsafe or made-up content, and checks pages for search and AI answers', function (): void {
    $save = fn (array $blocks, string $slug = 'new-page') => $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/pages', [
        'locale' => 'en', 'slug' => $slug, 'title' => 'A new page about RightAlly brokerages', 'description' => 'A description long enough for search results to show it properly, about seventy plus.', 'blocks' => $blocks]);

    $save([['type' => 'hero', 'heading' => 'Hi', 'cta_label' => 'Go', 'cta_href' => 'javascript:alert(1)']])->assertSessionHasErrors('blocks');
    $save([['type' => 'answer', 'question' => 'What?', 'answer' => 'Too short.']])->assertSessionHasErrors('blocks');
    $save([['type' => 'testimonials', 'items' => [['quote' => 'Great', 'name' => 'Pat']]]])->assertSessionHasErrors('blocks');
    $save([['type' => 'hero', 'heading' => 'Hi']], 'blog')->assertSessionHasErrors('slug');
    $save([['type' => 'hero', 'heading' => 'Hi']], 'pricing')->assertSessionHasErrors('slug');

    $save([['type' => 'text', 'heading' => 'About', 'body' => '<b>Hello</b> there']])->assertSessionHasNoErrors();
    $page = app(Website::class)->page('en', 'new-page');
    expect(json_decode((string) $page->draft_blocks, true)[0]['body'])->toBe('Hello there');
    expect(Website::check('en', 'new-page', 'Short', 'Short', json_decode((string) $page->draft_blocks, true), false))->toHaveCount(6);

    // Brokerage people never reach HQ's website editor.
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/hq/website')->assertNotFound();
});

it('runs a blog with scheduled posts, safe Markdown and article markup', function (): void {
    $post = fn (array $over) => $this->actingAs($this->staff)->post('http://app.rightally.io/hq/website/posts', $over + ['locale' => 'en', 'slug' => 'commission-caps',
        'title' => 'How commission caps work', 'description' => 'A plain guide to commission caps for brokerage owners, with examples and the questions agents ask.',
        'body' => "## What a cap is\n\nA cap is the most an agent pays the brokerage in a year.<script>alert(1)</script>\n\n## Why it matters\n\nIt keeps top agents.",
        'author' => 'RightAlly team', 'live' => true]);
    $post(['publish_at' => '2026-09-10 09:00'])->assertSessionHasNoErrors();
    $this->get(site('/blog/commission-caps'))->assertNotFound(); // scheduled
    $this->get(site('/blog'))->assertDontSee('How commission caps work');

    $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00', 'UTC'));
    $html = $this->get(site('/blog/commission-caps'))->assertOk()->getContent();
    expect($html)->toContain('"@type":"BlogPosting"')->toContain('id="what-a-cap-is"')->not->toContain('<script>alert');
    $this->get(site('/blog'))->assertSee('How commission caps work');
    expect($this->get(site('/sitemap.xml'))->getContent())->toContain('https://rightally.io/blog/commission-caps');
});
