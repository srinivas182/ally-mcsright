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
use Modules\Sites\AgentSites;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\SiteSetup;

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
    expect($html)->toContain('class="theme-modern"')->toContain('--brand: #ff0000')->toContain('Equal Housing Opportunity');

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
