<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Health\ContactChecks;
use Modules\Crm\Health\DatabaseHealth;
use Modules\Crm\Models\Contact;
use Modules\Learn\MarketQuiz;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Roster\Roster;
use Modules\Roster\Verification;
use Modules\Roster\Verification\ServiceVerifier;
use Modules\Sites\Funnels\MovedFlags;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
| LN4 (E24, E20): email and phone checks, the phone network's name as a suggestion, "may have moved" flags from MLS
| sales, licence checks from state public data; local market quizzes, accountability pods and live call cards.
*/

beforeEach(function (): void {
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->m = app(ManageContacts::class);
});

function lnService(string $service, string $provider, array $keys): void
{
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => $service, 'provider' => $provider, 'mode' => 'live',
        'test_keys' => Crypt::encryptString((string) json_encode($keys)), 'live_keys' => Crypt::encryptString((string) json_encode($keys)), 'test_contacts' => '',
        'created_at' => now(), 'updated_at' => now()]);
}

function lnMls(object $t): void
{
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $home = fn (int $i, array $o = []): array => $o + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 10000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800 + $i * 10, 'StreetNumber' => (string) (100 + $i), 'StreetName' => 'Elm St',
        'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty',
        'OnMarketDate' => '2026-05-10', 'DaysOnMarket' => 12, 'ModificationTimestamp' => sprintf('2026-05-20T00:%02d:00Z', $i)];
    $feed->records = [...array_map(fn (int $i): array => $home($i), range(1, 6)),
        $home(20, ['StreetNumber' => '12', 'StreetName' => 'North Oak Street', 'StandardStatus' => 'Closed', 'ClosePrice' => 455000, 'CloseDate' => '2026-04-20'])];
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $t->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
}

it('checks emails and phones through the chosen providers and shows what to fix', function (): void {
    $good = $this->m->create(['first_name' => 'Gia', 'email' => 'gia@example.test', 'phone' => '512-555-0101'], $this->agent);
    $bad = $this->m->create(['first_name' => 'Bo', 'email' => 'bo@nowhere.test', 'phone' => '512-555-0102'], $this->agent);
    $noname = $this->m->create(['first_name' => 'Tmp', 'phone' => '512-555-0103'], $this->agent);
    Contact::query()->whereKey($noname->id)->update(['first_name' => '', 'last_name' => '']); // a lead that came in with only a number
    expect(app(ContactChecks::class)->emails())->toBe(0); // not switched on

    lnService('email_check', 'zerobounce', ['api_key' => 'zb-key']);
    lnService('phone_check', 'twilio_lookup', ['sid' => 'AC1', 'token' => 'tok']);
    Http::fake([
        'api.zerobounce.net/v2/validate*' => fn (HttpRequest $r) => Http::response(['status' => str_contains($r->url(), 'nowhere') ? 'invalid' : 'valid']),
        'lookups.twilio.com/v2/PhoneNumbers/%2B15125550101*' => Http::response(['valid' => true, 'line_type_intelligence' => ['type' => 'mobile'], 'caller_name' => ['caller_name' => null]]),
        'lookups.twilio.com/v2/PhoneNumbers/%2B15125550102*' => Http::response(['valid' => true, 'line_type_intelligence' => ['type' => 'landline'], 'caller_name' => ['caller_name' => 'BO SMITH']]),
        'lookups.twilio.com/v2/PhoneNumbers/%2B15125550103*' => Http::response(['valid' => true, 'line_type_intelligence' => ['type' => 'nonFixedVoip'], 'caller_name' => ['caller_name' => 'MARIA GARCIA']]),
    ]);
    expect(app(ContactChecks::class)->emails())->toBe(2)->and(app(ContactChecks::class)->phones())->toBe(3)
        ->and(app(ContactChecks::class)->emails())->toBe(0); // each is checked once every six months
    expect($good->fresh()->email_status)->toBe('valid')->and($bad->fresh()->email_status)->toBe('invalid')->and($bad->fresh()->phone_type)->toBe('landline')
        ->and($bad->fresh()->caller_name)->toBeNull() // already has a name
        ->and($noname->fresh()->phone_type)->toBe('voip')->and($noname->fresh()->caller_name)->toBe('Maria Garcia');

    $health = collect(app(DatabaseHealth::class)->for($this->agent)['checks'])->keyBy('key');
    expect($health['bad_email']['count'])->toBe(1)->and($health['bad_email']['href'])->toBe('/contacts?list=bad-email');
    $this->actingAs($this->agent)->get('/contacts/'.$bad->ulid)->assertInertia(fn (Assert $p) => $p->where('checks.email', 'invalid')->where('checks.phone', 'landline'));

    $this->actingAs($this->agent)->post('/contacts/'.$noname->ulid.'/use-caller-name')->assertSessionHasNoErrors();
    expect($noname->fresh()->first_name)->toBe('Maria')->and($noname->fresh()->last_name)->toBe('Garcia')->and($noname->fresh()->caller_name)->toBeNull();
});

it('flags past clients whose home sold on the MLS as maybe moved', function (): void {
    lnMls($this);
    $pam = $this->m->create(['first_name' => 'Pam', 'last_name' => 'Past', 'email' => 'pam@x.test'], $this->agent);
    $this->m->update($pam, ['lifecycle' => 'past_client', 'address_line1' => '12 N. Oak St', 'zip' => '78704'], $this->agent);
    Contact::query()->whereKey($pam->id)->update(['closed_on' => '2019-05-01']);
    $lee = $this->m->create(['first_name' => 'Lee', 'email' => 'lee@x.test'], $this->agent);
    $this->m->update($lee, ['lifecycle' => 'past_client', 'address_line1' => '900 Other Rd', 'zip' => '78704'], $this->agent);

    expect(MovedFlags::key('12 N. Oak St', null, '78704'))->toBe('12|n oak st||78704')
        ->and(app(MovedFlags::class)->scan())->toBe(1)->and(app(MovedFlags::class)->scan())->toBe(0);
    expect($pam->fresh()->moved_on->toDateString())->toBe('2026-04-20')->and($pam->fresh()->moved_note)->toContain('sold on Apr 20, 2026')
        ->and($lee->fresh()->moved_on)->toBeNull();
    expect(collect(app(DatabaseHealth::class)->for(null)['checks'])->firstWhere('key', 'moved')['count'])->toBe(1);
    $this->actingAs($this->agent)->get('/contacts?list=may-have-moved')->assertOk();
});

it('checks licences against a state\'s public licence list once HQ loads it (on by default)', function (): void {
    $licence = app(Roster::class)->add($this->agent, 'licence', ['state_code' => 'TX', 'number' => '0612345', 'expires_on' => '2026-12-31'], $this->owner);
    expect(app(Verification::class)->available())->toBeTrue(); // LV2: state records by default until HQ sets licence look-up up
    lnService('licence_lookup', 'state_data', []);
    expect(app(Verification::class)->available())->toBeTrue()
        ->and(fn () => app(Verification::class)->check($licence))->toThrow(ValidationException::class, 'no state records for TX');

    $csv = "License Number,Last Name,First Name,License Status,Expiration Date\n612345,Lee,Pat,Active,12/31/2027\n700001,Smith,Jo,Suspended,01/31/2027\n";
    expect(ServiceVerifier::load('TX', $csv))->toBe(2);
    expect(app(Verification::class)->check($licence))->toBe('active');
    $row = DB::connection(Connections::cell())->table('roster_credentials')->where('ulid', $licence)->first();
    expect($row->verification_method)->toBe('state_data')->and(substr((string) $row->expires_on, 0, 10))->toBe('2027-12-31');
});

it('quizzes agents on their own market, keeps a streak, and runs pods and call cards', function (): void {
    lnMls($this);
    $quiz = app(MarketQuiz::class)->today('78704');
    expect($quiz['questions'])->toHaveCount(5)->and($quiz['questions'][0])->not->toHaveKey('answer')->and($quiz['questions'][0]['choices'])->toHaveCount(4);
    assertPageContract($this->actingAs($this->agent)->get('/learn/market?place=78704'), 'learn/market');

    $answers = ['active' => '6'];
    $this->actingAs($this->agent)->post('/learn/market', ['place' => '78704', 'answers' => $answers])->assertRedirect('/learn/market?place=78704');
    $this->actingAs($this->agent)->get('/learn/market?place=78704')->assertInertia(fn (Assert $p) => $p->where('result.score', 1)->where('result.total', 5)
        ->where('result.right.active', '6')->where('streak', 1));
    $this->travel(1)->days();
    app(MarketQuiz::class)->mark($this->agent, '78704', []);
    expect(app(MarketQuiz::class)->streak($this->agent))->toBe(2);

    $ann = User::factory()->create(['first_name' => 'Ann']);
    $outsider = User::factory()->create();
    assertPageContract($this->actingAs($this->agent)->get('/learn/pods'), 'learn/pods');
    $this->actingAs($this->agent)->post('/learn/pods', ['name' => 'Solo', 'members' => []])->assertSessionHasErrors('members');
    $this->actingAs($this->agent)->post('/learn/pods', ['name' => 'Tuesday pod', 'members' => [$ann->ulid]])->assertRedirect();
    $pod = (string) DB::connection(Connections::cell())->table('learn_pods')->value('ulid');
    $this->actingAs($ann)->post("/learn/pods/{$pod}/check-in", ['goal' => 'Call 20 past clients'])->assertSessionHasNoErrors();
    app(Timeline::class)->log($this->m->create(['first_name' => 'X', 'email' => 'x@x.test'], $ann), ['type' => 'call', 'outcome' => 'connected'], $ann);
    assertPageContract($this->actingAs($this->agent)->get("/learn/pods/{$pod}"), 'learn/pod');
    $this->actingAs($this->agent)->get("/learn/pods/{$pod}")->assertInertia(fn (Assert $p) => $p->where('pod.members.0.name', 'Ann '.$ann->last_name)
        ->where('pod.members.0.goal', 'Call 20 past clients')->where('pod.members.0.calls', 1));
    $this->actingAs($outsider)->get("/learn/pods/{$pod}")->assertNotFound();

    $this->actingAs($this->agent)->get('/outreach/calls')->assertInertia(fn (Assert $p) => $p->has('cards', 8)->where('cards.7.key', 'stop'));
});
