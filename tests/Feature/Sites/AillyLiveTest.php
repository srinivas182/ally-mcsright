<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\Ailly\BookingTexts;
use Modules\Sites\Ailly\Experiments;
use Modules\Sites\Jobs\RemindBooking;
use Modules\Sites\SiteSetup;
use Modules\Sites\Visitors\ClosingBrief;
use Tests\Fixtures\FakeAiProvider;

/* Sprint 17D-4: an agent joins a live AIlly chat, the chat continues by text, A/B tests of the greeting and gate. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC')); // 10:00 in Austin
    Notification::fake();
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['credentials' => Crypt::encryptString(json_encode(['sid' => 'AC'.str_repeat('1', 32), 'token' => 'tw-token'])),
        'sms_from' => '+15125550000', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $this->tenant->id, 'sms' => true, 'voice' => false, 'sms_number' => '+15125550100', 'created_at' => now(), 'updated_at' => now()]);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);

    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia']);
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    app(LeadRouting::class)->saveRule(['name' => 'Jane', 'method' => 'agent', 'agent_ids' => [$this->jane->id]], $this->owner);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([Assistant::FEATURE => 'ai.ailly', ClosingBrief::FEATURE => 'ai.closing_brief', AiResponder::FEATURE => 'ai.crm_ai_responder'] as $f => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    AillySettings::save($this->site, ['enabled' => true, 'gate' => 'booking', 'team' => [$this->jane->id]], $this->owner);
});

function liveStart(string $vid = 'vid_live_00000000000'): string
{
    return (string) test()->postJson(test()->web.'/_ailly/start', ['vid' => $vid, 'page' => '/homes'])->json('conversation');
}

function liveSay(string $c, string $text, ?string $reply = null): TestResponse
{
    if ($reply !== null) {
        test()->ai->respond((string) json_encode(['reply' => $reply, 'on_topic' => true, 'next_step' => 'none', 'lead_score' => 50, 'answered' => true]));
    }

    return test()->postJson(test()->web.'/_ailly/message', ['conversation' => $c, 'message' => $text]);
}

function liveContact(string $c, array $extra = []): TestResponse
{
    return test()->postJson(test()->web.'/_ailly/contact', ['conversation' => $c, 'name' => 'Maria Lopez', 'email' => 'maria@example.test', 'phone' => '5125550199',
        'sms_consent' => true, 'vid' => 'vid_live_00000000000'] + $extra);
}

it('lets the lead\'s agent join a live chat: AIlly goes quiet, the agent replies in the widget, then hands back', function (): void {
    $c = liveStart();
    liveSay($c, 'Do you have 3 bed homes near 78704?', 'Yes, a few. Shall I show you?');
    liveContact($c)->assertOk();

    $this->actingAs($this->jane)->get('/ailly/chats')->assertInertia(fn (Assert $p) => $p->component('ailly/chats')->where('live.0.ulid', $c));
    $this->actingAs($this->jane)->get('/ailly/chats/'.$c)->assertInertia(fn (Assert $p) => $p->component('ailly/chat')->where('chat.live', true)->where('chat.can_take', true)
        ->where('chat.taken_by', null));
    $this->actingAs($this->bob)->get('/ailly/chats/'.$c)->assertNotFound(); // not his lead or website

    $this->actingAs($this->jane)->post('/ailly/chats/'.$c.'/take')->assertSessionHasNoErrors();
    $asked = count($this->ai->requests);
    liveSay($c, 'Can I see one Saturday?')->assertOk()->assertJson(['agent' => true, 'reply' => '']);
    expect(count($this->ai->requests))->toBe($asked); // no AI while an agent has it
    Notification::assertSentTo($this->jane, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'New message in your website chat' && str_ends_with($n->url, '/ailly/chats/'.$c));

    $this->actingAs($this->jane)->post('/ailly/chats/'.$c.'/say', ['body' => 'Hi Maria, Saturday at 11 works. <b>See you</b>'])->assertSessionHasNoErrors();
    $poll = $this->postJson($this->web.'/_ailly/poll', ['conversation' => $c, 'after' => 0])->assertOk();
    expect($poll->json('agent'))->toBe('Jane')
        ->and(collect($poll->json('messages'))->map(fn (array $m): array => [$m['role'], $m['body'], $m['by']])->all())->toBe([
            ['note', 'Jane from the team joined the chat.', null],
            ['agent', 'Hi Maria, Saturday at 11 works. See you', 'Jane'],
        ]);
    $after = (int) collect($poll->json('messages'))->last()['id'];
    expect($this->postJson($this->web.'/_ailly/poll', ['conversation' => $c, 'after' => $after])->json('messages'))->toBe([]);

    $this->actingAs($this->bob)->post('/ailly/chats/'.$c.'/say', ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($this->owner)->get('/ailly/chats/'.$c)->assertInertia(fn (Assert $p) => $p->where('chat.taken_by.name', 'Jane Doe')->where('chat.can_take', true));

    $this->actingAs($this->jane)->post('/ailly/chats/'.$c.'/hand-back')->assertSessionHasNoErrors();
    liveSay($c, 'Thanks! Any with a pool?', 'Let me look for homes with a pool.')->assertJsonPath('reply', 'Let me look for homes with a pool.');
    expect((string) json_encode(end($this->ai->requests)))->toContain('Agent (a person on the team): Hi Maria');

    $this->travel(11)->minutes();
    $this->actingAs($this->jane)->get('/ailly/chats')->assertInertia(fn (Assert $p) => $p->where('live', []));
});

it('links hot-lead alerts to the live chat', function (): void {
    $c = liveStart();
    liveSay($c, 'We are pre-approved and want to see homes this week', 'Great, an agent can help.');
    liveContact($c, ['want' => 'call'])->assertOk();
    Notification::assertSentTo($this->jane, LeadAlert::class, fn (LeadAlert $n): bool => $n->subject === 'Hot lead from your website chat' && str_ends_with($n->url, '/ailly/chats/'.$c)
        && $n->action === 'Join the chat');
});

it('continues the chat by text in the assistant\'s name, and texts a booking confirmation instead of qualifying', function (): void {
    app(AiResponder::class)->saveSettings(['enabled' => true, 'assistant_name' => 'Ally', 'tone' => 'friendly', 'max_messages' => 6, 'knowledge' => ''], $this->owner);
    $c = liveStart();
    liveSay($c, 'Looking for a 3 bed near 78704 under 500k', 'Here are a few that match.');
    $this->ai->respond((string) json_encode(['reply' => 'Want me to send three more like the Elm St home?', 'handoff' => false, 'qualification' => ['area' => '78704'], 'summary' => '', 'agent_next_message' => '']));
    liveContact($c)->assertOk();

    $thread = AiThread::query()->sole();
    expect($thread->only(['origin', 'assistant_name']))->toBe(['origin' => 'website_chat', 'assistant_name' => AillySettings::DEFAULT_NAME])
        ->and($this->sms->delivered[0]['message'] ?? $this->sms->delivered[0][2] ?? null)->toStartWith("Hi, it's Ask Ally, the AI assistant from the")
        ->and((string) json_encode($this->ai->requests))->toContain('yes: they were just chatting with you on the website');

    // A visitor who books a time: a confirmation text and a reminder, no qualifying texts.
    Queue::fake([RemindBooking::class]);
    $wed = ['mon' => [], 'tue' => [], 'wed' => [['14:00', '16:00']], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []];
    app(Availability::class)->save($this->jane, true, $wed, 30, 1, $this->jane);
    $c2 = liveStart('vid_live_11111111111');
    $slot = $this->postJson($this->web.'/_ailly/slots', ['kind' => 'call'])->json('slots.0');
    $this->postJson($this->web.'/_ailly/contact', ['conversation' => $c2, 'name' => 'Sam Lee', 'phone' => '5125550177', 'sms_consent' => true, 'vid' => 'vid_live_11111111111',
        'want' => 'call', 'slot' => $slot['start'], 'slot_agent' => $slot['agent']])->assertOk();

    expect(AiThread::query()->count())->toBe(1);
    $text = (string) json_encode(end($this->sms->delivered));
    expect($text)->toContain('Ask Ally (AI assistant): your call with Jane is booked for Wed Jun 3 at 2:00 PM CDT')->toContain('STOP');
    $task = Task::query()->where('type', 'call')->sole();
    Queue::assertPushed(RemindBooking::class, fn (RemindBooking $j): bool => $j->taskId === $task->id);

    $sent = count($this->sms->delivered);
    BookingTexts::remind($task->id, 'Ask Ally');
    expect(count($this->sms->delivered))->toBe($sent + 1)
        ->and((string) json_encode(end($this->sms->delivered)))->toContain('Reminder from Ask Ally: your call with Jane is today at 2:00 PM CDT');
    $task->forceFill(['completed_at' => now()])->save();
    BookingTexts::remind($task->id, 'Ask Ally');
    expect(count($this->sms->delivered))->toBe($sent + 1); // done or moved: no reminder
});

it('A/B tests a greeting and gate, with a plain verdict, and makes the winner the setting', function (): void {
    $this->actingAs($this->owner)->post('/settings/ailly/test', ['greeting_b' => ''])->assertSessionHasErrors('greeting_b');
    $this->actingAs($this->owner)->post('/settings/ailly/test', ['greeting_b' => 'Hi! Looking for a safe neighborhood?'])->assertSessionHasErrors('greeting_b');
    $this->actingAs($this->owner)->post('/settings/ailly/test', ['greeting_b' => 'Hi! Tell me what you want in a home and I will find it.', 'gate_b' => 'before'])
        ->assertSessionHasNoErrors();

    $seen = [];
    foreach (range(1, 12) as $i) {
        RateLimiter::clear('ailly-start:127.0.0.1');
        $vid = 'vid_ab_'.str_pad((string) $i, 13, '0', STR_PAD_LEFT);
        $r = $this->postJson($this->web.'/_ailly/start', ['vid' => $vid, 'page' => '/'])->assertOk();
        $v = Experiments::variant($this->site->refresh(), $vid);
        $seen[$v] = true;
        expect($r->json('greeting'))->toBe($v === 'b' ? 'Hi! Tell me what you want in a home and I will find it.' : AillySettings::greeting(AillySettings::for($this->site), 'Ask Ally'))
            ->and($r->json('needs_contact'))->toBe($v === 'b');
        expect($this->postJson($this->web.'/_ailly/start', ['vid' => $vid, 'page' => '/'])->json('greeting'))->toBe($r->json('greeting')); // sticky
    }
    expect(array_keys($seen))->toEqualCanonicalizing(['a', 'b']);
    $c = (string) $this->postJson($this->web.'/_ailly/start', ['vid' => 'vid_ab_0000000000001', 'page' => '/'])->json('conversation');
    if (Experiments::variant($this->site, 'vid_ab_0000000000001') === 'b') {
        liveSay($c, 'Hi')->assertJsonPath('needs_contact', true); // B asks before answering
    }

    // Enough chats for a verdict: B turns 30 in 100 into leads, A 10 in 100.
    $rows = [];
    foreach (['a' => 12, 'b' => 36] as $v => $leads) {
        foreach (range(1, 120) as $i) {
            $rows[] = ['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'variant' => $v, 'outcome' => $i <= $leads ? 'lead' : 'none',
                'created_at' => now(), 'updated_at' => now()];
        }
    }
    DB::connection(Connections::cell())->table('ailly_conversations')->insert($rows);
    $this->actingAs($this->owner)->get('/settings/ailly')->assertInertia(fn (Assert $p) => $p->where('test.on', true)->where('test_results.winner', 'b')
        ->where('test_results.verdict', fn (string $v): bool => preg_match('/^Version B turns [0-9.]+ more chats in every 100 into leads, and that is very unlikely to be chance\.$/', $v) === 1));

    $this->actingAs($this->owner)->post('/settings/ailly/test/stop', ['use_b' => true])->assertSessionHasNoErrors();
    $s = AillySettings::for($this->site->refresh());
    expect($s['greeting'])->toBe('Hi! Tell me what you want in a home and I will find it.')->and($s['gate'])->toBe('before')
        ->and(Experiments::for($this->site)['on'])->toBeFalse()
        ->and(Experiments::variant($this->site, 'vid_x'))->toBeNull();
});

it('says when there is no clear winner yet', function (): void {
    expect(Experiments::verdict(50, 5, 50, 9)[1])->toBeNull()
        ->and(Experiments::verdict(200, 20, 200, 24))->toBe(['No clear winner yet: the difference could be chance. Keep it running, or keep what you have.', null])
        ->and(Experiments::verdict(200, 60, 200, 30)[1])->toBe('a');
});

it('stops agents from taking a chat another agent already has', function (): void {
    $c = liveStart();
    liveSay($c, 'Hello', 'Hi there!');
    liveContact($c)->assertOk();
    Lead::query()->sole()->forceFill(['assigned_user_id' => $this->bob->id])->save();
    DB::connection(Connections::cell())->table('ailly_conversations')->where('ulid', $c)->update(['taken_by' => $this->jane->id]);
    $this->actingAs($this->bob)->get('/ailly/chats/'.$c)->assertInertia(fn (Assert $p) => $p->where('chat.can_take', false));
    $this->actingAs($this->bob)->post('/ailly/chats/'.$c.'/take')->assertSessionHasErrors('chat');
});
