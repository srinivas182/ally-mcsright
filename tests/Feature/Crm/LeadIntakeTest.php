<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\FakeVoiceCaller;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Leads\Attribution;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Leads\PortalEmail;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\RoutingRule;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 16A: lead intake (API, forms, forwarded portal emails), attribution incl. AI search, routing, speed to lead. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in the brokerage's time zone
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea', 'last_name' => 'Broker']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent', 'phone' => '+15125550122']);
    $this->sources = app(LeadSources::class);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    app()->instance(VoiceCaller::class, $this->calls = new FakeVoiceCaller);
    Notification::fake();
});

function apiSource(object $t): string
{
    return $t->sources->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $t->owner)['key'] ?? '';
}

function roundRobin(object $t, array $extra = []): void
{
    app(LeadRouting::class)->saveRule($extra + ['name' => 'Everyone', 'method' => 'round_robin', 'agent_ids' => [$t->ann->id, $t->bob->id]], $t->owner);
}

it('classifies channels, with AI answer engines as their own channel', function (string $referrer, array $utm, string $channel): void {
    expect(Attribution::channel($referrer, $utm))->toBe($channel);
})->with([
    'ChatGPT by utm' => ['', ['utm_source' => 'chatgpt.com'], 'ai_search'],
    'Perplexity referrer' => ['https://www.perplexity.ai/search?q=homes', [], 'ai_search'],
    'Gemini' => ['https://gemini.google.com/app', [], 'ai_search'],
    'Copilot' => ['https://copilot.microsoft.com/', [], 'ai_search'],
    'Google organic' => ['https://www.google.com/', [], 'organic'],
    'Bing organic' => ['https://www.bing.com/search?q=x', [], 'organic'],
    'Google Ads' => ['https://www.google.com/', ['gclid' => 'abc'], 'paid'],
    'paid social' => ['https://facebook.com', ['utm_medium' => 'paid_social'], 'paid'],
    'social' => ['https://l.instagram.com/', [], 'social'],
    'portal' => ['https://www.zillow.com/homedetails/1', [], 'portal'],
    'newsletter' => ['', ['utm_medium' => 'email'], 'email'],
    'other site' => ['https://austinblog.example/post', [], 'referral_site'],
    'direct' => ['', [], 'direct'],
]);

it('takes leads by API, never twice for the same external id, and rotates them between agents', function (): void {
    $key = apiSource($this);
    roundRobin($this);
    $headers = ['Authorization' => 'Bearer '.$key];

    $first = $this->postJson('/api/leads', ['name' => 'Jane Doe', 'email' => 'JANE@x.test', 'phone' => '(512) 555-0199', 'intent' => 'buy', 'zip' => '78704',
        'price' => '450,000', 'external_id' => 'fb-1', 'utm_source' => 'chatgpt.com', 'landing_url' => 'https://acme.example/austin'], $headers)
        ->assertCreated()->json('id');
    $this->postJson('/api/leads', ['name' => 'Jane Doe', 'email' => 'jane@x.test', 'external_id' => 'fb-1'], $headers)->assertOk()->assertJson(['id' => $first]);
    $this->postJson('/api/leads', ['first_name' => 'Sam', 'phone' => '5125550188', 'external_id' => 'fb-2'], $headers)->assertCreated();

    $leads = Lead::query()->orderBy('id')->get();
    expect($leads)->toHaveCount(2)
        ->and($leads[0]->channel)->toBe('ai_search')->and($leads[0]->price_min)->toBe(450000)->and($leads[0]->status)->toBe('assigned')
        ->and([$leads[0]->assigned_user_id, $leads[1]->assigned_user_id])->toBe([$this->ann->id, $this->bob->id]);
    $jane = Contact::query()->where('primary_email', 'jane@x.test')->sole();
    expect($jane->origin->value)->toBe('company')->and($jane->owner_user_id)->toBe($this->ann->id)->and($jane->primary_phone)->toBe('+15125550199');
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => str_contains($n->line, 'buying around 78704 $450,000 (via facebook)'));

    $this->postJson('/api/leads', ['name' => 'X'], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/leads', ['name' => 'X', 'email' => 'x@x.test'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    expect(fn () => $this->sources->create(['name' => 'Mine', 'kind' => 'api', 'source' => 'other'], $this->ann))->toThrow(ValidationException::class);
});

it('gives a returning contact to their own agent and adds the inquiry to their timeline', function (): void {
    $key = apiSource($this);
    roundRobin($this);
    $pat = app(ManageContacts::class)->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->bob);

    $this->postJson('/api/leads', ['name' => 'Patricia', 'email' => 'pat@x.test', 'message' => 'Is 12 Oak still available?'], ['Authorization' => 'Bearer '.$key])->assertCreated();
    $lead = Lead::query()->sole();
    expect($lead->assigned_user_id)->toBe($this->bob->id)->and($lead->new_contact)->toBeFalse()->and($lead->contact_id)->toBe($pat->id)
        ->and(Contact::query()->count())->toBe(1)
        ->and($pat->refresh()->origin->value)->toBe('personal')
        ->and(DB::table('crm_activities')->where('contact_id', $pat->id)->where('body', 'like', 'New inquiry from this contact%Is 12 Oak still available%')->exists())->toBeTrue();
});

it('routes by ZIP and price, skips agents who paused leads, offers first-to-claim, and uses the pond', function (): void {
    $source = $this->sources->create(['name' => 'Site', 'kind' => 'api', 'source' => 'website'], $this->owner)['source'];
    $routing = app(LeadRouting::class);
    $routing->saveRule(['name' => 'Luxury 78704', 'method' => 'agent', 'agent_ids' => [$this->bob->id], 'zips' => '78704', 'price_min' => 1000000], $this->owner);
    $routing->saveRule(['name' => 'Sellers', 'method' => 'first_claim', 'agent_ids' => [$this->ann->id, $this->bob->id], 'intent' => 'sell'], $this->owner);
    $routing->saveRule(['name' => 'Renters', 'method' => 'pond', 'intent' => 'rent'], $this->owner);
    roundRobin($this);
    $in = fn (array $x) => app(LeadIntake::class)->receive($source, $x + ['name' => 'Lee '.uniqid(), 'email' => uniqid().'@x.test']);

    expect($in(['zip' => '78704', 'price' => 1500000])->assigned_user_id)->toBe($this->bob->id)
        ->and($in(['zip' => '78704', 'price' => 400000])->assigned_user_id)->toBe($this->ann->id); // falls through to round robin

    $offered = $in(['intent' => 'sell']);
    expect($offered->status)->toBe('offered')->and($offered->offered_to)->toEqualCanonicalizing([$this->ann->id, $this->bob->id]);
    $this->actingAs($this->bob)->post("/leads/{$offered->ulid}/claim")->assertRedirect();
    $this->actingAs($this->ann)->post("/leads/{$offered->ulid}/claim")->assertSessionHasErrors('lead');
    expect($offered->refresh()->assigned_user_id)->toBe($this->bob->id)->and($offered->contact?->owner_user_id)->toBe($this->bob->id);

    $rent = $in(['intent' => 'rent']);
    expect($rent->status)->toBe('pond')->and($rent->contact?->in_pond_since)->not->toBeNull();

    // Ann pauses new leads: round robin skips her.
    $this->actingAs($this->ann)->post('/leads/alerts', ['texts' => false, 'calls' => false, 'away_hours' => 4])->assertRedirect();
    expect($in([])->assigned_user_id)->toBe($this->bob->id)->and($in([])->assigned_user_id)->toBe($this->bob->id);
});

it('moves unanswered leads on after the window, only in waking hours, then to the broker', function (): void {
    $key = apiSource($this);
    roundRobin($this);
    app(LeadRouting::class)->saveSettings(['respond_minutes' => 5, 'max_passes' => 1, 'fallback' => 'broker'], $this->owner);
    $this->postJson('/api/leads', ['name' => 'Jane Doe', 'email' => 'jane@x.test'], ['Authorization' => 'Bearer '.$key]);
    $lead = Lead::query()->sole();
    expect($lead->assigned_user_id)->toBe($this->ann->id);

    $this->travel(4)->minutes();
    $this->artisan('crm:leads-reassign')->assertSuccessful();
    expect($lead->refresh()->assigned_user_id)->toBe($this->ann->id);

    $this->travel(2)->minutes();
    $this->artisan('crm:leads-reassign')->assertSuccessful();
    expect($lead->refresh()->assigned_user_id)->toBe($this->bob->id)->and($lead->passed_over)->toBe([$this->ann->id]);
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'A lead moved to another agent');

    $this->travel(6)->minutes();
    $this->artisan('crm:leads-reassign');
    expect($lead->refresh()->assigned_user_id)->toBe($this->owner->id)->and($lead->assigned_at)->toBeNull();
    $this->travel(30)->minutes();
    $this->artisan('crm:leads-reassign');
    expect($lead->refresh()->assigned_user_id)->toBe($this->owner->id); // the broker is the end of the line

    // Overnight the clock stops.
    $this->travelTo(CarbonImmutable::parse('2026-06-02 01:58', 'UTC')); // 20:58 local
    $this->postJson('/api/leads', ['name' => 'Night Owl', 'email' => 'owl@x.test'], ['Authorization' => 'Bearer '.$key]);
    $owl = Lead::query()->latest('id')->first();
    $this->travelTo(CarbonImmutable::parse('2026-06-02 06:00', 'UTC')); // 1am
    $this->artisan('crm:leads-reassign');
    expect($owl->refresh()->passes)->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 13:03', 'UTC')); // 8:03am
    $this->artisan('crm:leads-reassign');
    expect($owl->refresh()->passes)->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 13:06', 'UTC'));
    $this->artisan('crm:leads-reassign');
    expect($owl->refresh()->passes)->toBe(1);
});

it('stops the clock at the first call, text or email and records the response time', function (): void {
    $key = apiSource($this);
    roundRobin($this);
    $this->postJson('/api/leads', ['name' => 'Jane Doe', 'email' => 'jane@x.test'], ['Authorization' => 'Bearer '.$key]);
    $lead = Lead::query()->sole();
    $this->travel(3)->minutes();
    app(Timeline::class)->log($lead->contact()->firstOrFail(), ['type' => 'note', 'body' => 'Looked at her saved homes'], $this->ann);
    expect($lead->refresh()->responded_at)->toBeNull();
    app(Timeline::class)->log($lead->contact()->firstOrFail(), ['type' => 'call', 'outcome' => 'left_voicemail'], $this->ann);
    expect($lead->refresh()->status)->toBe('responded')->and($lead->response_seconds)->toBe(180)->and($lead->responded_by)->toBe($this->ann->id);
    $this->travel(10)->minutes();
    $this->artisan('crm:leads-reassign');
    expect($lead->refresh()->assigned_user_id)->toBe($this->ann->id);

    $this->actingAs($this->owner)->get('/leads')->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/leads/index')
        ->where('stats.total', 1)->where('stats.median_minutes', 3)->where('stats.within_5', 100)->where('leads.0.response_minutes', 3));
    $this->actingAs($this->bob)->get('/leads')->assertInertia(fn (Assert $page) => $page->has('leads', 0)->where('canManage', false));
});

it('texts the agent and calls them with press 1 to connect, when they opted in and HQ allows it', function (): void {
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['sms_from' => '+15125550100', 'voice_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->ann)->post('/leads/alerts', ['texts' => true, 'calls' => true])->assertRedirect();
    $key = apiSource($this);
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);

    $this->postJson('/api/leads', ['name' => 'Jane Doe', 'phone' => '5125550199', 'intent' => 'buy', 'zip' => '78704'], ['Authorization' => 'Bearer '.$key])->assertCreated();
    $lead = Lead::query()->sole();
    expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['to'])->toBe('+15125550111')
        ->and($this->sms->sent[0]['message'])->toStartWith('RightAlly: new lead — Jane D., buying around 78704 (via facebook). Reach out within 5 min:')
        ->and($this->calls->connects)->toHaveCount(1)
        ->and($this->calls->connects[0]['message'])->toBe('New lead from RightAlly: Jane D., buying around 78704 (via facebook). Press 1 to be connected to them now.');

    $press = $this->calls->connects[0]['on_press'];
    $this->post($press, ['Digits' => '1'])->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8')
        ->assertSee('<Dial callerId="+15125550100" timeout="25">+15125550199</Dial>', false);
    expect($lead->refresh()->status)->toBe('responded');
    $this->post($press.'x', ['Digits' => '1'])->assertForbidden();

    Contact::query()->whereKey($lead->contact_id)->update(['do_not_call' => true]);
    $this->post($press, ['Digits' => '1'])->assertSee('asked not to be called')->assertDontSee('<Dial', false);
});

it('needs a mobile number before an agent opts in to texts', function (): void {
    $noPhone = User::factory()->create(['phone' => null]);
    $this->actingAs($noPhone)->post('/leads/alerts', ['texts' => true, 'calls' => false])->assertSessionHasErrors('phone');
});

it('serves a fast, server-rendered hosted form and takes leads from it and from embedded forms', function (): void {
    $source = $this->sources->create(['name' => 'Website', 'kind' => 'form', 'source' => 'website', 'heading' => 'Ask about Austin homes'], $this->owner)['source'];
    roundRobin($this);
    $url = '/l/'.$source->token;

    $html = $this->get($url, ['Referer' => 'https://www.perplexity.ai/search'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->getContent();
    expect($html)->toContain('<h1>Ask about Austin homes</h1>')->toContain('"@type":"RealEstateAgent"')->toContain('rel="canonical"')
        ->toContain('value="https://www.perplexity.ai/search"')->toContain('Consent is not a condition of purchase');

    // Bots: honeypot and too-fast submissions look successful but create nothing.
    $this->post($url, ['first_name' => 'Bot', 'email' => 'bot@x.test', 'website' => 'spam'])->assertRedirect($url.'/thanks');
    $this->post($url, ['first_name' => 'Bot', 'email' => 'bot@x.test', 't' => Crypt::encryptString((string) time())])->assertRedirect($url.'/thanks');
    expect(Lead::query()->count())->toBe(0);

    $this->post($url, ['first_name' => 'Jane'])->assertStatus(422)->assertSee('Enter an email address or a US phone number.');

    $this->post($url, ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '512-555-0199', 'sms_consent' => '1', 'message' => 'Tour Saturday?',
        'first_referrer' => 'https://www.perplexity.ai/search', 'landing_url' => 'https://acme.example/austin?utm_campaign=spring'], ['Referer' => 'https://acme.example/contact'])
        ->assertRedirect($url.'/thanks');
    $lead = Lead::query()->sole();
    expect($lead->channel)->toBe('ai_search')->and($lead->referrer_host)->toBe('perplexity.ai')->and($lead->utm)->toBe(['utm_campaign' => 'spring'])
        ->and($lead->message)->toBe('Tour Saturday?')
        ->and($lead->contact?->sms_opt_in)->toBeTrue()->and($lead->contact?->sms_consent_text)->toBe($source->consent_wording);
    $this->get($url.'/thanks')->assertOk()->assertSee('Thank you');
    $this->get('/l/'.str_repeat('z', 40))->assertNotFound();
});

it('reads forwarded Zillow emails, and keeps unreadable ones for review', function (): void {
    config(['crm.inbound_email_secret' => 'inbound-secret']);
    $source = $this->sources->create(['name' => 'Portal emails', 'kind' => 'email', 'source' => 'zillow'], $this->owner)['source'];
    roundRobin($this);
    $to = LeadSources::address($source);
    expect($to)->toMatch('/^leads-[A-Za-z0-9]{40}@inbound\.rightally\.app$/');
    $post = fn (array $body, string $password = 'inbound-secret') => $this->withBasicAuth('postmark', $password)->postJson('/webhooks/inbound/lead-email', $body + ['OriginalRecipient' => $to]);

    $post(['From' => 'Zillow <premieragent@zillow.com>', 'ReplyTo' => 'jane.doe@gmail.com', 'Subject' => 'New Contact: 12 Oak St, Austin, TX 78704', 'MessageID' => 'm-1',
        'TextBody' => "You have a new contact!\nName: Jane Doe\nPhone: (512) 555-0199\nEmail: jane.doe@gmail.com\nProperty: 12 Oak St, Austin, TX 78704\nList price: \$450,000\nMessage: I am interested in 12 Oak St. Can I tour it this weekend?"])
        ->assertOk()->assertJson(['status' => 'assigned']);
    $lead = Lead::query()->sole();
    expect($lead->source)->toBe('zillow')->and($lead->channel)->toBe('portal')->and($lead->zip)->toBe('78704')->and($lead->intent)->toBe('buy')
        ->and($lead->price_min)->toBe(450000)->and($lead->message)->toBe('I am interested in 12 Oak St. Can I tour it this weekend?')
        ->and($lead->contact?->name)->toBe('Jane Doe')->and($lead->contact?->primary_email)->toBe('jane.doe@gmail.com')->and($lead->contact?->primary_phone)->toBe('+15125550199');

    // The same email again (provider retry): no second lead.
    $post(['From' => 'premieragent@zillow.com', 'Subject' => 'x', 'MessageID' => 'm-1', 'TextBody' => "Name: Jane Doe\nEmail: jane.doe@gmail.com"])->assertOk();
    expect(Lead::query()->count())->toBe(1);

    $post(['From' => 'alerts@zillow.com', 'Subject' => 'Weekly summary', 'MessageID' => 'm-2', 'TextBody' => 'Your listings got 120 views this week.'])->assertJson(['status' => 'needs_review']);
    expect(Lead::query()->where('status', 'needs_review')->sole()->raw)->toContain('Your listings got 120 views');
    $this->actingAs($this->owner)->get('/leads')->assertInertia(fn (Assert $page) => $page->has('review', 1));

    $post(['From' => 'x@zillow.com', 'Subject' => 'x', 'TextBody' => 'Name: A B'], 'wrong')->assertUnauthorized();
    $this->withBasicAuth('postmark', 'inbound-secret')->postJson('/webhooks/inbound/lead-email', ['OriginalRecipient' => 'leads-'.str_repeat('q', 40).'@inbound.rightally.app', 'TextBody' => 'x'])
        ->assertOk()->assertJson(['status' => 'ignored']);
});

it('reads other senders with AI, keeping only details that appear in the email', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => PortalEmail::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_lead_email']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond(json_encode(['first_name' => 'Carlos', 'last_name' => 'Ruiz', 'email' => '', 'phone' => '512 555 0177', 'intent' => 'sell', 'message' => '', 'property' => '9 Elm Rd', 'zip' => '78701', 'price' => '']));
    $email = ['from' => 'hello@newportal.example', 'subject' => 'Seller lead', 'text' => "Carlos Ruiz wants a valuation of 9 Elm Rd, Austin 78701.\nCall him on 512-555-0177."];

    $read = app(PortalEmail::class)->read($email);
    expect($read['read_by'])->toBe('ai')->and($read['first_name'])->toBe('Carlos')->and($read['last_name'])->toBe('Ruiz')
        ->and($read['phone'])->toBe('+15125550177')->and($read['email'])->toBeNull()
        ->and($read['intent'])->toBe('sell')->and($read['property'])->toBe('9 Elm Rd')->and($read['source'])->toBe('other');

    // An answer with details that are not in the email is withheld by the AI gateway; nothing invented is kept.
    $ai->respond(json_encode(['first_name' => 'Carlos', 'last_name' => 'Ruiz', 'email' => 'carlos@invented.test', 'phone' => '512 555 0177']));
    $read = app(PortalEmail::class)->read(['from' => 'hello@other.example'] + $email);
    expect($read['email'])->toBeNull()->and($read['first_name'])->toBe('')->and($read['read_by'])->toBe('parser');
});

it('lets owners manage sources, rules and the response window, and shows an API key once', function (): void {
    $this->actingAs($this->owner)->post('/leads/sources', ['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'])->assertRedirect();
    $page = $this->actingAs($this->owner)->get('/leads/settings')->assertOk();
    $key = $page->viewData('page')['props']['newKey']['key'];
    expect($key)->toStartWith('ra_live_')->and(LeadSources::forKey($key)?->name)->toBe('Zapier')
        ->and(LeadSource::query()->sole()->key_hash)->toBe(hash('sha256', $key));
    $this->actingAs($this->owner)->get('/leads/settings')->assertInertia(fn (Assert $p) => $p->where('newKey', null)->where('sources.0.key_last4', substr($key, -4)));

    $this->actingAs($this->owner)->post('/leads/rules', ['name' => 'Bad', 'method' => 'agent', 'agent_ids' => [$this->ann->id, $this->bob->id]])->assertSessionHasErrors('agent_ids');
    $this->actingAs($this->owner)->post('/leads/rules', ['name' => 'Zips', 'method' => 'round_robin', 'agent_ids' => [$this->ann->id], 'zips' => '7870'])->assertSessionHasErrors('zips');
    $this->actingAs($this->owner)->post('/leads/settings', ['respond_minutes' => 0, 'max_passes' => 1, 'fallback' => 'broker'])->assertSessionHasErrors('respond_minutes');
    $this->actingAs($this->ann)->get('/leads/settings')->assertForbidden();
    $this->actingAs($this->ann)->post('/leads/rules', ['name' => 'Mine', 'method' => 'agent', 'agent_ids' => [$this->ann->id]])->assertSessionHasErrors('leads');
});

it('sends the lead pages the data they need', function (): void {
    assertPageContract($this->actingAs($this->owner)->get('/leads'), 'crm/leads/index');
    assertPageContract($this->actingAs($this->owner)->get('/leads/settings'), 'crm/leads/settings');
});

it('emails the broker about leads that go to the lead pond, and copies owners and staff who ask (FX6)', function (): void {
    $key = apiSource($this);
    $headers = ['Authorization' => 'Bearer '.$key];
    $staff = User::factory()->create(['kind' => UserKind::Staff, 'first_name' => 'Sue']);

    // A pond rule: no agent is told, so the broker is.
    app(LeadRouting::class)->saveRule(['name' => 'Pond', 'method' => 'pond'], $this->owner);
    $this->postJson('/api/leads', ['name' => 'Pat Pond', 'email' => 'pat@x.test', 'intent' => 'buy'], $headers)->assertCreated();
    Notification::assertSentTo($this->owner, LeadAlert::class, fn ($n) => str_contains($n->subject, 'lead pond'));
    Notification::assertNotSentTo($staff, LeadAlert::class);

    // Sue asks for a copy of every lead: she hears where each one went; the agent who got it is not emailed twice.
    $this->actingAs($staff)->post('/leads/alerts', ['texts' => false, 'calls' => false, 'copy_all' => true])->assertSessionHasNoErrors();
    RoutingRule::query()->delete();
    roundRobin($this);
    Notification::fake();
    $this->postJson('/api/leads', ['name' => 'Rae Robin', 'email' => 'rae@x.test', 'intent' => 'buy'], $headers)->assertCreated();
    Notification::assertSentToTimes($staff, LeadAlert::class, 1);
    $agentTold = collect([$this->ann, $this->bob])->filter(fn ($u) => Notification::sent($u, LeadAlert::class)->count() === 1);
    expect($agentTold)->toHaveCount(1);

    // Agents cannot turn copies on for themselves.
    $this->actingAs($this->ann)->post('/leads/alerts', ['texts' => false, 'calls' => false, 'copy_all' => true]);
    expect(DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('user_id', $this->ann->id)->value('copy_all'))->toBeFalsy();
});
