<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\FakeVoiceCaller;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Crm\Voice\CallNotesMail;
use Tests\Fixtures\FakeAiProvider;

/*
| VO1 (specs/grow/14): spoken AI calls through the voice provider (Retell, Vapi or Bland) with a hand-off note for the
| agent; and recorded calls with automatic notes (D08): everyone is told, the call is written down, the AI writes the
| notes and a follow-up task, and the recording is deleted.
*/

const VO_CONSENT = 'I agree to receive calls and text messages, including automated and AI-assisted messages, from the brokerage at this number.';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'tw-token'])),
        'sms_from' => '+15125550100', 'voice_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => true, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $made = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner);
    $this->key = (string) $made['key'];
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    app(AiResponder::class)->saveSettings(['enabled' => false, 'voice_enabled' => true, 'assistant_name' => 'Ava', 'brand_name' => 'Acme Homes', 'max_messages' => 6, 'voice_monthly_cap' => 200], $this->owner);
    app()->instance(SmsSender::class, new FakeSmsSender);
    app()->instance(VoiceCaller::class, $this->calls = new FakeVoiceCaller);

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => 'crm.call_summary', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_call_summary']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
});

/** Connects an outside service at HQ level, in Test or Live mode. */
function voService(string $service, string $provider, array $keys, string $mode, string $contacts = ''): void
{
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => $service, 'provider' => $provider, 'mode' => $mode,
        'test_keys' => Crypt::encryptString((string) json_encode($keys)), 'live_keys' => Crypt::encryptString((string) json_encode($keys)),
        'test_contacts' => $contacts, 'created_at' => now(), 'updated_at' => now()]);
}

function voLead(object $t, string $phone = '5125550199'): Lead
{
    $t->postJson('/api/leads', ['name' => 'Rosa Diaz', 'phone' => $phone, 'email' => 'rosa'.substr($phone, -4).'@example.test', 'intent' => 'buy', 'zip' => '78704',
        'sms_consent' => true, 'consent_text' => VO_CONSENT], ['Authorization' => 'Bearer '.$t->key])->assertSuccessful();

    return Lead::query()->latest('id')->firstOrFail();
}

function voNotes(string $summary, string $next = 'Call Rosa tomorrow afternoon.', string $follow = 'tomorrow', bool $stop = false): string
{
    return (string) json_encode(['summary' => $summary, 'next_step' => $next, 'follow_up' => $follow, 'wants_agent' => true, 'stop_calls' => $stop]);
}

it('has a spoken AI conversation through the voice provider and hands the agent a note', function (): void {
    voService('voice_calls', 'retell', ['api_key' => 'rt-key', 'agent_id' => 'agent_123', 'from_number' => '+15125550100'], 'test', '+1 512 555 0199');
    Http::fake([
        'api.retellai.com/v2/create-phone-call' => Http::response(['call_id' => 'call_abc'], 201),
        'api.retellai.com/v2/get-call/*' => Http::response(['call_status' => 'ended', 'disconnection_reason' => 'user_hangup', 'duration_ms' => 95000,
            'transcript' => "Agent: Hi Rosa, this is Ava for Acme Homes.\nUser: We're moving in March, pre-approved to 650.", 'call_analysis' => ['call_summary' => 'Provider summary.']]),
    ]);
    $this->ai->respond(voNotes('Rosa is moving in March and is pre-approved to $650,000.'));

    $lead = voLead($this);
    expect($this->calls->connects)->toBe([]); // no keypad call: the conversation replaces it
    Http::assertSent(function (HttpRequest $r): bool {
        if (! str_contains($r->url(), 'create-phone-call')) {
            return false;
        }
        $vars = $r['retell_llm_dynamic_variables'];

        return $r['to_number'] === '+15125550199' && $r['override_agent_id'] === 'agent_123' && $vars['first_name'] === 'Rosa' && $vars['brokerage'] === 'Acme Homes'
            && $vars['agent_first_name'] === 'Ann' && $vars['transfer_to'] === '+15125550111' && str_contains($vars['booking_link'], '/book/') && ! isset($vars['email']);
    });

    $call = DB::connection(Connections::cell())->table('crm_ai_calls')->latest('id')->first();
    expect($call->status)->toBe('talked')->and($call->provider)->toBe('retell')->and($call->provider_call_id)->toBe('call_abc')
        ->and(Crypt::decryptString($call->transcript))->toContain('pre-approved to 650')
        ->and(Crypt::decryptString($call->summary))->toBe('Rosa is moving in March and is pre-approved to $650,000.');
    expect($this->ai->requests[0]->userMessage)->toContain('pre-approved to 650'); // the transcript goes in as data

    $contact = $lead->contact;
    expect(Task::query()->where('contact_id', $contact->id)->where('title', 'Call Rosa tomorrow afternoon.')->exists())->toBeTrue();
    Notification::assertSentTo($this->ann, CallNotesMail::class, fn (CallNotesMail $m): bool => $m->summary === 'Rosa is moving in March and is pre-approved to $650,000.');
    $this->actingAs($this->ann)->get('/contacts/'.$contact->ulid)->assertInertia(fn (Assert $p) => $p->where('calls.list.0.kind', 'ai_call')
        ->where('calls.list.0.status', 'talked')->where('calls.list.0.summary', 'Rosa is moving in March and is pre-approved to $650,000.'));
});

it('calls only the test numbers in Test mode, and falls back to the keypad call when no voice provider is connected', function (): void {
    voService('voice_calls', 'vapi', ['api_key' => 'vp-key', 'agent_id' => 'asst_1', 'from_number' => 'pn_1'], 'test', '+15125550100');
    Http::fake();
    voLead($this);
    Http::assertNothingSent();
    expect(DB::connection(Connections::cell())->table('crm_ai_calls')->latest('id')->value('reason'))->toContain('Test mode');

    DB::connection(Connections::control())->table('outside_services')->update(['mode' => 'off']);
    voLead($this, '5125550188');
    expect($this->calls->connects)->toHaveCount(1);
});

it('reads Vapi and Bland results, and marks Do Not Call when they ask for no more calls', function (): void {
    voService('voice_calls', 'bland', ['api_key' => 'bl-key', 'agent_id' => 'pathway_1', 'from_number' => '+15125550100'], 'live');
    Http::fake([
        'api.bland.ai/v1/calls' => Http::response(['status' => 'success', 'call_id' => 'bl_1']),
        'api.bland.ai/v1/calls/bl_1' => Http::response(['completed' => true, 'concatenated_transcript' => 'user: Please stop calling me.', 'summary' => 'Asked to stop.', 'call_length' => 0.5]),
    ]);
    $this->ai->respond(voNotes('Rosa asked not to be called again.', '', 'none', true));
    $lead = voLead($this);
    expect($lead->contact->fresh()->do_not_call)->toBeTrue()
        ->and(Task::query()->where('contact_id', $lead->contact_id)->exists())->toBeFalse();
});

it('makes a recorded call with notes: everyone is told, the call is written down and the recording deleted', function (): void {
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Sam', 'last_name' => 'Ortiz', 'phone' => '512-555-0177', 'source' => 'open_house']);
    $sam = Contact::query()->where('first_name', 'Sam')->sole();
    $this->actingAs($this->ann)->get('/contacts/'.$sam->ulid)->assertInertia(fn (Assert $p) => $p->where('calls.blocker', 'Call notes are not switched on yet (RightAlly HQ turns them on in Outside services).'));
    $this->actingAs($this->ann)->post('/contacts/'.$sam->ulid.'/recorded-call')->assertSessionHasErrors('call');

    voService('transcripts', 'deepgram', ['api_key' => 'dg-key'], 'live');
    Http::fake(['api.deepgram.com/*' => Http::response(['results' => ['utterances' => [['speaker' => 0, 'transcript' => 'Thanks for taking my call, Sam.'],
        ['speaker' => 1, 'transcript' => 'We want to list our house in May.']]]])]);
    $this->ai->respond(voNotes('Sam wants to list their house in May.', 'Send Sam a market analysis.', 'next_week'));

    $this->actingAs($this->ann)->post('/contacts/'.$sam->ulid.'/recorded-call')->assertSessionHasNoErrors();
    expect($this->calls->bridges)->toHaveCount(1)->and($this->calls->bridges[0]['to'])->toBe('+15125550111'); // the agent's phone rings first
    auth()->guard('web')->logout();

    $agentStep = $this->post($this->calls->bridges[0]['on_answer'])->assertOk()->getContent();
    expect($agentStep)->toContain('record="record-from-answer-dual"')->toContain('+15125550177');
    preg_match('/<Number url="([^"]+)"/', (string) $agentStep, $m);
    $this->post(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES))->assertOk()->assertSee('This call is recorded so I can keep accurate notes')->assertSee('Esta llamada se graba');
    $this->post('/webhooks/calls/recorded/'.DB::connection(Connections::cell())->table('crm_recorded_calls')->value('ulid').'/done', ['RecordingSid' => 'RE1'])->assertForbidden(); // unsigned

    preg_match('/recordingStatusCallback="([^"]+)"/', (string) $agentStep, $d);
    $this->post(html_entity_decode($d[1], ENT_XML1 | ENT_QUOTES), ['RecordingSid' => 'RE123', 'RecordingDuration' => '185', 'RecordingStatus' => 'completed'])->assertOk();

    $row = DB::connection(Connections::cell())->table('crm_recorded_calls')->sole();
    expect($row->status)->toBe('written')->and(Crypt::decryptString($row->transcript))->toBe("Speaker 1: Thanks for taking my call, Sam.\nSpeaker 2: We want to list our house in May.")
        ->and($this->calls->deleted)->toBe(['RE123']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'api.deepgram.com/v1/listen') && $r->body() === 'RIFF-fake-audio-RE123');
    $activity = DB::connection(Connections::cell())->table('crm_activities')->where('contact_id', $sam->id)->where('type', 'call')->sole();
    expect($activity->body)->toContain('Sam wants to list their house in May.')->toContain('Next step: Send Sam a market analysis.')->and((int) $activity->duration_seconds)->toBe(240);
    expect(Task::query()->where('contact_id', $sam->id)->where('title', 'Send Sam a market analysis.')->exists())->toBeTrue();
    Notification::assertSentTo($this->ann, CallNotesMail::class);
});

it('lets the agent try again or delete the recording when writing down fails', function (): void {
    voService('transcripts', 'assemblyai', ['api_key' => 'aa-key'], 'live');
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Sam', 'last_name' => 'Ortiz', 'phone' => '512-555-0177', 'source' => 'open_house']);
    $sam = Contact::query()->where('first_name', 'Sam')->sole();
    Http::fake(['api.assemblyai.com/*' => Http::response([], 500)]);
    $this->actingAs($this->ann)->post('/contacts/'.$sam->ulid.'/recorded-call');
    $agentStep = (string) $this->post($this->calls->bridges[0]['on_answer'])->getContent();
    preg_match('/recordingStatusCallback="([^"]+)"/', $agentStep, $d);
    $this->post(html_entity_decode($d[1], ENT_XML1 | ENT_QUOTES), ['RecordingSid' => 'RE9', 'RecordingDuration' => '60']);
    $ulid = (string) DB::connection(Connections::cell())->table('crm_recorded_calls')->value('ulid');
    expect(DB::connection(Connections::cell())->table('crm_recorded_calls')->value('status'))->toBe('failed')->and($this->calls->deleted)->toBe([]);

    $other = User::factory()->create();
    $this->actingAs($other)->post('/contacts/'.$sam->ulid.'/recorded-call/'.$ulid.'/delete')->assertSessionHasErrors();
    $this->actingAs($this->ann)->post('/contacts/'.$sam->ulid.'/recorded-call/'.$ulid.'/delete')->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('crm_recorded_calls')->value('status'))->toBe('deleted')->and($this->calls->deleted)->toBe(['RE9']);
});

it('does not record without the agent\'s phone, or someone on Do Not Call', function (): void {
    voService('transcripts', 'deepgram', ['api_key' => 'dg-key'], 'live');
    $noPhone = User::factory()->create(['phone' => null]);
    $this->actingAs($noPhone)->post('/contacts', ['first_name' => 'Kim', 'phone' => '512-555-0155', 'source' => 'open_house']);
    $kim = Contact::query()->where('first_name', 'Kim')->sole();
    $this->actingAs($noPhone)->post('/contacts/'.$kim->ulid.'/recorded-call')->assertSessionHasErrors(['call' => 'Add your mobile number to your profile first: RightAlly rings you, then them.']);
    $this->travelTo(CarbonImmutable::parse('2026-06-01 03:30', 'UTC')); // 22:30 in Austin
    $this->actingAs($this->ann)->post('/contacts', ['first_name' => 'Lee', 'phone' => '512-555-0156', 'source' => 'open_house']);
    $lee = Contact::query()->where('first_name', 'Lee')->sole();
    $this->actingAs($this->ann)->post('/contacts/'.$lee->ulid.'/recorded-call')->assertSessionHasErrors(['call' => 'It is outside 8am to 9pm where they are.']);
});
