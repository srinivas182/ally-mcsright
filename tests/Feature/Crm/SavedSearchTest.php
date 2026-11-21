<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\SavedSearch;
use Modules\Crm\Searches\SavedSearches;
use Modules\Crm\Searches\SearchAlertMail;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;

/* Sprint 17B-2: saved searches with new-listing and price-drop alerts, one-click unsubscribe, send to client. */

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // Monday, 10:00 in Austin
    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->feed->records = [listing(1), listing(2), listing(3, ['City' => 'Round Rock'])];
    app(ListingSync::class)->run($this->source);
    $this->tenant = $this->onTenantHost('acme');
    $auth = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $this->jane = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.test'], $this->ann);
});

function listing(int $i, array $over = []): array
{
    return $over + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 400000 + $i * 10000,
        'OriginalListPrice' => 400000 + $i * 10000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800, 'StreetNumber' => (string) $i, 'StreetName' => 'Oak St',
        'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-20',
        'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'];
}

it('emails new listings and price drops for a saved search once, at 8am, and never repeats them', function (): void {
    $this->actingAs($this->ann)->post("/contacts/{$this->jane->ulid}/searches", ['q' => 'Austin', 'max_price' => 500000, 'frequency' => 'daily'])->assertSessionHasNoErrors();
    $search = SavedSearch::query()->sole();
    expect($search->name)->toBe('Austin, under $500,000')->and($search->filters['q'])->toBe('Austin');

    // Later that day: a new Austin home, a price drop on K1, and a new Round Rock home (not in the search).
    $this->travel(2)->hours();
    $this->feed->records = [listing(1, ['ListPrice' => 395000, 'ModificationTimestamp' => '2026-06-01T16:00:00Z']), listing(4, ['ModificationTimestamp' => '2026-06-01T16:01:00Z']),
        listing(5, ['City' => 'Round Rock', 'ModificationTimestamp' => '2026-06-01T16:02:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());

    $this->artisan('crm:search-alerts')->assertSuccessful(); // 12:00 today: daily alerts already ran at 8am
    Mail::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-06-02 13:05', 'UTC')); // 8:05am next day
    $this->artisan('crm:search-alerts')->assertSuccessful();
    Mail::assertSent(SearchAlertMail::class, function (SearchAlertMail $m): bool {
        $kinds = array_column($m->listings, 'kind', 'mls_number');

        return $m->hasTo('jane@x.test') && $kinds === ['MLS4' => 'new', 'MLS1' => 'price_drop'] && $m->envelope()->subject === '1 new home for Austin, under $500,000 (+1 price drop)'
            && $m->agent === 'Ann Agent' && str_contains($m->stopUrl, '/alerts/') && $m->listings[0]['attribution'] === 'Listing courtesy of Acme Realty';
    });
    Mail::assertSentCount(1);
    expect($search->refresh()->sent_count)->toBe(1);

    // Tomorrow: nothing new, nothing sent.
    $this->travelTo(CarbonImmutable::parse('2026-06-03 13:05', 'UTC'));
    $this->artisan('crm:search-alerts');
    Mail::assertSentCount(1);
});

it('stops alerts with one click, and never emails people who opted out of email', function (): void {
    $searches = app(SavedSearches::class);
    $search = $searches->create($this->jane, ['q' => 'Austin'], '', 'instant', $this->ann);
    $url = $searches->stopUrl($search);
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($path)->assertOk()->assertSee('Stop these alerts?')->assertHeader('X-Robots-Tag', 'noindex');
    expect($search->refresh()->unsubscribed_at)->toBeNull(); // a link scanner opening it changes nothing
    $this->post($path)->assertOk()->assertSee("You're unsubscribed", false);
    expect($search->refresh()->frequency)->toBe('off')->and($search->unsubscribed_at)->not->toBeNull();
    $this->get('/alerts/'.$search->ulid.'/stop')->assertForbidden();

    $other = $searches->create($this->jane, ['q' => 'Austin'], 'Second', 'instant', $this->ann);
    app(Consent::class)->setEmail($this->jane, false, 'unsubscribe_link');
    $this->travel(20)->minutes();
    $this->feed->records = [listing(6, ['ModificationTimestamp' => '2026-06-01T15:30:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    $this->travel(20)->minutes();
    $this->artisan('crm:search-alerts');
    Mail::assertNothingSent();
    expect($other->refresh()->last_checked_at)->not->toBeNull();
});

it('sends instant alerts within 15 minutes', function (): void {
    app(SavedSearches::class)->create($this->jane, ['q' => 'Austin'], '', 'instant', $this->ann);
    $this->travel(5)->minutes();
    $this->feed->records = [listing(7, ['ModificationTimestamp' => '2026-06-01T15:05:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    $this->artisan('crm:search-alerts');
    Mail::assertNothingSent(); // checked less than 15 minutes ago
    $this->travel(11)->minutes();
    $this->artisan('crm:search-alerts');
    Mail::assertSent(SearchAlertMail::class, fn (SearchAlertMail $m): bool => array_column($m->listings, 'mls_number') === ['MLS7']);
});

it('checks what a saved search needs and who may set it up', function (): void {
    $this->actingAs($this->ann)->post("/contacts/{$this->jane->ulid}/searches", ['frequency' => 'daily'])->assertSessionHasErrors('q');
    $noEmail = app(ManageContacts::class)->create(['first_name' => 'Sam', 'phone' => '5125550100'], $this->ann);
    $this->actingAs($this->ann)->post("/contacts/{$noEmail->ulid}/searches", ['q' => 'Austin', 'frequency' => 'daily'])->assertSessionHasErrors('frequency');
    $this->actingAs($this->ann)->post("/contacts/{$noEmail->ulid}/searches", ['q' => 'Austin', 'frequency' => 'off'])->assertSessionHasNoErrors();
    $this->actingAs(User::factory()->create())->post("/contacts/{$this->jane->ulid}/searches", ['q' => 'Austin', 'frequency' => 'daily'])->assertNotFound(); // SX1: another agent's contact is not found

    $this->actingAs($this->ann)->get("/contacts/{$this->jane->ulid}")->assertInertia(fn (Assert $page) => $page->has('savedSearches', 0));
    $s = SavedSearch::query()->sole();
    $this->actingAs($this->ann)->patch("/searches/{$s->ulid}", ['frequency' => 'weekly'])->assertSessionHasNoErrors();
    expect($s->refresh()->frequency)->toBe('weekly');
});

it('sends a listing to a client as a one-to-one email through the inbox', function (): void {
    $ulid = Listing::query()->where('listing_key', 'K2')->value('ulid');
    $this->actingAs($this->ann)->post("/contacts/{$this->jane->ulid}/send-listing", ['listing' => $ulid, 'note' => 'Thought of you for this one!'])->assertSessionHasNoErrors();
    Mail::assertSent(MessageMail::class, fn (MessageMail $m): bool => $m->subjectLine === 'A home for you: 2 Oak St, Austin, TX'
        && str_starts_with($m->text, "Thought of you for this one!\n\n2 Oak St, Austin, TX\n\$420,000 · 3 bd · 2 ba · 1,800 sq ft") && str_contains($m->text, "Listing courtesy of Acme Realty\n\n--\n"));
    expect(Message::query()->sole()->channel)->toBe('email');
    $this->actingAs($this->ann)->post("/contacts/{$this->jane->ulid}/send-listing", ['listing' => str_repeat('0', 26)])->assertSessionHasErrors('listing');
});

it('texts new homes for a search that asked for texts: written consent only, 8am to 9pm, at most three a day (IDX10)', function (): void {
    app()->instance(SmsSender::class, $sms = new FakeSmsSender);
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'tw-token'])),
        'sms_from' => '+15125550000', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true,
        'voice' => false, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    $this->jane->forceFill(['phones' => [['e164' => '+15125550199', 'label' => 'mobile', 'primary' => true, 'can_text' => true]]])->save();
    $search = app(SavedSearches::class)->createForVisitor($this->jane, ['q' => 'Austin'], '', $this->ann->id);
    $search->forceFill(['text_alerts' => true, 'last_texted_at' => now()])->save();

    // No written consent yet: no text.
    $this->travel(61)->minutes();
    $this->feed->records = [listing(6, ['ModificationTimestamp' => '2026-06-01T16:10:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    expect(app(SavedSearches::class)->sendTexts())->toBe(0);

    app(Consent::class)->optInSms($this->jane->refresh(), 'web_form', SavedSearches::TEXT_CONSENT);
    expect(app(SavedSearches::class)->sendTexts())->toBe(1)
        ->and($sms->sent[0]['to'])->toBe('+15125550199')->and($sms->sent[0]['message'])->toStartWith($this->tenant->name.': new home for "Austin": 3 bd, 2 ba, $460,000 in 6 Oak St, Austin.')
        ->not->toContain('more)')->toContain('Reply STOP');
    expect(app(SavedSearches::class)->sendTexts())->toBe(0); // the same home is never texted twice

    // Late at night where Jane is (10:30pm in Austin): it waits for the morning.
    $this->travelTo(CarbonImmutable::parse('2026-06-02 03:30', 'UTC'));
    $this->feed->records = [listing(7, ['ModificationTimestamp' => '2026-06-02T03:20:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    expect(app(SavedSearches::class)->sendTexts())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 14:00', 'UTC'));
    expect(app(SavedSearches::class)->sendTexts())->toBe(1)->and(count($sms->sent))->toBe(2);

    // An opt-out (STOP) ends texts.
    app(Consent::class)->optOutSms($this->jane->refresh());
    $this->travel(2)->hours();
    $this->feed->records = [listing(8, ['ModificationTimestamp' => '2026-06-02T15:00:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    expect(app(SavedSearches::class)->sendTexts())->toBe(0);
});
