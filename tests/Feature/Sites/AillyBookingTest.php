<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\Mail\BookingConfirmedMail;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\SiteSetup;
use Modules\Sites\Visitors\ClosingBrief;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17D-3: AIlly books real times, prices sellers' homes, welcomes returning visitors, nudges, speaks Spanish. */

beforeEach(function (): void {
    // Wednesday 10:00 in Chicago.
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    Notification::fake();
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia']);
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'B'.$i, 'ListingId' => 'M'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 20000, 'LivingArea' => 1800, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704',
        'StreetNumber' => (string) $i, 'StreetName' => 'Elm', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 5));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([Assistant::FEATURE => 'ai.ailly', ClosingBrief::FEATURE => 'ai.closing_brief'] as $f => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    AillySettings::save($this->site, ['enabled' => true, 'gate' => 'booking', 'team' => [$this->jane->id]], $this->owner);
    $wed = ['mon' => [], 'tue' => [], 'wed' => [['12:00', '14:00']], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []];
    foreach ([$this->jane, $this->owner] as $u) {
        app(Availability::class)->save($u, true, $wed, 30, 1, $u);
    }
});

function bookChat(array $extra = []): string
{
    return (string) test()->postJson(test()->web.'/_ailly/start', ['vid' => 'vid_booking_0000000', 'page' => '/'] + $extra)->json('conversation');
}

function bookSlots(string $kind = 'call', array $extra = []): array
{
    return (array) test()->postJson(test()->web.'/_ailly/slots', ['kind' => $kind] + $extra)->assertOk()->json('slots');
}

function bookContact(string $c, array $extra): TestResponse
{
    return test()->postJson(test()->web.'/_ailly/contact', ['conversation' => $c, 'name' => 'Maria Lopez', 'email' => 'maria@example.test', 'vid' => 'vid_booking_0000000'] + $extra);
}

it('books a call in an open time with the booking team, as a task and lead for that agent, with a calendar invite', function (): void {
    $c = bookChat();
    $slots = bookSlots();
    expect($slots[0])->toMatchArray(['label' => 'Wed Jun 3, 12:00 PM', 'agent' => $this->jane->ulid, 'agent_name' => 'Jane']);

    bookContact($c, ['want' => 'call', 'slot' => $slots[0]['start'], 'slot_agent' => $slots[0]['agent']])
        ->assertOk()->assertJson(['ok' => true, 'booked' => 'Wednesday, June 3 at 12:00 PM CDT']);

    $lead = Lead::query()->sole();
    $task = Task::query()->where('type', 'call')->sole();
    expect($lead->assigned_user_id)->toBe($this->jane->id)
        ->and($task->assigned_to)->toBe($this->jane->id)->and($task->contact_id)->toBe($lead->contact_id)->and($task->priority)->toBe('high')
        ->and($task->due_at->equalTo(CarbonImmutable::parse('2026-06-03 17:00', 'UTC')))->toBeTrue()
        ->and(DB::connection(Connections::cell())->table('ailly_conversations')->where('ulid', $c)->value('outcome'))->toBe('call');
    Mail::assertSent(BookingConfirmedMail::class, fn (BookingConfirmedMail $m): bool => $m->hasTo('maria@example.test')
        && str_contains($m->ics(), 'DTSTART:20260603T170000Z') && str_contains($m->ics(), 'DTEND:20260603T173000Z') && str_contains($m->ics(), 'SUMMARY:Call with Jane Doe'));
    expect(collect(bookSlots())->pluck('label')->all())->not->toContain('Wed Jun 3, 12:00 PM'); // taken now
});

it('asks the visitor to choose again when the time was just taken, without saving a lead', function (): void {
    $c = bookChat();
    $slot = bookSlots()[0];
    Task::query()->create(['assigned_to' => $this->jane->id, 'type' => 'showing', 'title' => 'Showing', 'priority' => 'normal', 'due_at' => CarbonImmutable::parse($slot['start'])->utc()]);

    $r = bookContact($c, ['want' => 'call', 'slot' => $slot['start'], 'slot_agent' => $slot['agent']])->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'That time was just taken. Please choose another.']);
    expect($r->json('slots.0.label'))->toBe('Wed Jun 3, 1:00 PM')
        ->and(Lead::query()->count())->toBe(0);
    bookContact($c, ['want' => 'call', 'slot' => $slot['start'], 'slot_agent' => $this->owner->ulid])->assertStatus(422); // not on the booking team
    Mail::assertNothingSent();
});

it('books with the agent on their own pages, and the brokerage team elsewhere', function (): void {
    $this->actingAs($this->jane)->post('/settings/my-website')->assertSessionHasNoErrors();
    $this->actingAs($this->jane)->post('/settings/my-website/publish', ['live' => true])->assertSessionHasNoErrors();
    AillySettings::save($this->site, ['enabled' => true, 'gate' => 'booking', 'team' => [$this->owner->id]], $this->owner);

    expect(collect(bookSlots('call', ['agent_site' => 'jane-doe']))->pluck('agent_name')->unique()->all())->toBe(['Jane'])
        ->and(collect(bookSlots())->pluck('agent_name')->unique()->all())->toBe(['Olivia']);

    $c = bookChat(['agent_site' => 'jane-doe']);
    $slot = bookSlots('showing', ['agent_site' => 'jane-doe'])[0];
    bookContact($c, ['want' => 'showing', 'slot' => $slot['start'], 'slot_agent' => $slot['agent'], 'agent_site' => 'jane-doe'])->assertOk();
    expect(Task::query()->sole()->only(['type', 'assigned_to']))->toBe(['type' => 'showing', 'assigned_to' => $this->jane->id])
        ->and(Lead::query()->sole()->assigned_user_id)->toBe($this->jane->id);
});

it('gives sellers a likely range from similar homes for sale and books a listing appointment', function (): void {
    $c = bookChat();
    $this->ai->respond((string) json_encode(['reply' => "Here's a starting range for your home.", 'on_topic' => true, 'next_step' => 'none', 'lead_score' => 70, 'answered' => true,
        'valuation' => ['zip' => '78704', 'beds' => 3, 'sqft' => 1800]]));
    $r = $this->postJson($this->web.'/_ailly/message', ['conversation' => $c, 'message' => 'What is my 3 bed 1800 sqft home in 78704 worth?'])->assertOk();
    expect($r->json('next_step'))->toBe('book_listing')
        ->and($r->json('value'))->toMatchArray(['zip' => '78704', 'comps' => 5, 'basis' => 'size'])
        ->and($r->json('value.low'))->toBeLessThan($r->json('value.high'));

    $slot = bookSlots('listing')[0];
    bookContact($c, ['want' => 'listing', 'slot' => $slot['start'], 'slot_agent' => $slot['agent']])->assertOk()
        ->assertJsonPath('booked', 'Wednesday, June 3 at 12:00 PM CDT');
    expect(Task::query()->sole()->title)->toBe('Listing appointment booked from the website')
        ->and(Lead::query()->sole()->intent)->toBe('sell')
        ->and(DB::connection(Connections::cell())->table('ailly_conversations')->where('ulid', $c)->value('outcome'))->toBe('listing');
    Mail::assertSent(BookingConfirmedMail::class, fn (BookingConfirmedMail $m): bool => str_contains($m->ics(), 'DTEND:20260603T180000Z'));
});

it('welcomes a returning visitor with what is new for their last search, without AI', function (): void {
    $visitor = SiteVisitor::query()->create(['site_id' => $this->site->id, 'vid' => 'vid_booking_0000000', 'visits' => 3,
        'first_seen_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(2)]);
    SiteEvent::query()->create(['visitor_id' => $visitor->id, 'type' => 'search', 'path' => '/homes', 'data' => ['q' => 'Austin', 'beds' => 3], 'created_at' => now()->subDays(2)]);

    $r = $this->postJson($this->web.'/_ailly/start', ['vid' => 'vid_booking_0000000', 'page' => '/'])->assertOk();
    expect($r->json('greeting'))->toStartWith('Welcome back! 5 homes are new or reduced for 3+ bed homes in Austin')
        ->and($r->json('search_url'))->toBe('/homes?q=Austin&beds=3&sort=newest')
        ->and($this->ai->requests)->toBe([]);

    SiteEvent::query()->create(['visitor_id' => $visitor->id, 'type' => 'search', 'path' => '/homes', 'data' => ['q' => 'Dallas'], 'created_at' => now()->subMinutes(5)]);
    expect($this->postJson($this->web.'/_ailly/start', ['vid' => 'vid_booking_0000000', 'page' => '/'])->json('greeting'))->not->toStartWith('Welcome back'); // same visit
});

it('nudges on home pages only when allowed, and tells the assistant to reply in the visitor\'s language', function (): void {
    expect((string) $this->get($this->web.'/')->getContent())->toContain('data-nudge');
    AillySettings::save($this->site, ['enabled' => true, 'nudge' => false], $this->owner);
    expect((string) $this->get($this->web.'/')->getContent())->not->toContain('data-nudge');

    $c = bookChat();
    $this->ai->respond((string) json_encode(['reply' => '¡Claro! ¿En qué zona busca?', 'on_topic' => true, 'next_step' => 'none', 'lead_score' => 30, 'answered' => true]));
    $this->postJson($this->web.'/_ailly/message', ['conversation' => $c, 'message' => 'Hola, busco una casa de 3 recámaras'])->assertJsonPath('reply', '¡Claro! ¿En qué zona busca?');
    expect((string) json_encode($this->ai->requests))->toContain('reply in the language the visitor writes in');
});
