<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\TwilioSignature;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 16A-2: unified inbox — two-way texts and email, checks before every send, sign codes, missed-reply alerts. */

const TWILIO_TOKEN = 'twilio-auth-token-for-tests';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10:00 in Austin
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => TWILIO_TOKEN])),
        'sms_from' => '+15125550000', 'voice_from' => '+15125550000', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => false, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $this->jane = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.test', 'phone' => '5125550199'], $this->ann);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    Notification::fake();
});

function twilio(object $t, array $params): TestResponse
{
    $url = 'http://acme.rightally.app/webhooks/twilio/sms';
    $params += ['To' => '+15125550100', 'MessageSid' => 'SM'.uniqid()];

    // Raw form body, exactly as Twilio sends it.
    return $t->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_TWILIO_SIGNATURE' => TwilioSignature::sign($url, $params, TWILIO_TOKEN)], http_build_query($params));
}

it('sends a text from the brokerage number once the contact has consent, and it answers their lead', function (): void {
    app(Consent::class)->optInSms($this->jane, 'web_form', 'I agree to texts.');
    $lead = Lead::query()->create(['contact_id' => $this->jane->id, 'source' => 'website', 'channel' => 'direct', 'status' => 'assigned', 'assigned_user_id' => $this->ann->id, 'assigned_at' => now()]);
    $conversation = app(Inbox::class)->conversationFor($this->jane);
    $this->travel(2)->minutes();

    $this->actingAs($this->ann)->post("/inbox/{$conversation->ulid}/send", ['channel' => 'sms', 'body' => 'Hi Jane, Saturday at 10 works for 12 Oak St?'])->assertSessionHasNoErrors();
    expect($this->sms->delivered)->toBe([['from' => '+15125550100', 'to' => '+15125550199', 'message' => 'Hi Jane, Saturday at 10 works for 12 Oak St?']])
        ->and(Message::query()->sole()->only(['channel', 'direction', 'status', 'body']))->toBe(['channel' => 'sms', 'direction' => 'out', 'status' => 'sent', 'body' => 'Hi Jane, Saturday at 10 works for 12 Oak St?'])
        ->and($lead->refresh()->status)->toBe('responded')->and($lead->response_seconds)->toBe(120)
        ->and(DB::table('crm_activities')->where('contact_id', $this->jane->id)->where('type', 'text')->where('direction', 'out')->exists())->toBeTrue();

    // A failed delivery is reported, not silently lost.
    $this->sms->fail = true;
    $this->actingAs($this->ann)->post("/inbox/{$conversation->ulid}/send", ['channel' => 'sms', 'body' => 'Hello again'])->assertSessionHasErrors('body');
    expect(Message::query()->where('status', 'failed')->count())->toBe(1);
});

it('blocks texts without consent, after STOP, on Do Not Call, at night where they are, and with Fair Housing wording', function (): void {
    $guard = app(SendGuard::class);
    expect($guard->check($this->jane, 'sms', 'Hi'))->toBe(['No texting consent yet. Email them, or text once they have texted you or given consent on a form.']);

    app(Consent::class)->optInSms($this->jane, 'web_form', 'I agree to texts.');
    expect($guard->check($this->jane->refresh(), 'sms', 'Hi'))->toBe([])
        ->and($guard->check($this->jane, 'sms', 'A family-friendly street'))->toBe(['Fair Housing: reword "family-friendly" (describe the home, not who should live there).']);

    // 9:30pm in New York is 8:30pm in Austin: the New York number is blocked, Austin's is not.
    $at = CarbonImmutable::parse('2026-06-02 01:30', 'UTC');
    expect(SendGuard::quietHours('+12125550100', $at))->toBe('It is 9:30 pm where they are. Texts go between 8am and 9pm their time.')
        ->and(SendGuard::quietHours('+15125550199', $at))->toBeNull()
        ->and(SendGuard::quietHours('+18005550100', CarbonImmutable::parse('2026-06-01 12:30', 'UTC')))->not->toBeNull(); // 5:30am in California

    app(Consent::class)->setDoNotCall($this->jane, true);
    expect($guard->check($this->jane->refresh(), 'sms', 'Hi'))->toBe(['They are on Do Not Call.']);
    app(Consent::class)->setDoNotCall($this->jane, false);
    app(Consent::class)->optOutSms($this->jane->refresh(), 'agent');
    expect($guard->check($this->jane->refresh(), 'sms', 'Hi'))->toBe(['They opted out of texts (replied STOP or asked not to be texted).']);

    app(Consent::class)->setEmail($this->jane, false, 'unsubscribe_link');
    expect($guard->check($this->jane->refresh(), 'email', 'Hi'))->toBe(['They unsubscribed from email.']);

    $c = app(Inbox::class)->conversationFor($this->jane);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'sms', 'body' => 'Hi'])->assertSessionHasErrors('body');
    expect($this->sms->delivered)->toBe([]);
});

it('takes incoming texts with a valid Twilio signature, and lets the agent reply to someone who texted first', function (): void {
    $lead = Lead::query()->create(['contact_id' => $this->jane->id, 'source' => 'website', 'channel' => 'direct', 'status' => 'responded']);
    twilio($this, ['From' => '+15125550199', 'Body' => 'Is 12 Oak still available?', 'MessageSid' => 'SM1'])->assertOk()->assertSee('<Response></Response>', false);
    twilio($this, ['From' => '+15125550199', 'Body' => 'Is 12 Oak still available?', 'MessageSid' => 'SM1'])->assertOk(); // Twilio retry

    $conversation = Conversation::query()->sole();
    expect(Message::query()->where('direction', 'in')->count())->toBe(1)->and($conversation->unread)->toBeTrue()->and($conversation->needs_reply_since)->not->toBeNull()
        ->and($conversation->assigned_user_id)->toBe($this->ann->id)->and($lead->refresh()->engaged_at)->not->toBeNull();
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'New text from Jane Doe');

    // They texted first: a one-to-one reply is allowed without a consent form.
    $this->actingAs($this->ann)->post("/inbox/{$conversation->ulid}/send", ['channel' => 'sms', 'body' => 'Yes! Want to see it Saturday?'])->assertSessionHasNoErrors();
    expect($conversation->refresh()->needs_reply_since)->toBeNull();

    $this->post('http://acme.rightally.app/webhooks/twilio/sms', ['From' => '+15125550199', 'To' => '+15125550100', 'Body' => 'x'], ['X-Twilio-Signature' => 'forged'])->assertForbidden();
    twilio($this, ['From' => '+19995550000', 'Body' => 'who is this?'])->assertOk();
    expect(Contact::query()->count())->toBe(1);
});

it('stops texts at STOP everywhere and resumes manual texts at START', function (): void {
    app(Consent::class)->optInSms($this->jane, 'web_form', 'I agree to texts.');
    twilio($this, ['From' => '+15125550199', 'Body' => 'Stop']);
    $jane = $this->jane->refresh();
    expect($jane->sms_opt_in)->toBeFalse()->and(app(SendGuard::class)->check($jane, 'sms', 'Hi'))->toContain('They opted out of texts (replied STOP or asked not to be texted).')
        ->and(DB::table('crm_consent_events')->where('contact_id', $jane->id)->where('action', 'opt_out')->value('method'))->toBe('sms_keyword');

    $this->travel(1)->minutes();
    twilio($this, ['From' => '+15125550199', 'Body' => 'START']);
    $jane = $this->jane->refresh();
    expect($jane->sms_opt_in)->toBeTrue()->and(app(SendGuard::class)->check($jane, 'sms', 'Hi'))->toBe([])
        ->and(app(Consent::class)->canAutoText($jane))->toBeFalse(); // automated texts still need written consent
});

it('routes replies to the shared platform number back to the brokerage that texted them', function (): void {
    DB::connection(Connections::control())->table('hq_messaging_tenants')->update(['sms_number' => null]);
    app(Consent::class)->optInSms($this->jane, 'web_form', 'I agree to texts.');
    $c = app(Inbox::class)->conversationFor($this->jane);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'sms', 'body' => 'Hi Jane'])->assertSessionHasNoErrors();
    expect($this->sms->delivered[0]['from'])->toBe('+15125550000');

    twilio($this, ['From' => '+15125550199', 'To' => '+15125550000', 'Body' => 'Thanks!'])->assertOk();
    expect(Message::query()->where('direction', 'in')->sole()->body)->toBe('Thanks!');
});

it('emails from the inbox with a reply address, and files the reply without the quoted history', function (): void {
    Mail::fake();
    config(['crm.inbound_email_secret' => 'inbound-secret']);
    $c = app(Inbox::class)->conversationFor($this->jane);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'subject' => '12 Oak St', 'body' => 'Hi Jane, here are the details.'])->assertSessionHasNoErrors();
    $address = Inbox::replyAddress($c);
    Mail::assertSent(MessageMail::class, fn (MessageMail $m): bool => $m->hasTo('jane@x.test') && $m->hasReplyTo($address) && $m->fromName === 'Ann Agent · '.$this->tenant->name);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'body' => 'No subject'])->assertSessionHasErrors('body');

    $this->withBasicAuth('postmark', 'inbound-secret')->postJson('/webhooks/inbound/lead-email', ['OriginalRecipient' => $address, 'From' => 'Jane Doe <jane@x.test>', 'Subject' => 'Re: 12 Oak St',
        'MessageID' => 'e-1', 'TextBody' => "Thanks Ann, can we see it Sunday?\n\nOn Mon, Jun 1, 2026 at 10:00 AM Ann Agent wrote:\n> Hi Jane, here are the details."])
        ->assertOk()->assertJson(['status' => 'received']);
    $in = Message::query()->where('direction', 'in')->sole();
    expect($in->body)->toBe('Thanks Ann, can we see it Sunday?')->and($in->channel)->toBe('email')->and($c->refresh()->needs_reply_since)->not->toBeNull();
});

it('keeps personal and shared templates, fills merge fields and refuses unknown ones', function (): void {
    $this->actingAs($this->ann)->post('/inbox/templates', ['name' => 'Tour', 'channel' => 'sms', 'body' => 'Hi {first_name}, {agent_first_name} here. Want to tour {property}?'])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->post('/inbox/templates', ['name' => 'Bad', 'channel' => 'sms', 'body' => 'Hi {nickname}'])->assertSessionHasErrors('body');
    $this->actingAs($this->ann)->post('/inbox/templates', ['name' => 'Shared', 'channel' => 'sms', 'body' => 'Hi', 'shared' => true])->assertSessionHasErrors('shared');
    $this->actingAs($this->owner)->post('/inbox/templates', ['name' => 'Welcome', 'channel' => 'email', 'subject' => 'Welcome {first_name}', 'body' => 'From all of us at {brokerage}.', 'shared' => true])->assertSessionHasNoErrors();
    Lead::query()->create(['contact_id' => $this->jane->id, 'source' => 'website', 'channel' => 'direct', 'status' => 'responded', 'property' => '12 Oak St']);

    $c = app(Inbox::class)->conversationFor($this->jane);
    $this->actingAs($this->ann)->get('/inbox?c='.$c->ulid)->assertInertia(fn (Assert $page) => $page->component('crm/inbox/index')
        ->where('current.templates', fn ($t) => collect($t)->pluck('body')->sort()->values()->all() === ['From all of us at '.$this->tenant->name.'.', 'Hi Jane, Ann here. Want to tour 12 Oak St?'])
        ->where('current.blocks.sms', ['No texting consent yet. Email them, or text once they have texted you or given consent on a form.'])
        ->where('current.blocks.email', []));
});

it('answers sign-code texts with the home details and makes a lead for the listing agent', function (): void {
    $bob = User::factory()->create(['first_name' => 'Bob']);
    $this->actingAs($bob)->post('/inbox/signs', ['code' => 'oak12', 'property' => '12 Oak St', 'details' => '3 bed, 2 bath, $450,000. Photos: https://acme.example/oak'])->assertSessionHasNoErrors();
    $this->actingAs($bob)->post('/inbox/signs', ['code' => 'STOP', 'property' => 'x', 'details' => 'y'])->assertSessionHasErrors('code');
    $this->actingAs($bob)->post('/inbox/signs', ['code' => 'OAK12', 'property' => 'x', 'details' => 'y'])->assertSessionHasErrors('code');

    twilio($this, ['From' => '+15125550177', 'Body' => ' oak12 '])->assertOk()
        ->assertSee('<Message>12 Oak St: 3 bed, 2 bath, $450,000. Photos: https://acme.example/oak — Bob, '.htmlspecialchars($this->tenant->name, ENT_XML1 | ENT_QUOTES).' Reply STOP to opt out.</Message>', false);
    $lead = Lead::query()->sole();
    $contact = $lead->contact;
    expect($lead->source)->toBe('sign')->and($lead->assigned_user_id)->toBe($bob->id)->and($lead->property)->toBe('12 Oak St')
        ->and($contact?->primary_phone)->toBe('+15125550177')->and($contact?->sms_opt_in)->toBeFalse()
        ->and(Message::query()->where('contact_id', $contact?->id)->orderBy('id')->pluck('direction')->all())->toBe(['in', 'out'])
        ->and(DB::table('crm_sign_codes')->value('texts'))->toBe(1);
    Notification::assertSentTo($bob, LeadAlert::class);
});

it('drafts a reply with AI for the agent to edit, flagging Fair Housing wording', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Inbox::DRAFT, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_reply_draft']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    twilio($this, ['From' => '+15125550199', 'Body' => 'Is the area good for kids?']);
    $c = Conversation::query()->sole();

    $ai->respond(json_encode(['draft' => 'Hi Jane! It is a family-friendly street. Want to tour Saturday?']));
    $this->actingAs($this->ann)->postJson("/inbox/{$c->ulid}/draft", ['channel' => 'sms'])->assertOk()
        ->assertJson(['draft' => 'Hi Jane! It is a family-friendly street. Want to tour Saturday?', 'warnings' => ['family-friendly']]);
    $ai->fail(new RuntimeException('down'));
    $this->actingAs($this->ann)->postJson("/inbox/{$c->ulid}/draft", ['channel' => 'sms'])->assertUnprocessable();
});

it('nudges the agent after 15 minutes without a reply and puts an hour on the broker risk register', function (): void {
    twilio($this, ['From' => '+15125550199', 'Body' => 'Hello?']);
    $this->travel(16)->minutes();
    $this->artisan('crm:inbox-sweep')->assertSuccessful();
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'Jane Doe is waiting for your reply');
    $this->artisan('crm:inbox-sweep');
    Notification::assertSentToTimes($this->ann, LeadAlert::class, 2); // the new-text alert and one nudge

    $this->travel(45)->minutes();
    $this->artisan('crm:inbox-sweep');
    expect(Conversation::query()->sole()->escalated_at)->not->toBeNull();
    $this->actingAs($this->owner)->get('/deals/risk')->assertInertia(fn (Assert $page) => $page
        ->where('risks', fn ($r) => collect($r)->contains(fn ($x) => $x['category'] === 'Leads' && str_starts_with($x['title'], 'Jane Doe has waited since') && $x['owner'] === 'Ann Agent')));
});

it('shows agents only conversations with contacts they can see', function (): void {
    $bob = User::factory()->create();
    twilio($this, ['From' => '+15125550199', 'Body' => 'Hi']);
    $c = Conversation::query()->sole();
    $this->actingAs($bob)->get('/inbox?c='.$c->ulid)->assertInertia(fn (Assert $page) => $page->has('conversations', 0)->where('current', null));
    $this->actingAs($bob)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'subject' => 'x', 'body' => 'x'])->assertNotFound();
    $this->actingAs($this->ann)->get('/inbox?filter=needs_reply')->assertInertia(fn (Assert $page) => $page->has('conversations', 1)->where('conversations.0.needs_reply', true));
    $this->actingAs($this->ann)->get('/inbox/start/'.$this->jane->ulid)->assertRedirect('/inbox?c='.$c->ulid);
});

it('sends the inbox page the data it needs', function (): void {
    assertPageContract($this->actingAs($this->ann)->get('/inbox'), 'crm/inbox/index');
});
