<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Models\Task;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Sync\ListingSync;
use Modules\Outreach\Models\CardRequest;
use Modules\Sites\Models\HomeReport;
use Modules\Sites\SiteSetup;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* MK2 (specs/crm/21): email signature, monthly content pack, homeowners dashboard, handwritten cards. */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Agent', 'phone' => '+15125550123']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['phone' => '(512) 555-0100', 'area' => 'Austin'], $this->owner), true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information deemed reliable but not guaranteed.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 25000, 'OriginalListPrice' => 400000 + $i * 25000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800 + $i * 50,
        'StreetNumber' => (string) (100 + $i), 'StreetName' => 'Elm St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75,
        'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'DaysOnMarket' => 10 + $i,
        'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 6));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

it('signs every RightAlly email with the brand kit signature and offers it for Gmail and Outlook', function (): void {
    Mail::fake();
    assertPageContract($this->actingAs($this->agent)->get('/outreach/signature'), 'outreach/signature');
    $this->actingAs($this->agent)->get('/outreach/signature')->assertInertia(fn (Assert $p) => $p->where('text', fn ($t) => str_contains((string) $t, 'Pat Agent')
        && str_contains((string) $t, $this->tenant->name))->where('html', fn ($h) => str_contains((string) $h, '<table') && str_contains((string) $h, 'tel:+15125550123')));

    $jane = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.test'], $this->agent);
    $c = app(Inbox::class)->conversationFor($jane);
    $this->actingAs($this->agent)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'subject' => 'Hello', 'body' => 'Hi Jane.'])->assertSessionHasNoErrors();
    Mail::assertSent(MessageMail::class, fn (MessageMail $m): bool => str_contains($m->text, "Hi Jane.\n\n--\nPat Agent"));
});

it('writes a monthly content pack from real market numbers, in English and Spanish', function (): void {
    assertPageContract($this->actingAs($this->agent)->get('/outreach/content?place=78704'), 'outreach/content');
    $this->actingAs($this->agent)->get('/outreach/content?place=78704')->assertInertia(fn (Assert $p) => $p
        ->where('pack.stats.active', 6)
        ->where('pack.posts.en.0', fn ($t) => str_contains((string) $t, '6 homes for sale') && str_contains((string) $t, 'Source: MLS'))
        ->where('pack.posts.es.0', fn ($t) => str_contains((string) $t, 'casas en venta'))
        ->where('pack.newsletter.en.subject', fn ($s) => str_contains((string) $s, 'market update'))
        ->where('pack.newsletter.es.body', fn ($b) => str_contains((string) $b, 'Hola {first_name}')));
    $this->actingAs($this->agent)->get('/outreach/content?place=99999')->assertInertia(fn (Assert $p) => $p->where('pack', null));
});

it('starts home-value reports for past clients and gives homeowners a private dashboard', function (): void {
    $m = app(ManageContacts::class);
    $past = $m->create(['first_name' => 'Pam', 'last_name' => 'Past', 'email' => 'pam@x.test'], $this->agent);
    $m->update($past, ['lifecycle' => 'past_client', 'address_line1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704'], $this->agent);
    $m->create(['first_name' => 'Lee', 'last_name' => 'Lead', 'email' => 'lee@x.test'], $this->agent); // not a past client

    assertPageContract($this->actingAs($this->agent)->get('/outreach/homeowners'), 'outreach/homeowners');
    $this->actingAs($this->agent)->get('/outreach/homeowners')->assertInertia(fn (Assert $p) => $p->where('waiting', 1));
    $this->actingAs($this->agent)->post('/outreach/homeowners')->assertSessionHasNoErrors();
    $report = HomeReport::query()->sole();
    expect($report->contact_id)->toBe($past->id)->and($report->address)->toBe('12 Oak St');
    $this->actingAs($this->agent)->get('/outreach/homeowners')->assertInertia(fn (Assert $p) => $p->where('waiting', 0)->where('reports.0.contact', 'Pam Past'));

    $url = URL::signedRoute('sites.home-reports.dashboard', ['report' => $report->ulid], null, false);
    $html = (string) $this->get('http://acme.rightally.app'.$url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($html)->toContain('12 Oak St')->toContain('Likely worth today')->toContain('Ask for a market analysis')->toContain('we don\'t send or keep it');
    $this->get('http://acme.rightally.app/my-home/'.$report->ulid)->assertForbidden();

    $this->post('http://acme.rightally.app'.$url)->assertRedirect();
    expect(Task::query()->where('assigned_to', $this->agent->id)->where('title', 'like', 'Homeowner asked for a market analysis%')->count())->toBe(1);
    expect((string) $this->get('http://acme.rightally.app'.$url.'&asked=1')->assertOk()->getContent())->toContain('Thank you.');
    expect($report->refresh()->dashboard_viewed_at)->not->toBeNull();
});

it('queues handwritten cards for the partner and lets HQ export and mark them mailed', function (): void {
    $m = app(ManageContacts::class);
    $nobody = $m->create(['first_name' => 'No', 'last_name' => 'Address', 'email' => 'no@x.test'], $this->agent);
    $kim = $m->create(['first_name' => 'Kim', 'last_name' => 'Client', 'email' => 'kim@x.test'], $this->agent);
    $m->update($kim, ['address_line1' => '5 Pine Rd', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'], $this->agent);

    assertPageContract($this->actingAs($this->agent)->get('/outreach/cards?contact='.$kim->ulid), 'outreach/cards');
    $this->actingAs($this->agent)->post('/outreach/cards', ['contact' => $nobody->ulid, 'occasion' => 'thank_you', 'message' => 'Thank you for everything!'])->assertSessionHasErrors('contact');
    $this->actingAs($this->agent)->post('/outreach/cards', ['contact' => $kim->ulid, 'occasion' => 'thank_you', 'message' => 'Hi'])->assertSessionHasErrors('message');
    $this->actingAs($this->agent)->post('/outreach/cards', ['contact' => $kim->ulid, 'occasion' => 'home_anniversary', 'message' => '=Happy one year in your home, Kim!'])->assertSessionHasNoErrors();
    $card = CardRequest::query()->sole();
    expect($card->to_line1)->toBe('5 Pine Rd')->and($card->status)->toBe('queued');

    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->actingAs($admin)->get('http://app.rightally.io/hq/cards')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/cards')->where('queued.0.to', 'Kim Client'));
    $csv = $this->actingAs($admin)->get('http://app.rightally.io/hq/cards/export.csv')->assertOk()->streamedContent();
    expect($csv)->toContain("'=Happy one year")->toContain('5 Pine Rd');
    $this->actingAs($admin)->post('http://app.rightally.io/hq/cards/sent', ['ids' => [$card->id]])->assertRedirect();
    expect($card->refresh()->status)->toBe('sent');
    $this->actingAs($this->agent)->get('/outreach/cards')->assertInertia(fn (Assert $p) => $p->where('requests.0.status', 'sent'));
});
