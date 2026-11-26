<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\SavedSearch;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Funnels\Funnels;
use Modules\Sites\Models\SiteFunnel;
use Modules\Sites\SiteSetup;

/* Sprint LG1a: Lead Generation Engine — buyer and seller funnels: three taps to publish, value first, every lead
   through the normal intake with its own source, buyers get a saved search, sellers a value range. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    app(LeadRouting::class)->saveRule(['name' => 'Everyone to Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('aurum', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($this->site, true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'F'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 300000 + $i * 50000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1600 + $i * 50, 'StreetNumber' => (string) (100 + $i),
        'StreetName' => 'Elm St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.76,
        'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-15', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 6));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

function funnelForm(array $extra = []): array
{
    return $extra + ['name' => 'Bea Buyer', 'email' => 'bea@x.test', 'phone' => '512-555-0199', 'sms_consent' => '1', 't' => Crypt::encryptString((string) (time() - 30))];
}

it('lets an agent publish a buyer funnel in three taps, showing homes first', function (): void {
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'new_listings', 'area' => '78704', 'max_price' => '$450,000'])->assertSessionHasNoErrors();
    $f = SiteFunnel::query()->sole();
    expect($f->only(['kind', 'area', 'max_price', 'owner_user_id', 'status', 'headline']))->toBe(['kind' => 'new_listings', 'area' => '78704', 'max_price' => 450000,
        'owner_user_id' => $this->ann->id, 'status' => 'live', 'headline' => 'New homes for sale in 78704']);

    $html = (string) $this->get($this->web.'/go/'.$f->slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->getContent();
    expect($html)->toContain('New homes for sale in 78704')->toContain('3 homes match right now up to $450,000')->toContain('101 Elm St')->not->toContain('104 Elm St')
        ->and($f->fresh()?->visits)->toBe(1);
    $this->actingAs($this->ann)->get('/funnels')->assertInertia(fn (Assert $p) => $p->component('grow/funnels')->where('funnels.0.visits', 1)->where('manager', false));
});

it('turns a buyer into a lead for the funnel\'s agent, with a saved search and consent', function (): void {
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'first_home', 'area' => '78704', 'max_price' => '450000']);
    $f = SiteFunnel::query()->sole();
    $res = $this->post($this->web.'/_funnel/'.$f->slug, funnelForm(['budget' => '$400,000', 'timeline' => '3 to 6 months', 'preapproved' => 'Not yet', 'utm_campaign' => 'rightally-x']));
    $res->assertStatus(303);
    expect((string) $res->headers->get('Location'))->toStartWith($this->web.'/go/'.$f->slug.'?r=');

    $lead = Lead::query()->sole();
    expect($lead->intent)->toBe('buy')->and(LeadSource::query()->find($lead->source_id)?->name)->toBe('Funnel: Your first home in 78704')->and(Contact::query()->sole()->sms_opt_in)->toBeTrue()
        ->and($lead->message)->toContain('Budget about $400,000.')->toContain('Timeline: 3 to 6 months.')->toContain('Pre-approved: Not yet.')
        ->and($lead->utm['utm_campaign'] ?? null)->toBe('rightally-x')
        ->and(Contact::query()->sole()->owner_user_id)->toBe($this->ann->id)
        ->and(SavedSearch::query()->sole()->only(['user_id', 'frequency', 'name']))->toBe(['user_id' => $this->ann->id, 'frequency' => 'daily', 'name' => 'Your first home in 78704'])
        ->and(SavedSearch::query()->sole()->filters['max_price'])->toBe(400000)
        ->and($f->fresh()?->leads)->toBe(1);
    expect((string) $this->get((string) $res->headers->get('Location'))->getContent())->toContain('You\'re all set');
});

it('gives a seller a value range on the spot and a seller lead routed by the brokerage\'s rules', function (): void {
    $this->actingAs($this->owner)->post('/funnels', ['kind' => 'home_value', 'area' => 'Austin, TX'])->assertSessionHasNoErrors();
    $f = SiteFunnel::query()->sole();
    expect($f->owner_user_id)->toBeNull()->and($f->slug)->toBe('home-value-austin-tx');
    $res = $this->post($this->web.'/_funnel/'.$f->slug, funnelForm(['address' => '12 Oak Ln', 'zip' => '78704', 'beds' => 3, 'sqft' => 1750]));
    $lead = Lead::query()->sole();
    expect($lead->intent)->toBe('sell')->and($lead->property)->toBe('12 Oak Ln 78704')->and($lead->message)->toContain('Estimate: $')
        ->and(Contact::query()->sole()->owner_user_id)->toBe($this->ann->id) // the brokerage's lead rule
        ->and(SavedSearch::query()->count())->toBe(0);
    expect((string) $this->get((string) $res->headers->get('Location'))->getContent())->toContain('your home is likely worth');
    $this->post($this->web.'/_funnel/'.$f->slug, funnelForm(['address' => '', 'zip' => 'abc']))
        ->assertRedirect($this->web.'/go/'.$f->slug.'?error='.urlencode('Enter your street address and 5-digit ZIP code.').'#ask');
});

it('refuses copy about who should live there, and a paused funnel shows a friendly page', function (): void {
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'moving', 'area' => 'Austin, TX', 'headline' => 'Perfect for families moving to Austin'])->assertSessionHasErrors('headline');
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'moving', 'area' => 'Austin, TX'])->assertSessionHasNoErrors();
    $f = SiteFunnel::query()->sole();
    $this->actingAs($this->owner)->get('/funnels')->assertInertia(fn (Assert $p) => $p->where('funnels.0.for', 'Ann')->where('manager', true));
    $this->actingAs(User::factory()->create())->patch('/funnels/'.$f->ulid, ['status' => 'paused'])->assertForbidden();
    $this->actingAs($this->ann)->patch('/funnels/'.$f->ulid, ['status' => 'paused'])->assertSessionHasNoErrors();
    $this->get($this->web.'/go/'.$f->slug)->assertStatus(410)->assertSee('no longer available');
    $this->post($this->web.'/_funnel/'.$f->slug, funnelForm())->assertStatus(303);
    expect(Lead::query()->count())->toBe(0);
});

it('ignores bots and forms filled too fast', function (): void {
    $this->actingAs($this->owner)->post('/funnels', ['kind' => 'new_listings', 'area' => '78704']);
    $f = SiteFunnel::query()->sole();
    $this->get($this->web.'/go/'.$f->slug, ['User-Agent' => 'facebookexternalhit/1.1'])->assertOk();
    expect($f->fresh()?->visits)->toBe(0);
    $this->post($this->web.'/_funnel/'.$f->slug, funnelForm(['t' => Crypt::encryptString((string) time())]))->assertStatus(303);
    expect(Lead::query()->count())->toBe(0);
});

it('gives agents ten landing pages, each with its own reasons and button, credited to them (AG3)', function (): void {
    expect(Funnels::KINDS)->toHaveCount(10);
    foreach (Funnels::KINDS as $kind => $k) {
        $this->actingAs($this->ann)->post('/funnels', ['kind' => $kind, 'area' => '78704'])->assertSessionHasNoErrors();
        $f = SiteFunnel::query()->where('kind', $kind)->sole();
        expect($f->owner_user_id)->toBe($this->ann->id);
        auth()->logout();
        $html = (string) $this->get($this->web.'/go/'.$f->slug)->assertOk()->getContent();
        expect($html)->toContain(e($f->headline))->toContain(e($k['button']))->toContain(e($k['points'][0]))
            ->toContain('With Ann Agent')->toContain('class="lp-hero"');
        expect(str_contains($html, 'name="address"'))->toBe(Funnels::isValue($kind)); // value pages ask for the home
    }
    // A price-drop page shows homes whose price came down.
    expect(Funnels::filters(SiteFunnel::query()->where('kind', 'price_drops')->sole())['sort'])->toBe('reduced');
});

it('sends a seller on a "fresh plan" page their price range, and the lead to the agent who shared it (AG3)', function (): void {
    $this->actingAs($this->ann)->post('/funnels', ['kind' => 'fresh_start', 'area' => '78704'])->assertSessionHasNoErrors();
    auth()->logout();
    $f = SiteFunnel::query()->sole();
    $this->get($this->web.'/go/'.$f->slug);
    $res = $this->post($this->web.'/_funnel/'.$f->slug, funnelForm(['address' => '12 Oak Ln', 'zip' => '78704', 'beds' => '3']));
    $lead = Lead::query()->sole();
    expect($lead->intent)->toBe('sell')->and($lead->assigned_user_id)->toBe($this->ann->id)->and($lead->message)->toContain('Home value request for 12 Oak Ln');
    expect((string) $this->get((string) $res->headers->get('Location'))->getContent())->toContain('your home is likely worth');
});
