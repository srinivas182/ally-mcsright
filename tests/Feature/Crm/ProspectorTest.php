<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Support\Connections;
use Modules\Crm\Consent;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Prospector\DncRegistry;
use Modules\Crm\Prospector\Prospector;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;

/* LQ2: Lead-IQ Prospector — expired and withdrawn listings, for-sale-by-owner homes, and the Do Not Call Registry. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    foreach ([1 => 'Expired', 2 => 'Withdrawn', 3 => 'Expired', 4 => 'Active', 5 => 'Expired'] as $i => $status) {
        Listing::query()->create(['mls_source_id' => $this->source->id, 'listing_key' => 'K'.$i, 'standard_status' => $status, 'street_number' => (string) $i, 'street_name' => 'Oak St',
            'city' => 'Austin', 'state_or_province' => 'TX', 'postal_code' => $i === 5 ? '78610' : '78704', 'list_price_cents' => 45_000_000, 'original_list_price_cents' => 48_000_000,
            'days_on_market' => 120, 'modification_timestamp' => CarbonImmutable::now()->subDays(2)]);
    }
});

function prospectorLicence(object $t, array $scopes): void
{
    $auth = MlsAuthorization::query()->create(['tenant_id' => $t->tenant->id, 'mls_source_id' => $t->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, $scopes, null, null, User::factory()->hqStaff()->make());
}

function prospectorRegistryFile(string $lines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('512.txt', $lines);
}

it('finds expired and withdrawn listings only with a back-office licence, in the chosen areas, a few a day per agent', function (): void {
    $p = app(Prospector::class);
    $p->saveSettings(['agent_ids' => [$this->ann->id, $this->bob->id], 'per_agent_daily' => 1, 'zips' => '78704'], $this->owner);
    prospectorLicence($this, ['idx']);
    expect($p->find())->toBe(0); // IDX isn't for prospecting

    MlsAuthorization::query()->delete();
    prospectorLicence($this, ['idx', 'back_office']);
    expect($p->find())->toBe(2); // one each today; 78610 isn't watched, Active isn't off the market
    $rows = DB::connection(Connections::cell())->table('crm_prospects')->orderBy('id')->get();
    expect($rows->pluck('assigned_to')->sort()->values()->all())->toBe([$this->ann->id, $this->bob->id])
        ->and($rows[0]->reason)->toBe('Expired 2 days ago after 120 days on the market at $450,000 (cut from $480,000).');

    $this->travel(1)->days();
    expect($p->find())->toBe(1)->and($p->find())->toBe(0); // the last one in 78704; nothing found twice

    $this->actingAs($this->ann)->get('/prospector')->assertInertia(fn (Assert $page) => $page->component('crm/prospector')->where('broker', false)->where('feed', true)
        ->where('prospects', fn ($list): bool => collect($list)->every(fn ($x): bool => $x['mine'])));
    expect(collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'prospects'))->not->toBeNull();
});

it('keeps the brokerage\'s Do Not Call Registry list, 31 days fresh, by area code, and only hashes', function (): void {
    $this->actingAs($this->ann)->post('/prospector/registry', ['file' => prospectorRegistryFile("5125550101\n512,5550102\n"), 'downloaded_on' => '2026-05-30'])->assertForbidden();
    $this->actingAs($this->owner)->post('/prospector/registry', ['file' => prospectorRegistryFile("5125550101\n512,5550102\n"), 'downloaded_on' => '2026-04-01'])->assertSessionHasErrors('downloaded_on');
    $this->actingAs($this->owner)->post('/prospector/registry', ['file' => prospectorRegistryFile("5125550101\n512,5550102\n5125550101\n"), 'downloaded_on' => '2026-05-30'])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('crm_dnc_numbers')->count())->toBe(2)
        ->and(DB::connection(Connections::cell())->table('crm_dnc_numbers')->pluck('number_hash')->implode(''))->not->toContain('5550101');

    $dnc = app(DncRegistry::class);
    expect($dnc->status('+15125550101'))->toBe(DncRegistry::LISTED)->and($dnc->status('(512) 555-0199'))->toBe(DncRegistry::CLEAR)
        ->and($dnc->status('+17375550199'))->toBe(DncRegistry::NOT_COVERED);
    Contact::query()->create(['first_name' => 'Sam', 'emails' => [], 'phones' => [['e164' => '+15125550177', 'primary' => true]], 'origin' => 'personal', 'source' => 'other',
        'lifecycle' => 'lead'])->forceFill(['do_not_call' => true])->save();
    expect($dnc->status('+15125550177'))->toBe(DncRegistry::INTERNAL);
    $this->travel(32)->days();
    expect($dnc->status('+15125550199'))->toBe(DncRegistry::STALE);
});

it('lets the agent add a for-sale-by-owner home and a phone they found, checks it, and calls only when clear', function (): void {
    app(FollowUpPlans::class)->plansFor($this->ann); // the ready-made plans
    $this->actingAs($this->owner)->post('/prospector/registry', ['file' => prospectorRegistryFile("5125550101\n"), 'downloaded_on' => '2026-05-31']);
    $this->actingAs($this->ann)->post('/prospector/fsbo', ['street' => '9 Elm St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704', 'note' => 'Yard sign on Elm'])->assertRedirect();
    $this->actingAs($this->ann)->post('/prospector/fsbo', ['street' => '11 Elm St', 'zip' => '78704'])->assertRedirect();
    [$clear, $listed] = DB::connection(Connections::cell())->table('crm_prospects')->orderBy('id')->pluck('ulid')->all();

    $this->actingAs($this->bob)->post("/prospector/{$clear}/phone", ['phone' => '512-555-0199'])->assertForbidden(); // not Bob's
    $this->actingAs($this->ann)->post("/prospector/{$clear}/phone", ['phone' => '512-555-0199'])->assertRedirect();
    expect(collect(app(Prospector::class)->list($this->ann))->firstWhere('ulid', $clear)['dnc_words'])->toBe('Clear to call: not on the Do Not Call Registry.');
    $this->actingAs($this->ann)->post("/prospector/{$listed}/phone", ['phone' => '512-555-0101']);

    $this->actingAs($this->ann)->post("/prospector/{$clear}/contact")->assertRedirect();
    $a = Contact::query()->where('primary_phone', '+15125550199')->sole();
    expect($a->owner_user_id)->toBe($this->ann->id)->and($a->tags()->pluck('name')->all())->toBe(['Prospect'])
        ->and(PlanEnrollment::query()->where('contact_id', $a->id)->where('status', 'active')->exists())->toBeTrue()
        ->and(app(Consent::class)->canCall($a))->toBeTrue();

    $this->actingAs($this->ann)->post("/prospector/{$listed}/contact");
    $b = Contact::query()->where('primary_phone', '+15125550101')->sole();
    expect($b->tags()->pluck('name')->sort()->values()->all())->toBe(['Mail only', 'Prospect'])
        ->and(PlanEnrollment::query()->where('contact_id', $b->id)->exists())->toBeFalse()
        ->and(app(Consent::class)->canCall($b))->toBeFalse(); // on the registry, and never answered

    // Once they call back, there's a relationship: the agent may call them.
    DB::connection(Connections::cell())->table('crm_activities')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $this->tenant->id, 'contact_id' => $b->id, 'type' => 'call',
        'direction' => 'in', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(Consent::class)->canCall($b))->toBeTrue();
});

it('lets the broker choose who gets prospects and see everyone\'s', function (): void {
    $this->actingAs($this->ann)->post('/prospector/settings', ['agent_ids' => [$this->ann->id]])->assertForbidden();
    $this->actingAs($this->owner)->post('/prospector/settings', ['agent_ids' => [$this->ann->id, 999], 'per_agent_daily' => 3, 'zips' => '78704, 78745 nonsense'])->assertRedirect();
    expect(app(Prospector::class)->settings())->toBe(['agent_ids' => [$this->ann->id], 'per_agent_daily' => 3, 'zips' => ['78704', '78745']]);
    $this->actingAs($this->ann)->post('/prospector/fsbo', ['street' => '9 Elm St']);
    $this->actingAs($this->owner)->get('/prospector')->assertInertia(fn (Assert $page) => $page->where('broker', true)->where('feed', false)
        ->where('prospects.0.agent', 'Ann')->where('settings.per_agent_daily', 3));
    $ulid = (string) DB::connection(Connections::cell())->table('crm_prospects')->value('ulid');
    $this->actingAs($this->ann)->post("/prospector/{$ulid}/dismiss");
    $this->actingAs($this->ann)->get('/prospector')->assertInertia(fn (Assert $page) => $page->where('prospects', []));
});
