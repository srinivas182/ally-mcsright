<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\FakeVoiceCaller;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Leads\AiCaller;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;

/* LQ1: Lead-IQ calls new leads within a minute, in the brokerage's name, only with written consent to automated calls. */

const IQ_CONSENT = 'I agree to receive calls and text messages, including automated and AI-assisted messages, from the brokerage at this number.';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'tw-token'])),
        'sms_from' => '+15125550100', 'voice_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => true, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent', 'phone' => '+15125550122']);
    foreach ([$this->ann, $this->bob] as $agent) {
        $this->actingAs($agent)->post('/leads/alerts', ['texts' => false, 'calls' => true])->assertRedirect();
    }
    $made = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner);
    $this->key = (string) $made['key'];
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    app(AiResponder::class)->saveSettings(['enabled' => false, 'voice_enabled' => true, 'assistant_name' => 'Ava', 'brand_name' => 'Acme Homes', 'max_messages' => 6, 'voice_monthly_cap' => 200], $this->owner);
    app(Availability::class)->save($this->ann, true, Availability::DEFAULT_HOURS, 30, 2, $this->ann);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    app()->instance(VoiceCaller::class, $this->calls = new FakeVoiceCaller);
    Notification::fake();
});

function iqLead(object $t, array $extra = []): Lead
{
    $t->postJson('/api/leads', $extra + ['name' => 'Jane Doe', 'phone' => '5125550199', 'email' => 'jane@example.test', 'intent' => 'buy', 'zip' => '78704',
        'sms_consent' => true, 'consent_text' => IQ_CONSENT], ['Authorization' => 'Bearer '.$t->key])->assertSuccessful();

    return Lead::query()->latest('id')->firstOrFail();
}

/** @return object{status: string, reason: string|null, ulid: string} */
function iqCall(): object
{
    return DB::connection(Connections::cell())->table('crm_ai_calls')->latest('id')->first();
}

/** The next step's signed address from a TwiML answer. */
function iqNext(string $xml, string $attr = 'action'): string
{
    preg_match('/'.$attr.'="([^"]+)"/', $xml, $m);

    return html_entity_decode($m[1] ?? '', ENT_XML1 | ENT_QUOTES);
}

it('calls a new lead within a minute in the brokerage\'s name, says it is an AI, and offers an agent, a link or no more calls', function (): void {
    $lead = iqLead($this);
    expect($this->calls->connects)->toHaveCount(1);
    $c = $this->calls->connects[0];
    expect($c['to'])->toBe('+15125550199')->and($c['voice'])->toBe('Polly.Joanna')
        ->and($c['message'])->toBe('Hi Jane, this is Ava, an AI assistant calling for Acme Homes. Thanks for your interest in finding a home. To talk with an agent now, press 1. To get a link to pick a time, press 2. Para español, oprima 3. To stop these calls, press 9.');
    expect(iqCall()->status)->toBe('calling');
    $this->get(str_replace('https://', 'http://', $c['on_press']))->assertStatus(405); // Twilio posts
    expect($lead->contact?->primary_phone)->toBe('+15125550199');
});

it('does not call without written consent to calls, on Do Not Call, or past the monthly limit, and says why', function (): void {
    iqLead($this, ['consent_text' => 'I agree to receive texts from the brokerage.']);
    // The assistant doesn't call; the agent still gets their own press-1 call to reach the lead.
    expect(collect($this->calls->connects)->where('to', '+15125550199')->all())->toBe([])->and(iqCall()->status)->toBe('skipped')
        ->and(iqCall()->reason)->toContain('No written consent to automated calls')->and($this->calls->connects[0]['to'])->toBe('+15125550111');
    $this->calls->connects = [];

    app(AiResponder::class)->saveSettings(['voice_enabled' => true, 'assistant_name' => 'Ava', 'brand_name' => 'Acme Homes', 'max_messages' => 6, 'voice_monthly_cap' => 0], $this->owner);
    iqLead($this, ['phone' => '5125550177', 'name' => 'Sam Lee', 'email' => 'sam@example.test']);
    expect(collect($this->calls->connects)->where('to', '+15125550177')->all())->toBe([])->and(iqCall()->reason)->toBe('The monthly limit of 0 assistant calls was reached.');

    $this->actingAs($this->owner)->get('/leads/settings')->assertInertia(fn (Assert $page) => $page->where('aiCalls.skipped', 2)->where('ai.brand_name', 'Acme Homes'));
});

it('waits for the morning where the lead is', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 04:00', 'UTC')); // 11pm in Austin
    iqLead($this);
    expect($this->calls->connects)->toBe([])->and(iqCall()->status)->toBe('queued');
    $this->travelTo(CarbonImmutable::parse('2026-06-02 14:00', 'UTC'));
    expect(app(AiCaller::class)->place(iqCall()->ulid))->toBeTrue()->and($this->calls->connects)->toHaveCount(1);
});

it('puts them through to the agent, who hears who it is first; if the agent does not take it, the next agent, then a link to pick a time', function (): void {
    $lead = iqLead($this);
    $dial = $this->post($this->calls->connects[0]['on_press'], ['Digits' => '1'])->assertOk()->getContent();
    expect($dial)->toContain('Connecting you with an agent now.')->toContain('timeout="20"')->toContain('>+15125550111</Number>');

    $whisper = $this->post(iqNext($dial, 'url'))->assertOk()->getContent();
    expect($whisper)->toContain('RightAlly transfer: Jane Doe, buying around 78704 (via facebook). Press 1 to take the call.');
    $this->post(iqNext($whisper), ['Digits' => '1'])->assertOk()->assertSee('<Response></Response>', false);
    expect(iqCall()->status)->toBe('transferred')->and((int) DB::connection(Connections::cell())->table('crm_ai_calls')->value('agent_id'))->toBe($this->ann->id)
        ->and($lead->refresh()->responded_by)->toBe($this->ann->id);
    $this->post(iqNext($dial))->assertSee('<Hangup/>', false);

    // A second lead: Ann doesn't take it, Bob doesn't either, so they get the link to pick a time.
    $this->calls->connects = [];
    iqLead($this, ['phone' => '5125550188', 'name' => 'Lee Park', 'email' => 'lee@example.test']);
    $dial = $this->post($this->calls->connects[0]['on_press'], ['Digits' => '1'])->getContent();
    $toBob = $this->post(iqNext($dial), ['DialCallStatus' => 'no-answer'])->getContent();
    expect($toBob)->toContain('>+15125550122</Number>');
    $this->post(iqNext($toBob), ['DialCallStatus' => 'no-answer'])->assertSee('We just sent you a link to pick a time.');
    expect(iqCall()->status)->toBe('booking_sent')->and(collect($this->sms->sent)->pluck('message')->last())->toContain('/book/');
});

it('sends a link to pick a time, and booking it puts a call on the agent\'s calendar and tells them', function (): void {
    iqLead($this);
    $this->post($this->calls->connects[0]['on_press'], ['Digits' => '2'])->assertSee('We just sent you a link to pick a time');
    $text = (string) collect($this->sms->sent)->pluck('message')->last();
    expect($text)->toStartWith('Acme Homes: pick a time to talk with an agent:')->toEndWith('Reply STOP to opt out.');
    preg_match('#(https?://\S+/book/\S+)#', $text, $m);
    $link = $m[1];

    $page = $this->get($link)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Pick a time with Ann')->getContent();
    preg_match('/name="slot" value="([^"]+)"/', $page, $slot);
    $this->post($link, ['slot' => $slot[1]])->assertRedirect();
    $task = Task::query()->where('assigned_to', $this->ann->id)->where('title', 'Call booked from the website')->sole();
    expect(iqCall()->status)->toBe('booked')->and(CarbonImmutable::parse($task->due_at)->toIso8601String())->toBe(CarbonImmutable::parse($slot[1])->utc()->toIso8601String());
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $a): bool => str_starts_with($a->subject, 'Booked: Jane Doe'));
    $this->get($link)->assertSee("You're booked.");
    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $link))->assertForbidden();
});

it('speaks Spanish when they press 3, and stops calls when they press 9', function (): void {
    iqLead($this);
    $es = $this->post($this->calls->connects[0]['on_press'], ['Digits' => '3'])->assertOk()->getContent();
    expect($es)->toContain('voice="Polly.Lupe"')->toContain('Hola Jane, le habla Ava, asistente de inteligencia artificial de Acme Homes.')->toContain('oprima 9');
    $this->post(iqNext($es), ['Digits' => '9'])->assertSee('No recibirá más llamadas de nosotros.');
    expect(Contact::query()->where('primary_phone', '+15125550199')->value('do_not_call'))->toBeTrue()->and(iqCall()->status)->toBe('stopped');

    $this->post(preg_replace('/signature=[^&]+/', 'signature=forged', $this->calls->connects[0]['on_press']), ['Digits' => '1'])->assertForbidden();
});
