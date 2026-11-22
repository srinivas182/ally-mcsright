<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\AgentPlan;
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
    expect($html)->toContain('<h1>Run your whole real estate business in one place</h1>')
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
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/website')->assertInertia(fn (Assert $p) => $p->component('hq/website/index')->has('pages', 15)); // SITE1: + privacy and terms in both languages; SITE3: + three Spanish twins
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

it('shows a language selector, centred legal links and default privacy and terms pages (SITE1)', function (): void {
    $html = (string) $this->get(site('/'))->assertOk()->getContent();
    expect($html)->toContain('<details class="lang">')->toContain('aria-label="Language: English"')->toContain('hreflang="es" lang="es"')
        ->toContain('href="https://rightally.io/privacy"')->toContain('href="https://rightally.io/terms"')->toContain('© '.date('Y').' RightAlly')
        ->toContain('/favicon.svg?v=br1');
    preg_match('#<span class="links">(.*?)</span>#s', $html, $m);
    expect($m[1] ?? '')->not->toContain('Español'); // a language selector, never a menu item

    $this->get(site('/privacy'))->assertOk()->assertSee('Privacy policy')->assertSee('We do not sell personal information')->assertSee('index, follow', false);
    $this->get(site('/terms'))->assertOk()->assertSee('Terms of use')->assertSee('State of Florida');
    $this->get(site('/es/privacy'))->assertOk()->assertSee('Política de privacidad');

    // Once HQ publishes its own version, that is what shows.
    $page = app(Website::class)->page('en', 'privacy');
    $ulid = app(Website::class)->saveDraft((string) $page->ulid, 'en', 'privacy', 'Privacy policy | RightAlly', 'Ours',
        [['type' => 'text', 'heading' => 'Our own words', 'body' => 'Edited by HQ.']], $this->staff);
    app(Website::class)->publish($ulid, $this->staff);
    Cache::flush();
    $this->get(site('/privacy'))->assertOk()->assertSee('Edited by HQ.')->assertDontSee('We do not sell personal information');
});

it('always shows agent prices on the pricing page, and what every price includes (SITE1)', function (): void {
    $this->package->forceFill(['modules' => ['crm', 'website', 'transactions'], 'seats' => 10, 'ailly_conversations' => 500])->save();
    AgentPlan::query()->create(['key' => 'pro', 'name' => 'Agent Pro', 'description' => 'For busy agents', 'list_cents' => 7_900, 'floor_cents' => 4_900,
        'features' => ['backoffice.basic', 'website', 'content.blog'], 'sort' => 1, 'active' => true]);
    // An old pricing page without the agents block (made before BR4).
    $page = app(Website::class)->page('en', 'pricing');
    $blocks = array_values(array_filter(json_decode((string) $page->blocks, true), fn (array $b): bool => $b['type'] !== 'seats'));
    $ulid = app(Website::class)->saveDraft((string) $page->ulid, 'en', 'pricing', (string) $page->title, (string) $page->description, $blocks, $this->staff);
    app(Website::class)->publish($ulid, $this->staff);
    Cache::flush();

    $html = (string) $this->get(site('/pricing'))->assertOk()->getContent();
    expect($html)->toContain('Agent Pro')->toContain('$49')->toContain('10 agent seats included')->toContain('IDX website with AIlly and 1 MLS board')
        ->toContain('Deals, forms and e-sign')->toContain('500 AIlly conversations a month')
        ->toContain('Own website with AIlly')->toContain('AI blog writer')->not->toContain('(MK4)');
});

it('gives the Spanish website the same menu as the English one, each page linked to its twin (SITE3)', function (): void {
    $menu = fn (string $path): string => preg_match('#<span class="links">(.*?)</span>#s', (string) $this->get(site($path))->assertOk()->getContent(), $m) ? $m[1] : '';
    expect($menu('/'))->toContain('Brokerages')->toContain('Agents')->toContain('Pricing')->toContain('Security');
    expect($menu('/es'))->toContain('Corredurías')->toContain('Agentes')->toContain('Precios')->toContain('Seguridad')
        ->toContain('href="https://rightally.io/es/for-brokers"');

    $html = (string) $this->get(site('/es/for-brokers'))->assertOk()->getContent();
    expect($html)->toContain('<html lang="es">')->toContain('Un sistema para toda su correduría')
        ->toContain('hreflang="en" href="https://rightally.io/for-brokers"')->toContain('https://app.rightally.io/health-check/es');
    $this->get(site('/for-agents'))->assertOk()->assertSee('hreflang="es" href="https://rightally.io/es/for-agents"', false);
    $this->get(site('/es/security'))->assertOk()->assertSee('Sus datos son suyos');
});
