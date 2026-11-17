<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Deals\ListingActivity;
use Modules\ListIq\Models\Showing;
use Modules\ListIq\Preps;
use Modules\ListIq\ShowingMail;
use Modules\ListIq\Showings;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Portal\PortalView;

/* SH1: showing requests, approval with access sent only then, feedback after the showing, and the seller's view. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC')); // 10am in Austin
    Storage::fake('public');
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['timezone' => 'America/Chicago'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    $this->web = 'http://acme.rightally.site';
    $this->deal = Deal::query()->create(['type' => 'listing', 'status' => 'active', 'agent_user_id' => $this->ann->id, 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 45_000_000]);
    $this->prep = app(Preps::class)->create($this->ann, ['deal' => $this->deal->ulid]);
    $this->prep->forceFill(['in_mls' => true, 'access' => 'Lockbox 4321 on the front door'])->save();
});

it('takes showing requests on the listing page within showing hours, and sends access only when approved', function (): void {
    $this->post($this->web.'/_showing/'.$this->prep->ulid, ['name' => 'Bo Buyeragent', 'email' => 'bo@x.test', 'date' => '2026-09-02', 'time' => '22:00'])
        ->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?showing_error='.urlencode('Showings start between 8am and 8pm.').'#showing');
    $this->post($this->web.'/_showing/'.$this->prep->ulid, ['name' => 'Bo Buyeragent', 'email' => 'bo@x.test', 'brokerage' => 'KW', 'date' => '2026-09-02', 'time' => '14:00'])
        ->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?booked=1#showing');
    $s = Showing::query()->sole();
    expect($s->status)->toBe('requested')->and($s->starts_at->toIso8601String())->toBe('2026-09-02T19:00:00+00:00');
    Mail::assertSent(ShowingMail::class, fn (ShowingMail $m): bool => $m->kind === 'requested' && $m->hasTo($this->ann->email) && ! str_contains(implode(' ', $m->lines), '4321'));
    $this->post($this->web.'/_showing/'.$this->prep->ulid, ['name' => 'Cy', 'email' => 'cy@x.test', 'date' => '2026-09-02', 'time' => '14:15'])
        ->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?showing_error='.urlencode('That time is taken. Please choose another.').'#showing');

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/showings/{$s->ulid}", ['approve' => true])->assertRedirect();
    Mail::assertSent(ShowingMail::class, fn (ShowingMail $m): bool => $m->kind === 'approved' && $m->hasTo('bo@x.test') && str_contains(implode(' ', $m->lines), 'Lockbox 4321'));
    expect($this->prep->fresh()?->getRawOriginal('access'))->not->toContain('4321'); // encrypted at rest

    // Automatic approval when the agent chooses it.
    $this->actingAs($this->ann)->patch("/list-iq/{$this->prep->ulid}", ['showing_mode' => 'auto']);
    $this->post($this->web.'/_showing/'.$this->prep->ulid, ['name' => 'Di', 'email' => 'di@x.test', 'date' => '2026-09-03', 'time' => '10:00'])
        ->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?booked=2#showing');
    expect(Showing::query()->where('email', 'di@x.test')->sole()->status)->toBe('approved');
});

it('asks for feedback an hour after the showing, reminds once, and shows the seller the summary in their portal', function (): void {
    $showings = app(Showings::class);
    $s = $showings->request($this->prep, ['name' => 'Bo', 'email' => 'bo@x.test', 'date' => '2026-09-02', 'time' => '14:00']);
    $showings->approve($s, $this->ann);
    $this->travelTo(CarbonImmutable::parse('2026-09-02 19:45', 'UTC'));
    expect($showings->askFeedback())->toBe(0); // not an hour after yet
    $this->travelTo(CarbonImmutable::parse('2026-09-02 20:31', 'UTC'));
    expect($showings->askFeedback())->toBe(1)->and($showings->askFeedback())->toBe(0);
    $link = Mail::sent(ShowingMail::class, fn (ShowingMail $m): bool => $m->kind === 'feedback')->first()->url;
    $token = substr((string) $link, strrpos((string) $link, '/') + 1);
    $this->travel(1)->days();
    expect($showings->askFeedback())->toBe(1); // one reminder, with a new link
    $this->travel(2)->days();
    expect($showings->askFeedback())->toBe(0);
    $this->get('/showing-feedback/'.$token)->assertNotFound(); // the first link was replaced
    $link = Mail::sent(ShowingMail::class, fn (ShowingMail $m): bool => $m->kind === 'feedback')->last()->url;
    $token = substr((string) $link, strrpos((string) $link, '/') + 1);
    $this->get('/showing-feedback/'.$token)->assertOk()->assertSee('How interested were your buyers?')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->post('/showing-feedback/'.$token, ['interest' => 4, 'price' => 'high', 'comments' => 'Lovely kitchen, small yard.'])->assertOk()->assertSee('Thank you!');

    $this->prep->forceFill(['seller_note' => 'Two showings this week; one second look likely.', 'seller_note_at' => now()])->save();
    $l = app(ListingActivity::class)->forSeller($this->deal->id, 'es');
    expect($l)->toMatchArray(['shown' => 1, 'answered' => 1, 'interest' => 4.0, 'price' => ['low' => 0, 'right' => 0, 'high' => 1], 'comments' => ['Lovely kitchen, small yard.'],
        'note' => 'Two showings this week; one second look likely.']);
    $page = PortalView::page($this->deal, (object) ['ulid' => 'x', 'id' => 0, 'name' => 'Sam', 'role' => 'seller', 'locale' => 'en', 'phone' => null, 'sms_consent_at' => null, 'email' => 'sam@x.test']);
    expect($page['listing']['shown'])->toBe(1);
    $buyer = PortalView::page($this->deal, (object) ['ulid' => 'y', 'id' => 0, 'name' => 'Bob', 'role' => 'buyer', 'locale' => 'en', 'phone' => null, 'sms_consent_at' => null, 'email' => 'bob@x.test']);
    expect($buyer['listing'])->toBeNull();
});

it('tells the agent about requests waiting and quiet listings, and gives the seller plain advice when it goes quiet', function (): void {
    app(Showings::class)->request($this->prep, ['name' => 'Bo', 'email' => 'bo@x.test', 'date' => '2026-09-02', 'time' => '14:00']);
    expect(collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'showings-waiting')['title'])->toBe('1 showing request to answer');
    Showing::query()->update(['status' => 'declined']);
    $this->travel(15)->days();
    $this->prep->forceFill(['created_at' => now()->subDays(15)])->save();
    expect(collect(app(DailyBriefs::class)->for($this->ann))->firstWhere('key', 'showings-quiet-'.$this->prep->ulid))->not->toBeNull()
        ->and(app(Showings::class)->advice($this->prep->fresh() ?? $this->prep, 'en'))->toStartWith('There have been no showings in the last two weeks.');
});
