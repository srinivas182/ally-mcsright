<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Movewise\Alerts;
use Modules\Movewise\Mail\AlertDigest;
use Modules\Movewise\Mail\SignInCode;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\Person;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;
use Modules\Movewise\MovewiseAilly;
use Modules\Movewise\Tools\Affordability;
use Modules\Movewise\Tools\NetSheet;
use Tests\Fixtures\FakeAiProvider;

/* Movewise MW2: My Movewise (email-code sign-in, saved homes and searches, alerts with one-click unsubscribe, the
   move planner, download and delete), the calculators, AIlly, and Spanish pages under /es. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Mail::fake();
    config(['core.hq_host' => 'app.rightally.io', 'movewise.host' => 'movewisehomes.com', 'listings.cdn_url' => 'https://cdn.movewise.test']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    app(TenantContext::class)->forget();
    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $this->source = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->home = fn (string $key, int $price, string $city): array => ['ListingKey' => $key, 'ListingId' => 'M'.$key, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => $price, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1900, 'StreetNumber' => '12', 'StreetName' => 'Oak St', 'City' => $city, 'StateOrProvince' => 'TX',
        'PostalCode' => '77001', 'Latitude' => 29.7, 'Longitude' => -95.3, 'ListAgentMlsId' => 'A1', 'ListOfficeName' => 'Bayou Realty', 'ModificationTimestamp' => '2026-05-31T14:30:00Z'];
    $this->feed->records = [($this->home)('H1', 400000, 'Houston')];
    app(ListingSync::class)->run($this->source);
    $partner = Partner::query()->create(['name' => 'Lone Star Partners LLC', 'broker_name' => 'Pat Broker', 'licence_number' => '9001234', 'licence_state' => 'TX', 'contract_signed_on' => '2026-05-01', 'status' => 'active']);
    $auth = app(MlsAccess::class)->request($this->hqTenant->id, $this->source, 'B-LS1', [], 'Movewise TX', $this->staff);
    app(MlsAccess::class)->approve($auth, ['idx'], null, null, $this->staff);
    State::query()->findOrFail('TX')->forceFill(['partner_id' => $partner->id, 'checklist' => array_map(fn (): array => ['done' => true], Movewise::CHECKLIST)])->save();
    app(Movewise::class)->switchState(State::query()->findOrFail('TX'), true, $this->staff);
    $this->mw = 'https://movewisehomes.com';
    $this->from = ['Origin' => 'https://movewisehomes.com'];
    $this->withCredentials(); // JSON calls carry cookies, as the browser's same-origin fetch does
});

/** Signs in through the real email-code flow and returns the session cookie value. */
function mwSignIn(object $t, string $email = 'Buyer@Example.com'): string
{
    $t->postJson($t->mw.'/_auth/start', ['email' => $email], $t->from)->assertOk();
    $code = '';
    Mail::assertSent(SignInCode::class, function (SignInCode $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });
    $t->postJson($t->mw.'/_auth/verify', ['email' => $email, 'code' => '000000'], $t->from)->assertStatus(422); // wrong code
    $r = $t->postJson($t->mw.'/_auth/verify', ['email' => $email, 'code' => $code], $t->from)->assertOk();
    $cookie = collect($r->headers->getCookies())->firstWhere(fn ($c): bool => $c->getName() === 'mw_sid');
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');

    return (string) $cookie->getValue();
}

it('signs people in with an emailed code only, from Movewise\'s own pages, and keeps their homes and searches', function (): void {
    $this->postJson($this->mw.'/_auth/start', ['email' => 'a@b.com'])->assertForbidden(); // no origin
    $this->postJson($this->mw.'/_auth/start', ['email' => 'a@b.com'], ['Origin' => 'https://evil.test'])->assertForbidden();
    $this->postJson($this->mw.'/_auth/start', ['email' => 'not-an-email'], $this->from)->assertStatus(422);
    $this->getJson($this->mw.'/_me')->assertOk()->assertJson(['signed_in' => false, 'saved' => []]);
    $this->postJson($this->mw.'/_me/homes', ['listing' => Listing::query()->value('ulid')], $this->from)->assertUnauthorized();

    $sid = mwSignIn($this);
    $person = Person::query()->sole();
    expect($person->email)->toBe('buyer@example.com')->and($person->email_verified_at)->not->toBeNull()
        ->and(DB::connection(Connections::control())->table('movewise_sessions')->value('token_hash'))->toBe(hash('sha256', $sid)); // only the hash is kept

    $ulid = (string) Listing::query()->value('ulid');
    $this->withUnencryptedCookie('mw_sid', $sid)->postJson($this->mw.'/_me/homes', ['listing' => $ulid, 'saved' => true], $this->from)->assertOk();
    $this->withUnencryptedCookie('mw_sid', $sid)->getJson($this->mw.'/_me')->assertJson(['signed_in' => true, 'saved' => [$ulid]]);
    $this->withUnencryptedCookie('mw_sid', $sid)->postJson($this->mw.'/_me/searches', ['filters' => ['q' => 'Houston', 'state' => 'tx', 'evil' => 'x']], $this->from)->assertOk();
    $search = DB::connection(Connections::control())->table('movewise_saved_searches')->sole();
    expect(json_decode($search->filters, true))->toBe(['q' => 'Houston', 'states' => ['TX']])->and($search->frequency)->toBe('daily');

    $page = $this->withUnencryptedCookie('mw_sid', $sid)->get($this->mw.'/account')->assertOk()->assertSee('Signed in as buyer@example.com')->assertSee('12 Oak St')->assertSee('Houston')
        ->assertSee('noindex', false);
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($this->mw.'/account')->assertOk()->assertSee('Email me a code'); // signed out: the sign-in page

    // The move planner keeps the person's date and ticks.
    $this->withUnencryptedCookie('mw_sid', $sid)->post($this->mw.'/account/plan', ['move_date' => '2026-08-01', 'done' => ['movers', 'nonsense']], $this->from)->assertRedirect('/account#plan');
    expect($person->refresh()->plan)->toBe(['move_date' => '2026-08-01', 'done' => ['movers']]);
});

it('emails new homes for saved searches, with a one-click stop that needs no sign-in', function (): void {
    $sid = mwSignIn($this);
    $this->travel(5)->minutes(); // homes already on the market when the search is saved aren't "new"
    $this->withUnencryptedCookie('mw_sid', $sid)->postJson($this->mw.'/_me/searches', ['filters' => ['q' => 'Houston']], $this->from)->assertOk();
    $s = DB::connection(Connections::control())->table('movewise_saved_searches')->sole();

    $this->travel(2)->hours();
    $this->feed->records = [($this->home)('H9', 350000, 'Houston')];
    app(ListingSync::class)->run($this->source);
    $this->travel(1)->days();
    $this->artisan('movewise:alerts')->assertSuccessful();
    Mail::assertSent(AlertDigest::class, fn (AlertDigest $m): bool => $m->total === 1 && $m->stopUrl === Alerts::stopUrl((string) $s->ulid) && $m->hasTo('buyer@example.com'));
    $this->artisan('movewise:alerts');
    Mail::assertSent(AlertDigest::class, 1); // not again the same day

    $this->get($this->mw.'/alerts/off/'.$s->ulid.'/'.str_repeat('0', 32))->assertNotFound();
    $this->post($this->mw.'/alerts/off/'.$s->ulid.'/'.Alerts::sign((string) $s->ulid))->assertNoContent(); // RFC 8058 one-click
    expect(DB::connection(Connections::control())->table('movewise_saved_searches')->value('frequency'))->toBe('off');
    $this->get($this->mw.'/alerts/off/'.$s->ulid.'/'.Alerts::sign((string) $s->ulid))->assertOk()->assertSee('Alerts stopped');
});

it('lets people download everything and delete their account', function (): void {
    $sid = mwSignIn($this);
    $person = Person::query()->sole();
    $this->withUnencryptedCookie('mw_sid', $sid)->postJson($this->mw.'/_me/homes', ['listing' => Listing::query()->value('ulid')], $this->from)->assertOk();
    $export = $this->withUnencryptedCookie('mw_sid', $sid)->get($this->mw.'/account/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="my-movewise.json"');
    expect(json_decode((string) $export->getContent(), true)['account']['email'])->toBe('buyer@example.com');

    $this->withUnencryptedCookie('mw_sid', $sid)->post($this->mw.'/account/delete', [], $this->from)->assertStatus(422); // must tick "yes"
    $this->withUnencryptedCookie('mw_sid', $sid)->post($this->mw.'/account/delete', ['confirm' => '1'], $this->from)->assertRedirect('/');
    $db = DB::connection(Connections::control());
    expect(Person::query()->find($person->id))->toBeNull()->and($db->table('movewise_saved_homes')->count())->toBe(0)->and($db->table('movewise_sessions')->count())->toBe(0);
});

it('works out affordability and a seller\'s net, showing every step, as pages search engines can read', function (): void {
    $a = Affordability::for(120_000, 500, 40_000, 'TX');
    expect($a['budget'])->toBe(2_800)->and($a['price'])->toBeGreaterThan(200_000)->and($a['cost']['total'] - 280)->toBeLessThanOrEqual(2_800)->and($a['steps'])->toHaveCount(5);
    $n = NetSheet::for(400_000, 250_000, 3, 0, 5_000, 'TX');
    expect($n['lines'][2]['amount'])->toBe(-12_000)->and($n['net'])->toBe(400_000 - 250_000 - 12_000 - 5_000 + $n['lines'][4]['amount']);

    $this->get($this->mw.'/afford')->assertOk()->assertSee('How much home can I afford?')->assertSee('"@type":"FAQPage"', false)->assertSee('index, follow', false);
    $this->get($this->mw.'/afford?income=120000&debts=500&down=40000')->assertOk()->assertSee('$2,800')->assertSee('noindex', false);
    $this->get($this->mw.'/net-sheet?price=400000&payoff=250000&listing_pct=3')->assertOk()->assertSee('−$12,000');
    $this->get($this->mw.'/move-planner?move_date=2026-08-01')->assertOk()->assertSee('Get three quotes from licensed movers')->assertSee('2026-06-06');
    $this->get($this->mw.'/sitemaps/pages.xml')->assertSee('https://movewisehomes.com/afford')->assertSee('https://movewisehomes.com/es/afford');
});

it('answers with AIlly as HQ (no credits from anyone), keeps the conversation, and never lets a Fair Housing slip through', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => MovewiseAilly::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.movewise']);
    app(ProviderRegistry::class)->fake($model->provider->key, $ai = new FakeAiProvider);
    $ai->respond(json_encode(['reply' => 'It is about $2,900 a month all in.']));
    $ulid = (string) Listing::query()->value('ulid');
    $r = $this->postJson($this->mw.'/_ailly', ['message' => 'What would this cost me monthly?', 'listing' => $ulid], $this->from)->assertOk()->assertJson(['reply' => 'It is about $2,900 a month all in.']);
    expect(json_encode($ai->requests[0]))->toContain('12 Oak St')->toContain('400000');

    $ai->respond(json_encode(['reply' => 'Perfect for young couples with no kids.']));
    $this->postJson($this->mw.'/_ailly', ['chat' => $r->json('chat'), 'message' => 'Who is it good for?'], $this->from)->assertOk()
        ->assertJson(['chat' => $r->json('chat'), 'reply' => 'I can help with homes, what you can afford, a home\'s value and your move. What would you like to know about the home or your plans?']);
    $chat = DB::connection(Connections::control())->table('movewise_chats')->sole();
    expect(json_decode($chat->messages, true))->toHaveCount(4)->and($chat->person_id)->toBeNull();
    $this->postJson($this->mw.'/_ailly', ['message' => 'hi'], ['Origin' => 'https://evil.test'])->assertForbidden();
});

it('has every page in Spanish under /es, tied to the English one for search engines', function (): void {
    $en = $this->get($this->mw.'/')->assertOk()->assertSee('<html lang="en">', false)
        ->assertSee('<link rel="alternate" hreflang="es" href="https://movewisehomes.com/es">', false)->assertSee('<link rel="alternate" hreflang="x-default" href="https://movewisehomes.com/">', false);
    expect($en->headers->get('Content-Security-Policy'))->toContain("script-src 'self'");
    $this->get($this->mw.'/es')->assertOk()->assertSee('<html lang="es">', false)->assertSee('Encuentra tu próximo hogar')
        ->assertSee('<link rel="canonical" href="https://movewisehomes.com/es">', false)->assertSee('href="/es/homes"', false)->assertSee('English');
    $this->get($this->mw.'/es/homes?q=Houston')->assertOk()->assertSee('1 casa');
    $this->get($this->mw.'/es/texas')->assertOk()->assertSee('https://movewisehomes.com/es/texas', false);
    $this->get($this->mw.'/es/afford')->assertOk()->assertSee('¿Cuánta casa puedo pagar?');
    $this->get($this->mw.'/')->assertSee('<html lang="en">', false); // the language never sticks to the next request
    $en = require base_path('modules/Movewise/resources/lang/en.php');
    $es = require base_path('modules/Movewise/resources/lang/es.php');
    expect(array_keys($es))->toEqualCanonicalizing(array_keys($en)); // every string in both languages
    $this->get($this->mw.'/assets/movewise.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
});
