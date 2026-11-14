<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 16B-1: the AI first responder — answers within a minute, qualifies, hands over to a person. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'tw-token'])),
        'sms_from' => '+15125550000', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => false, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $made = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner);
    $this->source = $made['source'];
    $this->key = (string) $made['key'];
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    app(AiResponder::class)->saveSettings(['enabled' => true, 'assistant_name' => 'Ally', 'tone' => 'friendly', 'max_messages' => 6, 'knowledge' => 'We serve Austin and Round Rock.'], $this->owner);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => AiResponder::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_ai_responder']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    Notification::fake();
});

function newLead(object $t, array $extra = []): Lead
{
    $t->postJson('/api/leads', $extra + ['name' => 'Jane Doe', 'phone' => '5125550199', 'intent' => 'buy', 'zip' => '78704', 'sms_consent' => true,
        'consent_text' => 'I agree to receive texts, including automated texts, from the brokerage.'], ['Authorization' => 'Bearer '.$t->key])->assertSuccessful();

    return Lead::query()->latest('id')->firstOrFail();
}

function replyText(object $t, string $body): void
{
    $url = 'http://acme.rightally.app/webhooks/twilio/sms';
    $params = ['From' => '+15125550199', 'To' => '+15125550100', 'Body' => $body, 'MessageSid' => 'SM'.uniqid()];
    $t->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => TwilioSignature::sign($url, $params, 'tw-token')], http_build_query($params))->assertOk();
}

function aiSays(object $t, array $json): void
{
    $t->ai->respond(json_encode($json + ['handoff' => false, 'qualification' => [], 'summary' => '', 'agent_next_message' => '']));
}

it('answers a new lead by text at once, says it is an AI assistant, and does not count as the agent answering', function (): void {
    aiSays($this, ['reply' => 'Thanks for reaching out about homes around 78704! Are you hoping to move in the next few months?', 'qualification' => ['intent' => 'buy']]);
    $lead = newLead($this);

    expect($this->sms->delivered)->toHaveCount(1)
        ->and($this->sms->delivered[0]['message'])->toBe("Hi, this is Ally, Ann's AI assistant at {$this->tenant->name}. Thanks for reaching out about homes around 78704! Are you hoping to move in the next few months?")
        ->and($this->sms->delivered[0]['from'])->toBe('+15125550100');
    $thread = AiThread::query()->sole();
    expect($thread->status)->toBe('active')->and($thread->messages_sent)->toBe(1)->and($thread->qualification)->toBe(['intent' => 'buy'])
        ->and($lead->refresh()->status)->toBe('assigned') // the agent still owes a real conversation
        ->and(Message::query()->sole()->ai_drafted)->toBeTrue();
});

it('keeps the conversation going, then hands a qualified lead to the agent with a summary', function (): void {
    aiSays($this, ['reply' => 'Great! Are you pre-approved for a mortgage yet?']);
    newLead($this);
    aiSays($this, ['reply' => 'Perfect, Ann will be in touch shortly to set up showings.', 'handoff' => true, 'handoff_reason' => 'qualified',
        'qualification' => ['timeline' => 'next month', 'preapproved' => 'yes, $450k'], 'summary' => 'Jane wants to buy near 78704 next month and is pre-approved for $450k.',
        'agent_next_message' => 'Hi Jane, Ann here! Would Saturday morning work to see a few homes?']);
    replyText($this, 'Next month. Pre-approved for 450k');

    $thread = AiThread::query()->sole();
    expect($thread->status)->toBe('handed_off')->and($thread->handoff_reason)->toBe('qualified')->and($thread->messages_sent)->toBe(2)
        ->and($thread->qualification)->toBe(['timeline' => 'next month', 'preapproved' => 'yes, $450k'])
        ->and(Conversation::query()->sole()->needs_reply_since)->not->toBeNull()
        ->and(end($this->sms->delivered)['message'])->toBe('Perfect, Ann will be in touch shortly to set up showings.');
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'Jane Doe: ready to talk'
        && str_contains($n->line, 'pre-approved for $450k') && str_contains($n->line, 'timeline: next month'));

    $this->actingAs($this->ann)->get('/inbox?c='.Conversation::query()->sole()->ulid)->assertInertia(fn (Assert $page) => $page
        ->where('current.ai.status', 'handed_off')->where('current.ai.next_message', 'Hi Jane, Ann here! Would Saturday morning work to see a few homes?'));
});

it('hands over at once when they ask for a person, without asking the AI', function (): void {
    aiSays($this, ['reply' => 'Hi Jane! What area are you looking in?']);
    newLead($this);
    replyText($this, 'Can I talk to a real person please?');

    expect(AiThread::query()->sole()->handoff_reason)->toBe('asked_for_person')
        ->and(end($this->sms->delivered)['message'])->toBe("I'll get Ann for you right away.");
});

it('emails leads without written texting consent, and steps back for good when the agent writes', function (): void {
    Mail::fake();
    aiSays($this, ['reply' => 'Thanks for your note about 12 Oak St. Would you like to see it this week?']);
    $lead = newLead($this, ['email' => 'jane@x.test', 'sms_consent' => false, 'property' => '12 Oak St']);
    expect($this->sms->delivered)->toBe([]);
    Mail::assertSent(MessageMail::class, fn (MessageMail $m): bool => $m->hasTo('jane@x.test') && $m->subjectLine === 'Your inquiry about 12 Oak St' && str_starts_with($m->text, 'Hi, this is Ally'));

    $c = Conversation::query()->sole();
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'subject' => 'Re: 12 Oak St', 'body' => 'Hi Jane, Ann here. Thursday at 5?'])->assertSessionHasNoErrors();
    expect(AiThread::query()->sole()->only(['status', 'handoff_reason']))->toBe(['status' => 'handed_off', 'handoff_reason' => 'agent_replied'])
        ->and($lead->refresh()->status)->toBe('responded');

    // Their next reply goes to Ann, not the AI (no AI answer is queued, so a call would fail the test).
    app(Inbox::class)->receiveEmail($c->refresh(), 'jane@x.test', 'Re: 12 Oak St', 'Thursday works!', 'e-9');
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'New email from Jane Doe');
});

it('never sends wording that fails the Fair Housing check, and stops at the message limit', function (): void {
    aiSays($this, ['reply' => 'It is a very family-friendly street!']);
    newLead($this);
    expect($this->sms->delivered)->toBe([])->and(AiThread::query()->sole()->handoff_reason)->toBe('fair_housing_check');

    app(AiResponder::class)->saveSettings(['enabled' => true, 'max_messages' => 1], $this->owner);
    aiSays($this, ['reply' => 'Hi Sam! Buying or selling?']);
    newLead($this, ['name' => 'Sam Lee', 'phone' => '5125550188']);
    $url = 'http://acme.rightally.app/webhooks/twilio/sms';
    $params = ['From' => '+15125550188', 'To' => '+15125550100', 'Body' => 'Buying', 'MessageSid' => 'SMx'];
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => TwilioSignature::sign($url, $params, 'tw-token')], http_build_query($params));
    expect(AiThread::query()->latest('id')->first()?->handoff_reason)->toBe('message_limit');
});

it('leaves returning contacts, other sources and switched-off brokerages to people', function (): void {
    app(ManageContacts::class)->create(['first_name' => 'Pat', 'phone' => '5125550177'], $this->ann);
    newLead($this, ['name' => 'Pat', 'phone' => '5125550177']);
    expect(AiThread::query()->count())->toBe(0);

    $other = app(LeadSources::class)->create(['name' => 'Website', 'kind' => 'api', 'source' => 'website'], $this->owner)['source'];
    app(AiResponder::class)->saveSettings(['enabled' => true, 'sources' => [$other->ulid]], $this->owner);
    newLead($this, ['name' => 'Lee', 'phone' => '5125550166']);
    expect(AiThread::query()->count())->toBe(0);

    app(AiResponder::class)->saveSettings(['enabled' => false], $this->owner);
    newLead($this, ['name' => 'Kim', 'phone' => '5125550155']);
    expect(AiThread::query()->count())->toBe(0)->and($this->sms->delivered)->toBe([]);
});

it('lets owners and staff set up the assistant', function (): void {
    $this->actingAs($this->owner)->post('/leads/ai', ['enabled' => true, 'assistant_name' => 'Sky', 'tone' => 'professional', 'max_messages' => 30])->assertSessionHasErrors('max_messages');
    $this->actingAs($this->owner)->post('/leads/ai', ['enabled' => true, 'assistant_name' => 'Sky', 'max_messages' => 4, 'knowledge' => 'Great for kids, near a church'])->assertSessionHasErrors('knowledge');
    $this->actingAs($this->owner)->post('/leads/ai', ['enabled' => true, 'assistant_name' => 'Sky', 'tone' => 'professional', 'max_messages' => 4, 'sources' => [$this->source->ulid, 'nope']])->assertSessionHasNoErrors();
    expect(app(AiResponder::class)->settings())->toMatchArray(['assistant_name' => 'Sky', 'tone' => 'professional', 'max_messages' => 4, 'sources' => [$this->source->ulid]]);
    $this->actingAs($this->ann)->post('/leads/ai', ['enabled' => false])->assertSessionHasErrors('ai');
    $this->actingAs($this->owner)->get('/leads/settings')->assertInertia(fn (Assert $page) => $page->where('ai.assistant_name', 'Sky'));
});

it('does not text at night where the lead is; with no email it hands the lead to the agent', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 04:00', 'UTC')); // 11pm in Austin
    newLead($this);
    expect($this->sms->delivered)->toBe([])->and(AiThread::query()->sole()->handoff_reason)->toBe('cannot_message');
});
