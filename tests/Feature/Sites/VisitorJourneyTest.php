<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Crm\Consent;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\SavedSearch;
use Modules\Crm\Models\Task;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Mail\SignInCodeMail;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\SiteSetup;
use Modules\Sites\Visitors\JourneySummary;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17C-2b: visitor accounts, saved homes, compare, home value, the visitor journey on leads, ready-to-act alerts. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    Notification::fake();
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('aurum', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => vjHome($i), range(1, 6));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->vid = 'vid_'.str_repeat('a', 20);
});

function vjHome(int $i): array
{
    return ['ListingKey' => 'V'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 400000 + $i * 25000,
        'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1600 + $i * 50, 'StreetNumber' => (string) (100 + $i), 'StreetName' => 'Elm St', 'City' => 'Austin',
        'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.76, 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-15',
        'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'];
}

function vjUlid(int $i): string
{
    return Listing::query()->where('listing_key', 'V'.$i)->value('ulid');
}

function vjBeacon(object $t, array $events, array $extra = [], array $headers = []): void
{
    $server = ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $t->call('POST', $t->web.'/_t', [], [], [], $server, (string) json_encode($extra + ['vid' => $t->vid, 'ref' => 'https://chatgpt.com/', 'landing' => $t->web.'/', 'events' => $events]))
        ->assertNoContent();
}

function vjSignIn(object $t, string $email = 'jane@x.test', ?string $vid = null): array
{
    $t->postJson($t->web.'/_auth/start', ['email' => $email])->assertStatus(202);
    $code = null;
    Mail::assertSent(SignInCodeMail::class, function (SignInCodeMail $m) use (&$code, $email): bool {
        if ($m->hasTo($email)) {
            $code = $m->code;
        }

        return true;
    });

    return $t->postJson($t->web.'/_auth/verify', ['email' => $email, 'code' => (string) $code, 'vid' => $vid ?? $t->vid, 'name' => 'Jane Doe'])->assertOk()->json();
}

it('records the journey without cookies, only for homes the brokerage may show, and not for GPC browsers or bots', function (): void {
    vjBeacon($this, [['type' => 'page', 'path' => '/'], ['type' => 'search', 'query' => 'q=78704&beds=3&max_price=550000'], ['type' => 'listing', 'listing' => vjUlid(1)],
        ['type' => 'listing', 'listing' => str_repeat('Z', 26)], ['type' => 'hack', 'path' => '/x'], ['type' => 'listing', 'listing' => '<script>']]);
    $v = SiteVisitor::query()->sole();
    expect($v->first_referrer)->toBe('https://chatgpt.com/')->and(SiteEvent::query()->orderBy('id')->pluck('type')->all())->toBe(['page', 'search', 'listing'])
        // MySQL keeps JSON keys in its own order, so compare as maps.
        ->and(SiteEvent::query()->where('type', 'listing')->sole()->data)->toEqual(['label' => '101 Elm St, Austin', 'price' => 425000, 'beds' => 3, 'zip' => '78704'])
        ->and(SiteEvent::query()->where('type', 'search')->sole()->data)->toEqual(['q' => '78704', 'max_price' => 550000, 'beds' => 3]);

    vjBeacon($this, [['type' => 'page', 'path' => '/about']], [], ['Sec-GPC' => '1']);
    vjBeacon($this, [['type' => 'page', 'path' => '/about']], [], ['User-Agent' => 'Googlebot/2.1']);
    expect(SiteEvent::query()->count())->toBe(3);

    $v->forceFill(['events_day' => '2026-06-01', 'events_today' => 500])->save();
    vjBeacon($this, [['type' => 'page', 'path' => '/about']]);
    expect(SiteEvent::query()->count())->toBe(3);
});

it('signs visitors in with an emailed code; the first sign-in becomes a lead with their journey and a summary', function (): void {
    vjBeacon($this, [['type' => 'listing', 'listing' => vjUlid(2)], ['type' => 'listing', 'listing' => vjUlid(2)], ['type' => 'listing', 'listing' => vjUlid(3)],
        ['type' => 'search', 'query' => 'q=78704&beds=3']]);

    $this->postJson($this->web.'/_auth/start', ['email' => 'jane@x.test'])->assertStatus(202);
    foreach (range(1, 5) as $_) {
        $this->postJson($this->web.'/_auth/verify', ['email' => 'jane@x.test', 'code' => '000000', 'vid' => $this->vid])->assertUnprocessable();
    }
    expect(DB::table('site_login_codes')->value('code_hash'))->not->toBe('000000'); // stored hashed
    $me = vjSignIn($this);
    expect($me['name'])->toBe('Jane Doe')->and($me['token'])->toBeString()->and($me['favourites'])->toBe([]);

    $lead = Lead::query()->sole();
    $visitor = SiteVisitor::query()->sole();
    expect($lead->channel)->toBe('ai_search')->and($lead->assigned_user_id)->toBe($this->ann->id)
        ->and($visitor->contact_id)->toBe($lead->contact_id)->and($visitor->verified_at)->not->toBeNull()
        ->and($visitor->summary)->toBe('Looking at 3-bed homes around $475k ($450k–$475k) in 78704. Viewed 2 homes over 1 visit; most interested in 102 Elm St, Austin ($450k), viewed 2 times. Found you through an AI assistant.')
        ->and($visitor->summary_ai)->toBeFalse()
        ->and(Activity::query()->where('contact_id', $lead->contact_id)->where('body', 'like', 'Website journey: Looking at 3-bed homes%')->exists())->toBeTrue();
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => str_starts_with($n->url, 'http://acme.rightally.app/') || str_starts_with($n->url, 'https://acme.rightally.app/'));

    // Second device: same person, new browser id; the journeys join and no second lead is made.
    $other = 'vid_'.str_repeat('b', 20);
    $this->call('POST', $this->web.'/_t', [], [], [], ['CONTENT_TYPE' => 'text/plain'], (string) json_encode(['vid' => $other, 'events' => [['type' => 'listing', 'listing' => vjUlid(4)]]]));
    Mail::fake();
    vjSignIn($this, 'jane@x.test', $other);
    expect(Lead::query()->count())->toBe(1)->and(SiteVisitor::query()->where('vid', $other)->value('merged_into'))->toBe($visitor->id)
        ->and(SiteEvent::query()->where('visitor_id', $visitor->id)->where('listing_ulid', vjUlid(4))->exists())->toBeTrue();
});

it('keeps saved homes and saved searches on the account', function (): void {
    $me = vjSignIn($this);
    $auth = ['Authorization' => 'Bearer '.$me['token']];
    $this->postJson($this->web.'/_me/favourites', ['listing' => vjUlid(1), 'on' => true])->assertUnauthorized();
    $this->postJson($this->web.'/_me/favourites', ['listing' => vjUlid(1), 'on' => true], $auth)->assertOk()->assertJson(['favourites' => [vjUlid(1)]]);
    $this->postJson($this->web.'/_me/favourites', ['listing' => vjUlid(2), 'on' => true], $auth)->assertOk();
    $this->postJson($this->web.'/_me/favourites', ['listing' => vjUlid(1), 'on' => false], $auth)->assertOk()->assertJson(['favourites' => [vjUlid(2)]]);
    $this->getJson($this->web.'/_me', $auth)->assertOk()->assertJson(['name' => 'Jane Doe', 'email' => 'jane@x.test', 'favourites' => [vjUlid(2)]]);
    $this->getJson($this->web.'/_me', ['Authorization' => 'Bearer forged'])->assertUnauthorized();

    $this->postJson($this->web.'/_me/searches', ['query' => 'q=78704&max_price=600000'], $auth)->assertOk();
    $search = SavedSearch::query()->sole();
    expect($search->frequency)->toBe('daily')->and($search->user_id)->toBe($this->ann->id)->and($search->filters['max_price'])->toBe(600000);
    $this->postJson($this->web.'/_me/searches', ['query' => 'sort=newest'], $auth)->assertUnprocessable();
});

it('tells the agent when a known visitor looks ready to act, once per home per week', function (): void {
    vjSignIn($this);
    Notification::fake();
    $home = vjUlid(5);
    vjBeacon($this, [['type' => 'listing', 'listing' => $home], ['type' => 'listing', 'listing' => $home]]);
    Notification::assertNothingSent();
    vjBeacon($this, [['type' => 'listing', 'listing' => $home]]);
    Notification::assertSentToTimes($this->ann, LeadAlert::class, 1);
    $sent = Notification::sent($this->ann, LeadAlert::class)->first();
    expect($sent->subject)->toBe('Ready to act: Jane Doe')->and($sent->line)->toContain('keeps coming back to 105 Elm St, Austin ($525,000)');
    vjBeacon($this, [['type' => 'listing', 'listing' => $home]]);
    Notification::assertSentToTimes($this->ann, LeadAlert::class, 1);

    // Reactive follow-up (SO2): the agent also gets a task for today that says why, once a day.
    expect(Task::query()->where('assigned_to', $this->ann->id)->where('title', 'like', 'Reach out now:%')->pluck('title')->all())
        ->toBe(['Reach out now: Jane Doe keeps coming back to 105 Elm St, Austin on your website']);

    vjBeacon($this, [['type' => 'mortgage', 'listing' => vjUlid(6), 'down_pct' => '10', 'rate' => '6.25']]);
    Notification::assertSentToTimes($this->ann, LeadAlert::class, 2);
    expect(Task::query()->where('title', 'like', 'Reach out now:%')->count())->toBe(1);
    expect(SiteEvent::query()->where('type', 'mortgage')->sole()->data)->toMatchArray(['down_pct' => 10, 'rate' => 6.25]);
});

it('shows the journey on the CRM lead, only to people who may see the contact', function (): void {
    vjBeacon($this, [['type' => 'listing', 'listing' => vjUlid(1)], ['type' => 'compare', 'listings' => [vjUlid(1), vjUlid(2)]]]);
    $form = ['name' => 'Sam Buyer', 'email' => 'sam@x.test', 'message' => 'Hi', 'listing' => vjUlid(1), 'vid' => $this->vid, 'return' => '/homes',
        't' => Crypt::encryptString((string) (time() - 30))];
    $this->post($this->web.'/_lead', $form)->assertRedirect();
    $lead = Lead::query()->sole();

    $page = $this->actingAs($this->owner)->get('/leads')->assertOk();
    $page->assertInertia(fn (Assert $p) => $p->where('leads.0.journey.homes_viewed', 1)->where('leads.0.journey.visits', 1)
        ->where('leads.0.journey.summary', fn ($s) => str_contains((string) $s, 'Compared 101 Elm St, Austin vs 102 Elm St, Austin')));
    $journey = $this->actingAs($this->owner)->getJson('/leads/'.$lead->ulid.'/journey')->assertOk()->json('journey');
    expect(array_column($journey['events'], 'text'))->toContain('Viewed 101 Elm St, Austin ($425,000)')->toContain('Compared 101 Elm St, Austin vs 102 Elm St, Austin')
        ->toContain('Sent an enquiry about 101 Elm St Austin TX 78704')
        ->and($journey['events'][0]['url'])->toStartWith('https://acme.rightally.site/homes/'.vjUlid(1));

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson('/leads/'.$lead->ulid.'/journey')->assertNotFound();
});

it('writes the summary with AI when the brokerage has AI, and falls back to the written one otherwise', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => JourneySummary::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.site_journey']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond((string) json_encode(['summary' => 'Wants a 3-bed in 78704 under $500k and keeps returning to 101 Elm St.', 'next_step' => 'Offer a showing of 101 Elm St this weekend.']));

    vjBeacon($this, [['type' => 'listing', 'listing' => vjUlid(1)]]);
    vjSignIn($this);
    $v = SiteVisitor::query()->sole();
    expect($v->summary_ai)->toBeTrue()->and($v->summary)->toBe("Wants a 3-bed in 78704 under \$500k and keeps returning to 101 Elm St.\nNext step: Offer a showing of 101 Elm St this weekend.")
        ->and($ai->requests)->toHaveCount(1);
    expect(json_encode($ai->requests))->not->toContain('jane@x.test'); // no personal details sent
});

it('gives home owners a price range from comparable homes and sends the agent a seller lead', function (): void {
    $html = (string) $this->get($this->web.'/home-value')->assertOk()->getContent();
    expect($html)->toContain('What is my home worth?')->toContain('content="index, follow, max-image-preview:large"')->toContain('"@type":"FAQPage"')->toContain('href="/sell"'); // TP2: the Sell page leads to it

    $form = ['address' => '12 Oak Ln', 'zip' => '78704', 'beds' => 3, 'sqft' => 1750, 'name' => 'Sue Seller', 'email' => 'sue@x.test', 'vid' => $this->vid,
        't' => Crypt::encryptString((string) (time() - 30))];
    $res = $this->post($this->web.'/_valuation', $form);
    $res->assertStatus(303);
    $location = (string) $res->headers->get('Location');
    expect($location)->toStartWith($this->web.'/home-value?r=');
    $lead = Lead::query()->sole();
    // Six comps from $270/sq ft to $288/sq ft (25th–75th percentile) x 1,750 sq ft.
    expect($lead->intent)->toBe('sell')->and($lead->property)->toBe('12 Oak Ln 78704')->and($lead->zip)->toBe('78704')
        ->and($lead->message)->toStartWith('Home value request for 12 Oak Ln 78704 (3 bd, 1,750 sq ft). Website estimate: $')
        ->and($lead->price_min)->toBeGreaterThan(450000)->toBeLessThan(510000);

    $result = (string) $this->get(str_replace($this->web, $this->web, $location))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($result)->toContain('Based on 6 similar homes for sale near 12 Oak Ln')->toContain('Similar homes for sale nearby')->toContain('content="noindex, follow"');
    expect(SiteEvent::query()->where('type', 'valuation')->sole()->data['address'])->toBe('12 Oak Ln 78704');

    $this->post($this->web.'/_valuation', [...$form, 'zip' => '99999', 'email' => 'far@x.test'])->assertStatus(303);
    expect(Lead::query()->latest('id')->first()->message)->toContain('Not enough similar homes for sale to estimate');
    $this->post($this->web.'/_valuation', [...$form, 'zip' => 'abc'])->assertRedirect($this->web.'/home-value?error='.urlencode('Enter your street address and 5-digit ZIP code.').'#value');
});

it('compares homes side by side and lets the brokerage set the sign-up prompt', function (): void {
    $html = (string) $this->get($this->web.'/homes/compare?ids='.vjUlid(1).','.vjUlid(6))->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->getContent();
    expect($html)->toContain('101 Elm St')->toContain('106 Elm St')->toContain('<td class="best">$425,000</td>')->toContain('<td class="best">1,900</td>');
    $this->get($this->web.'/homes/compare')->assertOk()->assertSee('Choose');

    expect((string) $this->get($this->web.'/')->getContent())->toContain('data-signup-after="3"')->toContain('/site-assets/visitor.js')->toContain('Global Privacy Control');
    $this->actingAs($this->owner)->patch('/settings/website', ['settings' => ['signup_after' => 0]])->assertSessionHasNoErrors();
    expect((string) $this->get($this->web.'/')->getContent())->toContain('data-signup-after="0"');
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->where('site.signup_after', 0));
});

it('lets a visitor ask for texts about a saved search, recording their number and the exact consent words (IDX10)', function (): void {
    $me = vjSignIn($this);
    $auth = ['Authorization' => 'Bearer '.$me['token']];
    $name = $this->postJson($this->web.'/_me/searches', ['query' => 'q=78704&max_price=600000&pool=1'], $auth)->assertOk()->json('name');
    expect(SavedSearch::query()->sole()->filters['pool'])->toBeTrue();

    $this->postJson($this->web.'/_me/searches/text', ['name' => $name, 'phone' => '512 555 0199'], $auth)->assertUnprocessable(); // the box must be ticked
    $this->postJson($this->web.'/_me/searches/text', ['name' => $name, 'phone' => '12', 'agree' => true], $auth)->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->postJson($this->web.'/_me/searches/text', ['name' => $name, 'phone' => '(512) 555-0199', 'agree' => true], $auth)->assertOk();

    $search = SavedSearch::query()->sole();
    $contact = $search->contact;
    expect($search->text_alerts)->toBeTrue()->and($contact->primary_phone)->toBe('+15125550199')->and($contact->sms_consent_method)->toBe('web_form')
        ->and($contact->sms_consent_text)->toContain('Reply STOP to stop')->and(app(Consent::class)->canAutoText($contact))->toBeTrue();
    $this->postJson($this->web.'/_me/searches/text', ['name' => 'Not mine', 'phone' => '5125550199', 'agree' => true], $auth)->assertNotFound();
});
