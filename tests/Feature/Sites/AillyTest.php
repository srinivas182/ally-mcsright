<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Lead;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17D-1: AIlly, the website assistant — answers, matching homes, contact gate, leads, and keeping credits for people. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'A'.$i, 'ListingId' => 'M'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 25000, 'LivingArea' => 1800, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704',
        'StreetNumber' => (string) $i, 'StreetName' => 'Oak', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 4));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Assistant::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.ailly']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    AillySettings::save($this->site, ['enabled' => true, 'name' => 'Ally', 'gate' => 'after', 'gate_after' => 2], $this->owner);
});

function aillyReplies(array $json): void
{
    test()->ai->respond((string) json_encode($json + ['on_topic' => true, 'next_step' => 'none', 'lead_score' => 40]));
}

function aillyChat(): string
{
    return (string) test()->postJson(test()->web.'/_ailly/start', ['vid' => 'vid_abcdefghijklmnop', 'page' => '/homes'])->assertJson(['ok' => true, 'name' => 'Ally'])->json('conversation');
}

function aillySay(string $c, string $text): TestResponse
{
    return test()->postJson(test()->web.'/_ailly/message', ['conversation' => $c, 'message' => $text]);
}

it('appears on the website only when switched on, and never talks to bots', function (): void {
    expect((string) $this->get($this->web.'/')->getContent())->toContain('data-ailly="Ally"')->toContain('/site-assets/ailly.js');
    $this->postJson($this->web.'/_ailly/start', [], ['User-Agent' => 'Googlebot/2.1'])->assertJson(['ok' => false]);

    AillySettings::save($this->site->fresh(), ['enabled' => false, 'name' => 'Ally', 'gate' => 'after'], $this->owner);
    expect((string) $this->get($this->web.'/')->getContent())->not->toContain('data-ailly=');
    $this->postJson($this->web.'/_ailly/start')->assertJson(['ok' => false]);
});

it('answers, shows matching homes from the MLS, and drops invented figures and Fair Housing risk wording', function (): void {
    $c = aillyChat();
    aillyReplies(['reply' => 'Here are a few 3-bedroom homes in Austin under $500,000. Prices fell 12% last month. It is a very safe area. Want to see them in person?',
        'search' => ['q' => 'Austin', 'min_price' => null, 'max_price' => 500000, 'beds' => 3, 'baths' => null], 'next_step' => 'see_homes']);

    $r = aillySay($c, 'Looking for a 3 bed in Austin under 500k')->assertOk()->assertJson(['next_step' => 'see_homes', 'needs_contact' => false]);

    expect($r->json('homes'))->toHaveCount(3)
        ->and($r->json('homes.0'))->toHaveKeys(['ulid', 'label', 'price', 'beds', 'url', 'attribution'])
        ->and($r->json('reply'))->toContain('Here are a few 3-bedroom homes in Austin under $500,000')->not->toContain('12%')->not->toContain('safe')
        ->and(json_encode($this->ai->requests[0]))->toContain('Visitor: Looking for a 3 bed in Austin under 500k');
});

it('asks for contact details after the chosen number of answers, before spending more credits', function (): void {
    $c = aillyChat();
    aillyReplies(['reply' => 'Happy to help.']);
    aillySay($c, 'Hi');
    aillyReplies(['reply' => 'Austin has many neighbourhoods.']);
    aillySay($c, 'Tell me about Austin');

    aillySay($c, 'And schools?')->assertJson(['needs_contact' => true]);
    expect($this->ai->requests)->toHaveCount(2);
});

it('turns shared details into a CRM lead with the chat, and books a call', function (): void {
    $c = aillyChat();
    aillyReplies(['reply' => 'Sure, an agent can call you.', 'next_step' => 'book_call', 'lead_score' => 85]);
    aillySay($c, 'We want to buy in the next month, pre-approved');

    $this->postJson($this->web.'/_ailly/contact', ['conversation' => $c, 'name' => 'Maria Lopez', 'email' => 'maria@example.test', 'phone' => '+15125550100',
        'want' => 'call', 'when' => 'Tuesday morning', 'sms_consent' => true, 'vid' => 'vid_abcdefghijklmnop'])->assertJson(['ok' => true]);

    $lead = Lead::query()->latest('id')->firstOrFail();
    $conversation = DB::connection(Connections::cell())->table('ailly_conversations')->where('ulid', $c)->first();
    expect($conversation->outcome)->toBe('call')->and($conversation->lead_id)->toBe($lead->id)->and($conversation->lead_score)->toBe(85)
        ->and((string) $lead->getAttribute('message'))->toContain('Asked for a call: Tuesday morning')->toContain('Visitor: We want to buy in the next month');

    $this->postJson($this->web.'/_ailly/contact', ['conversation' => $c, 'name' => 'X'])->assertUnprocessable(); // email or phone required
});

it('answers a repeated first question for free from the day\'s cache', function (): void {
    aillyReplies(['reply' => 'Our office is open 9 to 5 on weekdays.']);
    aillySay(aillyChat(), 'What are your office hours?');

    aillySay(aillyChat(), 'what are your office hours')->assertJson(['reply' => 'Our office is open 9 to 5 on weekdays.']);
    expect($this->ai->requests)->toHaveCount(1)
        ->and(DB::connection(Connections::cell())->table('ailly_conversations')->sum('cached_replies'))->toBe(1);
});

it('ends chats that are not about homes after two tries', function (): void {
    $c = aillyChat();
    aillyReplies(['reply' => 'I can only help with homes and real estate here.', 'on_topic' => false]);
    aillySay($c, 'Write my homework');
    aillyReplies(['reply' => 'I can only help with homes and real estate here.', 'on_topic' => false]);

    aillySay($c, 'Please write my homework')->assertJson(['ended' => true]);
    aillySay($c, 'Hello?')->assertUnprocessable();
});

it('limits messages per visitor a day and new chats a day', function (): void {
    AillySettings::save($this->site->fresh(), ['enabled' => true, 'name' => 'Ally', 'gate' => 'booking', 'visitor_daily' => 5, 'daily_cap' => 10], $this->owner);
    $c = aillyChat();
    foreach (range(1, 5) as $i) {
        aillyReplies(['reply' => 'Answer '.$i.'.']);
        aillySay($c, 'Question number '.$i);
    }
    aillySay($c, 'One more')->assertJson(['needs_contact' => true, 'next_step' => 'book_call']);
    expect($this->ai->requests)->toHaveCount(5);

    foreach (range(1, 9) as $i) {
        aillyChat();
    }
    $this->postJson($this->web.'/_ailly/start', ['vid' => 'vid_abcdefghijklmnop'])->assertJson(['ok' => false]);
});

it('asks for details instead of answering when credits run out or the AI is unavailable', function (): void {
    $c = aillyChat();
    $this->ai->fail(new InsufficientCredits('Out of credits.'));

    aillySay($c, 'Is now a good time to sell?')->assertJson(['needs_contact' => true, 'next_step' => 'book_call'])
        ->assertJsonPath('reply', 'I can\'t answer that right now. Leave your name, email and phone and an agent will get back to you shortly.');
});

it('lets owners and staff set AIlly up and read the latest chats', function (): void {
    $c = aillyChat();
    aillyReplies(['reply' => 'Hello there.']);
    aillySay($c, 'Hi');

    $this->actingAs($this->owner)->get('/settings/ailly')->assertInertia(fn (Assert $p) => $p->component('settings/ailly')
        ->where('settings.gate', 'after')->where('month.conversations', 1)->where('conversations.0.messages.1', ['role' => 'visitor', 'body' => 'Hi']));
    $this->actingAs($this->owner)->post('/settings/ailly', ['enabled' => true, 'name' => 'Rae', 'greeting' => 'Hello!', 'gate' => 'booking', 'gate_after' => 2,
        'visitor_daily' => 20, 'daily_cap' => 300])->assertRedirect();
    expect(AillySettings::for($this->site->fresh()))->toMatchArray(['name' => 'Rae', 'gate' => 'booking', 'greeting' => 'Hello!']);

    $this->actingAs(User::factory()->create())->get('/settings/ailly')->assertForbidden();
});
