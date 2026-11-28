<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Recruiting\SelfJoin;
use Modules\Sites\AgentSites;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\SiteSetup;
use Modules\Sites\Tools\HomeCalculators;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17C-3b: agent websites, the brand kit, the agent directory, agent domains and landing pages. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Router']);
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '+15125550177']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->brokerage = $sites->create('aurum', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($this->brokerage, true, $this->owner);

    // Jane's roster credentials: her licence and MLS id.
    foreach ([['licence', 'TX', '0712345'], ['mls', null, 'AGT1']] as [$kind, $state, $number]) {
        DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'kind' => $kind,
            'state_code' => $state, 'number' => $number, 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    }
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = [agHome(1, 'AGT1'), agHome(2, 'OTHER'), agHome(3, 'AGT1')];
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

function agHome(int $i, string $agent): array
{
    return ['ListingKey' => 'A'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 500000 + $i * 1000, 'BedroomsTotal' => 3,
        'StreetNumber' => (string) (200 + $i), 'StreetName' => 'Pine Rd', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'ListOfficeName' => 'Acme Realty',
        'ListAgentMlsId' => $agent, 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'];
}

function janeSite(object $t, bool $live = true): Site
{
    $t->actingAs($t->jane)->post('/settings/my-website')->assertSessionHasNoErrors();
    $site = Site::query()->where('kind', 'agent')->sole();
    $t->actingAs($t->jane)->patch('/settings/my-website', ['name' => 'Jane Doe', 'tagline' => 'South Austin specialist', 'bio' => 'Twelve years helping buyers.',
        'phone' => '(512) 555-0177', 'email' => 'jane@acme.test', 'areas' => '78704, Travis Heights', 'languages' => 'English, Spanish'])->assertSessionHasNoErrors();
    if ($live) {
        $t->actingAs($t->jane)->post('/settings/my-website/publish', ['live' => true])->assertSessionHasNoErrors();
    }

    return $site->refresh();
}

it('gives each agent a website with their own listings, licence and brokerage credit', function (): void {
    assertPageContract($this->actingAs($this->jane)->get('/settings/my-website'), 'settings/my-website');
    $site = janeSite($this, false);
    expect($site->slug)->toBe('jane-doe')->and($site->template)->toBe('aurum');
    $this->get($this->web.'/agents/jane-doe')->assertNotFound(); // draft
    $this->actingAs($this->jane)->post('/settings/my-website/publish', ['live' => true]);

    $html = (string) $this->get($this->web.'/agents/jane-doe')->assertOk()->getContent();
    expect($html)->toContain('<title>Jane Doe | Real estate agent at ')->toContain('South Austin specialist') // long brokerage names are shortened in the title
        ->toContain('<small>'.e($this->tenant->name).'</small>')->toContain('Brokered by')->toContain('TX licence 0712345')
        ->toContain('tel:5125550177')->toContain('201 Pine Rd')->toContain('203 Pine Rd')->not->toContain('202 Pine Rd') // only her listings
        ->toContain('"@type":"RealEstateAgent","@id":"http://acme.rightally.site/agents/jane-doe#agent"')
        ->toContain('"parentOrganization":{"@id":"http://acme.rightally.site/#organization"}')->toContain('"value":"0712345"')
        ->toContain('"knowsLanguage":["English","Spanish"]')->toContain('Equal Housing Opportunity')
        ->toContain('name="agent" value="'.$site->ulid.'"')->toContain('<link rel="canonical" href="http://acme.rightally.site/agents/jane-doe">');

    $dir = (string) $this->get($this->web.'/agents')->assertOk()->getContent();
    expect($dir)->toContain('href="/agents/jane-doe"')->toContain('"@type":"ItemList"')->toContain('Speaks English, Spanish');
    expect((string) $this->get($this->web.'/agents?language=French')->getContent())->not->toContain('href="/agents/jane-doe"');
    expect((string) $this->get($this->web.'/agents?q=travis')->getContent())->toContain('href="/agents/jane-doe"');
    expect((string) $this->get($this->web.'/sitemap.xml')->getContent())->toContain('<loc>http://acme.rightally.site/agents/jane-doe</loc>');
    expect((string) $this->get($this->web.'/llms.txt')->getContent())->toContain('## Our agents')->toContain('[Jane Doe](http://acme.rightally.site/agents/jane-doe): 78704, Travis Heights (speaks English, Spanish)');
});

it('sends leads from an agent website to that agent, not the routing rules', function (): void {
    $site = janeSite($this);
    $form = ['name' => 'Pat Buyer', 'email' => 'pat@x.test', 'message' => 'Hi Jane', 'agent' => $site->ulid, 'return' => '/agents/jane-doe', 't' => Crypt::encryptString((string) (time() - 30))];
    $this->post($this->web.'/_lead', $form)->assertRedirect();
    $lead = Lead::query()->sole();
    expect($lead->assigned_user_id)->toBe($this->jane->id)->and(LeadSource::query()->find($lead->source_id)?->name)->toBe('Agent website');

    // Without an agent, the brokerage's routing applies.
    $this->post($this->web.'/_lead', [...$form, 'email' => 'sam@x.test', 'agent' => null]);
    expect(Lead::query()->latest('id')->first()->assigned_user_id)->toBe($this->ann->id);

    // A forged or offline agent site is ignored.
    $this->actingAs($this->owner)->post('/settings/website/agents/'.$site->ulid.'/publish', ['live' => false])->assertSessionHasNoErrors();
    $this->post($this->web.'/_lead', [...$form, 'email' => 'lee@x.test']);
    expect(Lead::query()->latest('id')->first()->assigned_user_id)->toBe($this->ann->id);
});

it('applies the broker brand kit to what agents may change', function (): void {
    $site = janeSite($this);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->sole();
    $brokerHome = SitePage::query()->where('site_id', $this->brokerage->id)->where('path', '/')->sole();
    $sections = [['type' => 'text', 'props' => ['title' => 'Hello from Jane', 'body' => 'My story.']]];

    $this->actingAs($this->jane)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => $sections])->assertOk(); // sections allowed by default
    $this->actingAs($this->jane)->putJson('/settings/website/pages/'.$brokerHome->ulid.'/draft', ['sections' => $sections])->assertForbidden();
    $this->actingAs($this->ann)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => $sections])->assertForbidden();
    $this->actingAs($this->jane)->patch('/settings/my-website', ['template' => 'modern', 'brand' => ['primary' => '#ff0000']]);
    expect($site->refresh()->template)->toBe('aurum')->and($site->brand['primary'] ?? null)->not->toBe('#ff0000'); // not allowed

    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => true, 'template' => true, 'colours' => true, 'sections' => false, 'domain' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->jane)->putJson('/settings/website/pages/'.$page->ulid.'/draft', ['sections' => $sections])->assertForbidden();
    $this->actingAs($this->jane)->patch('/settings/my-website', ['template' => 'modern', 'brand' => ['primary' => '#ff0000', 'accent' => '#00ff00']]);
    expect($site->refresh()->template)->toBe('modern');
    $html = (string) $this->get($this->web.'/agents/jane-doe')->getContent();
    expect($html)->toContain('class="theme-modern ')->toContain('--brand: #ff0000')->toContain('Equal Housing Opportunity');

    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => false, 'template' => false, 'colours' => false, 'sections' => false, 'domain' => false]);
    $this->get($this->web.'/agents/jane-doe')->assertNotFound();
    expect((string) $this->get($this->web.'/agents')->getContent())->not->toContain('jane-doe');
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->where('agent_rules.enabled', false)->where('agent_sites.0.slug', 'jane-doe'));
});

it('serves an agent website on the agent\'s own domain, with leads going to them', function (): void {
    $site = janeSite($this);
    $this->actingAs($this->jane)->post('/settings/my-website/domains', ['host' => 'www.janedoehomes.com'])->assertSessionHasErrors('host'); // not allowed yet
    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => true, 'template' => false, 'colours' => false, 'sections' => true, 'domain' => true]);
    $this->actingAs($this->jane)->post('/settings/my-website/domains', ['host' => 'www.janedoehomes.com'])->assertSessionHasNoErrors();
    $domain = TenantDomain::query()->where('host', 'www.janedoehomes.com')->sole();
    expect($domain->site_ulid)->toBe($site->ulid)->and($domain->purpose)->toBe('website');
    $domain->forceFill(['verification_status' => 'verified'])->save();
    app(DomainResolver::class)->forget('www.janedoehomes.com');

    $home = (string) $this->get('http://www.janedoehomes.com/')->assertOk()->getContent();
    expect($home)->toContain('South Austin specialist')->toContain('<link rel="canonical" href="http://www.janedoehomes.com/">')->toContain('Brokered by');
    expect((string) $this->get('http://www.janedoehomes.com/homes')->assertOk()->getContent())->toContain('<small>'.e($this->tenant->name).'</small>');

    $this->post('http://www.janedoehomes.com/_lead', ['name' => 'Kim', 'email' => 'kim@x.test', 'return' => '/', 't' => Crypt::encryptString((string) (time() - 30))]);
    expect(Lead::query()->sole()->assigned_user_id)->toBe($this->jane->id);

    $this->actingAs($this->jane)->post('/settings/my-website/publish', ['live' => false]);
    $this->get('http://www.janedoehomes.com/')->assertNotFound();
});

it('makes landing pages for ads: no menu, hidden from search engines, leads reported under the page', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/pages', ['path' => '/free-home-value', 'title' => 'Free home value in 78704', 'layout' => 'landing'])->assertRedirect();
    $page = SitePage::query()->where('path', '/free-home-value')->sole();
    expect($page->layout)->toBe('landing')->and($page->noindex)->toBeTrue();
    $this->actingAs($this->owner)->post('/settings/website/pages/'.$page->ulid.'/publish');

    $html = (string) $this->get($this->web.'/free-home-value?utm_source=facebook&utm_campaign=spring')->assertOk()->getContent();
    expect($html)->not->toContain('<nav aria-label="Main">')->not->toContain('data-account')->toContain('content="noindex, follow"')
        ->toContain('Equal Housing Opportunity')->toContain('action="/_lead"');

    $this->post($this->web.'/_lead', ['name' => 'Lu', 'email' => 'lu@x.test', 'return' => '/free-home-value', 'landing_url' => $this->web.'/free-home-value?utm_source=facebook&utm_campaign=spring',
        't' => Crypt::encryptString((string) (time() - 30))])->assertRedirect($this->web.'/free-home-value?sent=1#contact');
    $lead = Lead::query()->sole();
    expect(LeadSource::query()->find($lead->source_id)?->name)->toBe('Landing page: Free home value in 78704')
        ->and($lead->utm['utm_campaign'] ?? null)->toBe('spring');
    expect(AgentSites::rules()['enabled'])->toBeTrue();
});

/* AW1: referral links and QR codes, approval before agent websites go live, and the website calculators (specs/websites/18). */

it('gives agents a referral link and QR code that send later leads to them', function (): void {
    $site = janeSite($this);
    $this->actingAs($this->jane)->get('/settings/my-website')->assertInertia(fn ($p) => $p->where('site.referral', fn ($u) => str_ends_with((string) $u, '/r/jane-doe')));
    $qr = $this->actingAs($this->jane)->get('/settings/my-website/qr.png')->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr((string) $qr->getContent(), 1, 3))->toBe('PNG');

    $r = $this->get($this->web.'/r/jane-doe?to=/homes')->assertRedirect($this->web.'/homes')->assertHeader('Cache-Control', 'no-store, private');
    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'ra_agent');
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();
    $this->get($this->web.'/r/jane-doe?to=//evil.example')->assertRedirect($this->web.'/agents/jane-doe'); // only this site
    $this->get($this->web.'/r/nobody')->assertRedirect($this->web.'/');

    // Days later the visitor sends the brokerage contact form: it goes to Jane, not the routing rules.
    $form = ['name' => 'Ref Buyer', 'email' => 'ref@x.test', 'message' => 'Hello', 'return' => '/contact', 't' => Crypt::encryptString((string) (time() - 30))];
    $this->withUnencryptedCookie('ra_agent', $cookie->getValue())->post($this->web.'/_lead', $form)->assertRedirect();
    expect(Lead::query()->latest('id')->first()->assigned_user_id)->toBe($this->jane->id);

    // The broker can switch referral links off.
    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => true, 'template' => false, 'colours' => false, 'sections' => true, 'domain' => false,
        'referrals' => false, 'approval' => false])->assertSessionHasNoErrors();
    $this->get($this->web.'/r/jane-doe')->assertRedirect($this->web.'/');
    $this->actingAs($this->jane)->get('/settings/my-website/qr.png')->assertNotFound();
});

it('lets the broker approve agent websites before they go live', function (): void {
    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => true, 'template' => false, 'colours' => false, 'sections' => true, 'domain' => false,
        'referrals' => true, 'approval' => true])->assertSessionHasNoErrors();
    $site = janeSite($this);
    expect($site->status)->toBe('review');
    $this->get($this->web.'/agents/jane-doe')->assertNotFound();
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn ($p) => $p->where('agent_sites.0.status', 'review'));
    $this->actingAs($this->owner)->post('/settings/website/agents/'.$site->ulid.'/publish', ['live' => true])->assertSessionHasNoErrors();
    expect($site->refresh()->status)->toBe('live');
    $this->get($this->web.'/agents/jane-doe')->assertOk();
});

it('has free calculators that work without JavaScript and are found by search engines', function (): void {
    $hub = (string) $this->get($this->web.'/calculators')->assertOk()->getContent();
    expect($hub)->toContain('href="/calculators/mortgage"')->toContain('href="/calculators/affordability"')->toContain('href="/calculators/rent-vs-buy"');

    $page = (string) $this->get($this->web.'/calculators/mortgage')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public, s-maxage=900')->getContent();
    expect($page)->toContain('"@type":"FAQPage"')->toContain('"@type":"HowTo"')->toContain('<link rel="canonical" href="http://acme.rightally.site/calculators/mortgage">')
        ->toContain('a month');

    $mine = (string) $this->get($this->web.'/calculators/mortgage?price=500,000&down_pct=10&rate=6&years=30&tax_rate=1&insurance=100&hoa=50')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->getContent();
    $m = HomeCalculators::mortgage(500000, 10, 6, 30, 1, 100, 50, 0.5);
    expect($m['principal_interest'])->toBe(2698)->and($m['tax'])->toBe(417)->and($m['pmi'])->toBe(188)->and($m['total'])->toBe(3452)
        ->and($mine)->toContain('$3,452')->toContain('Mortgage insurance')->toContain('content="noindex');

    $a = HomeCalculators::affordability(120000, 500, 60000, 6.5, 30, 1.2, 150, 0);
    expect($a['monthly'])->toBe(2800)->and($a['price'])->toBeGreaterThan(400000)->toBeLessThan(500000);
    expect((string) $this->get($this->web.'/calculators/affordability?income=120000&debts=500&down=60000&rate=6.5')->getContent())->toContain('/homes?max_price='.$a['price']);

    $rb = HomeCalculators::rentVsBuy(2500, 400000, 20, 6.5, 30, 10, 1.2, 150, 3, 3);
    expect($rb['better'])->toBeIn(['buy', 'rent'])->and($rb['rent_total'])->toBeGreaterThan(300000);
    $this->get($this->web.'/calculators/nope')->assertNotFound();

    // The brokerage's own figures.
    $this->actingAs($this->owner)->post('/settings/website/calculators', ['rate' => 5.875, 'down_pct' => 15, 'tax_rate' => 2.1, 'insurance' => 175])->assertSessionHasNoErrors();
    expect((string) $this->get($this->web.'/calculators/mortgage')->getContent())->toContain('value="5.875"')->toContain('value="2.1"');
    expect((string) $this->get($this->web.'/sitemap.xml')->getContent())->toContain('/calculators/rent-vs-buy</loc>');
    expect((string) $this->get($this->web.'/llms.txt')->getContent())->toContain('/calculators/affordability');
});

it('remembers the agent whose page a visitor opened, shows "Your agent" on other pages, and sends their leads to the agent (AG2)', function (): void {
    janeSite($this);
    // The agent's page stays cached for everyone; the page itself asks to remember Jane.
    $page = $this->get($this->web.'/agents/jane-doe')->assertOk();
    expect(collect($page->headers->getCookies())->first(fn ($c) => $c->getName() === 'ra_agent'))->toBeNull()
        ->and($page->getContent())->toContain('data-your-agent');

    $this->get($this->web.'/_ref')->assertNoContent(); // a visitor who came with no agent
    $this->get($this->web.'/_ref?remember=nobody')->assertNoContent();
    $r = $this->get($this->web.'/_ref?remember=jane-doe')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJson(['name' => 'Jane Doe', 'url' => '/agents/jane-doe']);
    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'ra_agent');
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();

    // On any other page: who the visitor is with.
    $this->withUnencryptedCookie('ra_agent', $cookie->getValue())->get($this->web.'/_ref')->assertOk()->assertJson(['first_name' => 'Jane']);

    // Their lead later, from the brokerage's contact form, goes to Jane.
    $form = ['name' => 'Page Buyer', 'email' => 'page@x.test', 'message' => 'Hello', 'return' => '/contact', 't' => Crypt::encryptString((string) (time() - 30))];
    $this->withUnencryptedCookie('ra_agent', $cookie->getValue())->post($this->web.'/_lead', $form)->assertRedirect();
    expect(Lead::query()->latest('id')->first()->assigned_user_id)->toBe($this->jane->id);

    // A broker who switched referral links off: nothing is remembered.
    $this->actingAs($this->owner)->post('/settings/website/agents', ['enabled' => true, 'template' => false, 'colours' => false, 'sections' => true, 'domain' => false,
        'referrals' => false, 'approval' => false]);
    auth()->logout();
    $this->unencryptedCookies = [];
    expect(collect($this->get($this->web.'/_ref?remember=jane-doe')->headers->getCookies())->first(fn ($c) => $c->getName() === 'ra_agent'))->toBeNull();
});

it('opens an agent\'s page with their profile: photo or initials, Call/Text/Email, areas, and their own contact form (AG7)', function (): void {
    janeSite($this);
    $html = (string) $this->get($this->web.'/agents/jane-doe')->assertOk()->getContent();
    expect($html)->toContain('class="agent-hero"')->toContain('<h1 class="agent-name"')->toContain('Jane Doe</h1>')
        ->toContain('Real estate agent with <strong>')->toContain('South Austin specialist')
        ->toContain('<li>Travis Heights</li>')->toContain('href="tel:5125550177"')->toContain('href="sms:5125550177"')->toContain('href="mailto:jane@acme.test"')
        ->toContain('class="agent-monogram"')->toContain('>JD</span>') // no photo yet: her initials
        ->toContain('class="agent-dock"')->toContain('TX real estate licence 0712345')
        ->toContain('Twelve years helping buyers.') // "About Jane" uses her bio
        ->toContain('Message Jane')->not->toContain('Office hours')->not->toContain('Send us a message')
        ->not->toContain('Helping you buy and sell with'); // the old opening is gone
});

it('moves agents\' existing pages to the profile opening, leaving pages they changed alone (AG7)', function (): void {
    $site = janeSite($this);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->sole();
    $old = ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => 'Jane Doe', 'subhead' => 'Helping you buy and sell.', 'search' => true]];
    $page->forceFill(['sections' => [$old, ...array_slice($page->sections, 1)]])->save();
    (require base_path('modules/Sites/database/migrations/2027_07_02_000001_agent_profile_section.php'))->up();
    expect($page->fresh()->sections[0]['type'])->toBe('agent');

    $page->forceFill(['sections' => [array_replace_recursive($old, ['props' => ['headline' => 'Your Austin home, made simple']]), ...array_slice($page->sections, 1)]])->save();
    (require base_path('modules/Sites/database/migrations/2027_07_02_000001_agent_profile_section.php'))->up();
    expect($page->fresh()->sections[0]['props']['headline'])->toBe('Your Austin home, made simple'); // changed by Jane: kept
});

it('suggests the agent whose page the visitor browsed when they click Join us, which they can change (FX4)', function (): void {
    janeSite($this);
    $code = app(SelfJoin::class)->codeFor($this->jane);
    $r = $this->get($this->web.'/r/jane-doe?to=/join-us');
    $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'ra_agent');

    $join = $this->withUnencryptedCookie('ra_agent', $cookie->getValue())->get($this->web.'/join')->assertRedirect();
    expect($join->headers->get('Location'))->toEndWith('/join/'.$code.'?via=page');
    $this->unencryptedCookies = [];
    expect($this->get($this->web.'/join')->headers->get('Location'))->toEndWith('/join'); // no agent page: the company
});
