<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\MessageMail;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Leads\LongNurture;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;
use Modules\Crm\Models\Nurture;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/* Sprint LN1: Lead-IQ long nurture — 12 months of AI check-ins for quiet leads, timed by what they do, handed to
   the agent the moment they reply, stopped the moment a person writes. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Mail::fake();
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $made = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner);
    $this->key = (string) $made['key'];
    app(LeadRouting::class)->saveRule(['name' => 'Ann', 'method' => 'agent', 'agent_ids' => [$this->ann->id]], $this->owner);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => LongNurture::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_nurture']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->owner)->post('/leads/ai', ['enabled' => false, 'nurture_enabled' => true, 'assistant_name' => 'Ally', 'tone' => 'friendly', 'max_messages' => 6])
        ->assertSessionHasNoErrors();
    auth()->logout();
    $this->postJson('/api/leads', ['name' => 'Jane Doe', 'email' => 'jane@x.test', 'intent' => 'buy', 'zip' => '78704'], ['Authorization' => 'Bearer '.$this->key])->assertSuccessful();
    $this->contact = Contact::query()->sole();
});

function nurtureSays(object $t, string $reply): void
{
    $t->ai->respond((string) json_encode(['reply' => $reply]));
}

it('starts after a week of quiet, checks in by email, and hands the lead to the agent when they reply', function (): void {
    $n = app(LongNurture::class);
    expect(app(AiResponder::class)->settings()['nurture_enabled'])->toBeTrue()->and($n->enrol())->toBe(0); // not quiet for a week yet

    $this->travelTo(CarbonImmutable::parse('2026-06-09 15:00', 'UTC'));
    expect($n->enrol())->toBe(1)->and($n->enrol())->toBe(0);
    nurtureSays($this, 'Hi Jane, this is Ally, Ann\'s AI assistant at Acme. Still thinking about a move in 78704?');
    expect($n->runDue())->toBe(1);
    Mail::assertSent(MessageMail::class, fn (MessageMail $m): bool => $m->hasTo('jane@x.test') && $m->subjectLine === 'Checking in' && str_contains($m->text, 'Still thinking'));
    $row = Nurture::query()->sole();
    expect($row->only(['status', 'touches', 'unanswered', 'last_reason']))->toBe(['status' => 'active', 'touches' => 1, 'unanswered' => 1, 'last_reason' => 'check_in'])
        ->and($row->next_at->toDateString())->toBe('2026-06-16')->and($row->ends_on->toDateString())->toBe('2027-06-01')
        ->and($n->runDue())->toBe(0); // not due again yet

    $c = Conversation::query()->sole();
    $this->actingAs($this->ann)->get('/inbox?c='.$c->ulid)->assertInertia(fn (Assert $p) => $p->where('current.nurture.status', 'active')->where('current.nurture.touches', 1));
    auth()->logout();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 13:00', 'UTC'));
    app(Inbox::class)->receiveEmail($c, 'jane@x.test', 'Re: Checking in', 'Yes! We want to start looking next month.', 'e-1');
    expect(Nurture::query()->sole()->status)->toBe('replied');
    $this->travelTo(CarbonImmutable::parse('2026-06-16 15:00', 'UTC'));
    expect($n->runDue())->toBe(0);
    Notification::assertSentTo($this->ann, LeadAlert::class, fn (LeadAlert $a): bool => $a->subject === 'Jane Doe replied after 1 follow-up(s)');
});

it('stops the moment a person writes to them, or when anyone presses Stop', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 15:00', 'UTC'));
    app(LongNurture::class)->enrol();
    $c = app(Inbox::class)->conversationFor($this->contact);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/send", ['channel' => 'email', 'subject' => 'Hi', 'body' => 'Hi Jane, Ann here.'])->assertSessionHasNoErrors();
    expect(Nurture::query()->sole()->only(['status', 'stopped_reason']))->toBe(['status' => 'stopped', 'stopped_reason' => 'agent_replied'])
        ->and(app(LongNurture::class)->runDue())->toBe(0);

    Nurture::query()->update(['status' => 'active', 'stopped_reason' => null]);
    $this->actingAs($this->ann)->post("/inbox/{$c->ulid}/nurture/stop")->assertRedirect();
    expect(Nurture::query()->sole()->stopped_reason)->toBe('stopped_by_agent');
});

it('leaves alone clients, people in a conversation, and brokerages that have not switched it on', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 15:00', 'UTC'));
    $this->contact->forceFill(['lifecycle' => Lifecycle::Active])->save();
    expect(app(LongNurture::class)->enrol())->toBe(0);
    $this->contact->forceFill(['lifecycle' => Lifecycle::Lead])->save();
    $c = app(Inbox::class)->conversationFor($this->contact);
    Message::query()->create(['conversation_id' => $c->id, 'contact_id' => $this->contact->id, 'channel' => 'email', 'direction' => 'in', 'body' => 'Hello', 'status' => 'received']);
    expect(app(LongNurture::class)->enrol())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-06-20 15:00', 'UTC'));
    DB::connection(Connections::cell())->table('crm_ai_responder_settings')->update(['nurture_enabled' => false]);
    expect(app(LongNurture::class)->enrol())->toBe(0)->and(Nurture::query()->count())->toBe(0);
});

it('slows down while they do not answer, and speeds up when they come back to the website', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 15:00', 'UTC'));
    app(LongNurture::class)->enrol();
    Nurture::query()->update(['touches' => 5, 'unanswered' => 5, 'last_touch_at' => now()->subDays(30)]);
    nurtureSays($this, 'Hi Jane, Ally here, Ann\'s AI assistant. Any questions about homes in 78704?');
    app(LongNurture::class)->runDue();
    expect(Nurture::query()->sole()->next_at->toDateString())->toBe('2026-08-27'); // 45 days, stretched by three quarters after six unanswered: 79

    Nurture::query()->update(['next_at' => now()]);
    $site = app(SiteSetup::class)->create('aurum', [], $this->owner);
    DB::connection(Connections::cell())->table('site_visitors')->insert(['tenant_id' => $this->tenant->id, 'site_id' => $site->id, 'ulid' => (string) Str::ulid(), 'vid' => 'vid_abcdefghijklmnop',
        'contact_id' => $this->contact->id, 'last_seen_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    nurtureSays($this, 'Hi Jane, Ally here, Ann\'s AI assistant. Want the newest homes in 78704?');
    app(LongNurture::class)->runDue();
    expect(Nurture::query()->sole()->next_at->toDateString())->toBe('2026-06-11');
});

it('never sends wording that fails the Fair Housing check, and finishes after 12 months', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-09 15:00', 'UTC'));
    app(LongNurture::class)->enrol();
    nurtureSays($this, 'Great family-friendly area with safe streets!');
    expect(app(LongNurture::class)->runDue())->toBe(0);
    Mail::assertNotSent(MessageMail::class);
    expect(Nurture::query()->sole()->next_at->toDateString())->toBe('2026-06-10');

    $this->travelTo(CarbonImmutable::parse('2027-06-03 15:00', 'UTC'));
    expect(app(LongNurture::class)->runDue())->toBe(0)->and(Nurture::query()->sole()->status)->toBe('done');
});
