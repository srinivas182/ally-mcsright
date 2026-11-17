<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\Voice\VoiceVendor;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Task;
use Modules\Engage\CheckInReplyMail;
use Modules\Engage\DatabaseAssistant;
use Modules\Engage\GamePlan;
use Modules\Engage\Models\CheckIn;
use Modules\Engage\Models\Recognition;
use Modules\Engage\RecognitionMail;
use Modules\Engage\RetentionAlertMail;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    Storage::fake('local');
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'created_at' => CarbonImmutable::parse('2023-06-12')]);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([GamePlan::FEATURE => 'ai.game_coach', DatabaseAssistant::DRAFT => 'ai.checkin_draft', DatabaseAssistant::READ => 'ai.checkin_reply'] as $f => $c) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $c]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    app(CreditLedger::class)->add(null, 100, 'adjust', 'Test credits', $this->owner);
});

function pastClient(User $agent, string $first, string $email): Contact
{
    $c = app(ManageContacts::class)->create(['first_name' => $first, 'last_name' => 'Client', 'email' => $email], $agent);
    $c->forceFill(['lifecycle' => 'past_client', 'last_activity_at' => CarbonImmutable::now()->subMonths(6), 'city' => 'Austin'])->save();

    return $c->fresh();
}

it('shows the game plan and coaches on today from counts only, once a day', function (): void {
    $this->ai->respond(json_encode(['focus' => 'Make your calls before lunch.', 'blocks' => [['when' => '9:00–10:30', 'what' => "Calls from Today's 10"]],
        'encouragement' => 'Steady wins.']));
    pastClient($this->ann, 'Sam', 'sam@x.test');

    $this->actingAs($this->ann)->get('/engage')->assertInertia(fn (Assert $p) => $p->component('engage/index')->where('plan.coach', null)->has('plan.drill.title'));
    $this->actingAs($this->ann)->post('/engage/coach');
    $this->actingAs($this->ann)->post('/engage/coach'); // kept for the day
    expect($this->ai->requests)->toHaveCount(1)
        ->and($this->ai->requests[0]->userMessage.$this->ai->requests[0]->system)->not->toContain('Sam')->toContain('calls_to_make');
    $this->actingAs($this->ann)->get('/engage')->assertInertia(fn (Assert $p) => $p->where('plan.coach.focus', 'Make your calls before lunch.'));
});

it('drafts check-ins for the agent to send, and reads replies into a brief, a task and a suggested answer', function (): void {
    Notification::fake();
    $sam = pastClient($this->ann, 'Sam', 'sam@x.test');
    $kim = pastClient($this->ann, 'Kim', 'kim@x.test');
    app(Consent::class)->setEmail($kim, false, 'agent', $this->ann); // unsubscribed: never drafted
    pastClient($this->owner, 'Other', 'other@x.test'); // someone else's client

    $this->actingAs($this->ann)->post('/engage/assistant', ['active' => true, 'weekly' => 5, 'audience' => 'past_clients']);
    $this->ai->respond(json_encode(['subject' => 'Thinking of you', 'body' => "Hi Sam,\nHow is the garden coming along?\nAnn"]));
    $this->actingAs($this->ann)->post('/engage/assistant/draft');
    $c = CheckIn::query()->sole();
    expect($c->contact_id)->toBe($sam->id)->and($c->status)->toBe('draft');
    Mail::assertNothingSent(); // nothing goes without the agent

    $this->actingAs($this->ann)->post("/engage/checkins/{$c->ulid}/send", ['body' => "Hi Sam,\nHow is the new garden?\nAnn"]);
    expect($c->fresh()->status)->toBe('sent');
    Mail::assertSent(MessageMail::class);

    // Sam replies to the conversation's own address; the reply is read for what he wants.
    $this->ai->respond(json_encode(['intent' => 'sell', 'brief' => 'Sam may sell in the spring and asked what his home is worth.',
        'reply' => "Great to hear from you, Sam! When in spring are you thinking?\nAnn"]));
    app(Inbox::class)->receiveEmail(Conversation::query()->where('contact_id', $sam->id)->sole(), 'sam@x.test', 'Re: Thinking of you', 'Thinking of selling in spring. What is it worth?', 'm-1');
    $c->refresh();
    expect($c->status)->toBe('replied')->and($c->intent)->toBe('sell')->and($c->suggested_reply)->toContain('When in spring')
        ->and(Task::query()->where('contact_id', $sam->id)->where('priority', 'high')->value('title'))->toBe('Reply to Sam: Thinking of selling');
    Notification::assertSentTo($this->ann, CheckInReplyMail::class, fn (CheckInReplyMail $m): bool => $m->intent === 'Thinking of selling');
    $this->actingAs($this->owner)->post("/engage/checkins/{$c->ulid}/skip")->assertNotFound(); // only the agent's own
});

it('records "stop emailing me" as an email opt-out straight away', function (): void {
    Notification::fake();
    $sam = pastClient($this->ann, 'Sam', 'sam@x.test');
    $c = CheckIn::query()->create(['user_id' => $this->ann->id, 'contact_id' => $sam->id, 'status' => 'sent', 'subject' => 'Hi', 'body' => 'Hello', 'sent_at' => now()]);
    $this->ai->respond(json_encode(['intent' => 'unsubscribe', 'brief' => 'Asked not to be emailed.', 'reply' => '']));
    app(Inbox::class)->receiveEmail(app(Inbox::class)->conversationFor($sam), 'sam@x.test', 'Re: Hi', 'Please take me off your list.', 'm-2');

    expect(ConsentEvent::query()->where('contact_id', $sam->id)->where('channel', 'email')->latest('id')->value('action'))->toBe('opt_out')
        ->and($c->fresh()->intent)->toBe('unsubscribe')->and(Task::query()->where('contact_id', $sam->id)->exists())->toBeFalse();
});

it('shows the broker agent activity and sends Monday retention alerts', function (): void {
    Notification::fake();
    $this->actingAs($this->ann)->get('/engage/activity')->assertForbidden();
    $this->ann->forceFill(['last_active_at' => CarbonImmutable::parse('2026-04-01'), 'last_login_at' => CarbonImmutable::parse('2026-04-01')])->save();
    $this->actingAs($this->owner)->get('/engage/activity')->assertInertia(fn (Assert $p) => $p->component('engage/activity')
        ->where('agents.0.name', 'Ann Agent')->where('agents.0.last_active', '2026-04-01')->where('agents.0.risk', fn ($r): bool => $r >= 25));

    $this->travelTo(CarbonImmutable::parse('2026-06-08 08:00'));
    $this->artisan('engage:weekly')->assertSuccessful();
    Notification::assertSentTo($this->owner, RetentionAlertMail::class, fn (RetentionAlertMail $m): bool => $m->agents[0]['name'] === 'Ann Agent');
});

it('lists milestones and sends recognition messages, recorded or in the broker\'s consented AI voice', function (): void {
    Notification::fake();
    $voice = new class implements VoiceVendor
    {
        public array $spoken = [];

        public function stock(): array
        {
            return [];
        }

        public function cloneVoice(string $label, array $files): string
        {
            return 'voice-123';
        }

        public function speak(string $voiceId, string $text): string
        {
            $this->spoken[] = [$voiceId, $text];

            return 'ID3-mp3';
        }

        public function delete(string $voiceId): void {}
    };
    app()->instance(VoiceVendor::class, $voice);

    $this->actingAs($this->owner)->get('/engage/activity')->assertInertia(fn (Assert $p) => $p->where('milestones.0.occasion', 'anniversary')
        ->where('milestones.0.name', 'Ann Agent'));

    // Without consent there is no AI voice.
    $this->actingAs($this->owner)->post('/engage/recognitions', ['agent' => $this->ann->id, 'occasion' => 'anniversary', 'text' => 'Happy 3 years!', 'ai_voice' => true])
        ->assertSessionHasErrors('voice');

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/engage/voice', ['name' => 'olive owner', 'agree' => true,
        'recording' => UploadedFile::fake()->create('consent.webm', 80, 'audio/webm')])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/engage/recognitions', ['agent' => $this->ann->id, 'occasion' => 'anniversary', 'text' => 'Happy 3 years, Ann!', 'ai_voice' => true])
        ->assertSessionHasNoErrors();
    $r = Recognition::query()->sole();
    expect($r->audio_kind)->toBe('ai_voice')->and($voice->spoken)->toBe([['voice-123', 'Happy 3 years, Ann!']]);
    Notification::assertSentTo($this->ann, RecognitionMail::class, fn (RecognitionMail $m): bool => $m->audio === 'ai_voice');

    $this->actingAs($this->ann)->get("/engage/recognitions/{$r->ulid}")->assertInertia(fn (Assert $p) => $p->where('recognition.audio', 'ai_voice'));
    $this->actingAs($this->ann)->get("/engage/recognitions/{$r->ulid}/audio")->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    $this->actingAs(User::factory()->create())->get("/engage/recognitions/{$r->ulid}")->assertNotFound();
    $this->actingAs($this->owner)->get('/engage/activity')->assertInertia(fn (Assert $p) => $p->where('milestones', [])->where('voice', true));

    $this->actingAs($this->owner)->delete('/engage/voice');
    $this->actingAs($this->owner)->get('/engage/activity')->assertInertia(fn (Assert $p) => $p->where('voice', false));
});
