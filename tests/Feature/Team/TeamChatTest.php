<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Team\Chat;
use Modules\Team\Help\HelpCentre;
use Modules\Team\Help\SupportReplyMail;
use Modules\Team\Jobs\SendMentionMail;
use Modules\Team\MentionMail;
use Modules\Team\Models\Channel;
use Modules\Team\Models\Message;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
});

it('gives every office Everyone and Announcements, where only the broker and staff post news', function (): void {
    $this->actingAs($this->ann)->get('/chat')->assertInertia(fn (Assert $p) => $p->component('team/chat')
        ->where('channels.0.kind', 'announcements')->where('channels.0.can_post', false)->where('channels.1.kind', 'everyone')->where('channels.1.can_post', true));
    $news = Channel::query()->where('kind', 'announcements')->sole();
    $this->actingAs($this->ann)->post("/chat/{$news->ulid}/messages", ['body' => 'Hello'])->assertSessionHasErrors('body');
    $this->actingAs($this->owner)->post("/chat/{$news->ulid}/messages", ['body' => 'Office closed Monday.'])->assertRedirect();

    expect(Message::query()->sole()->body)->toBe('Office closed Monday.')
        ->and(DB::connection(Connections::cell())->table('team_messages')->value('body'))->not->toContain('Office closed'); // encrypted at rest
    $this->actingAs($this->ann)->get('/chat')->assertInertia(fn (Assert $p) => $p->where('channels.0.unread', 1));
    $this->actingAs($this->ann)->getJson("/chat/{$news->ulid}/messages")->assertJsonPath('messages.0.body', 'Office closed Monday.')->assertJsonPath('unread', 0);
});

it('keeps direct messages between the two people, and emails a mention only if it stays unread', function (): void {
    Queue::fake();
    $this->actingAs($this->ann)->post('/chat/direct', ['user' => $this->bob->id]);
    $dm = Channel::query()->where('kind', 'direct')->sole();
    $this->actingAs($this->ann)->post("/chat/{$dm->ulid}/messages", ['body' => '@Bob can you cover my open house?', 'mentions' => [$this->bob->id, $this->owner->id]]);

    $this->actingAs($this->owner)->getJson("/chat/{$dm->ulid}/messages")->assertForbidden(); // even the owner cannot read direct messages
    $this->actingAs($this->bob)->get('/chat?c='.$dm->ulid)->assertInertia(fn (Assert $p) => $p->where('current.name', 'Ann Agent')->where('messages.0.mine', false));
    expect(Message::query()->sole()->mentions)->toBe([$this->bob->id]); // the owner is not in this chat, so cannot be mentioned
    Queue::assertPushed(SendMentionMail::class, 1);

    Notification::fake();
    (new SendMentionMail(Message::query()->sole()->id, $this->bob->id))->handle(app(Chat::class));
    Notification::assertNothingSent(); // Bob has read it

    $this->actingAs($this->ann)->post("/chat/{$dm->ulid}/messages", ['body' => 'Thanks @Bob', 'mentions' => [$this->bob->id]]);
    (new SendMentionMail(Message::query()->latest('id')->first()->id, $this->bob->id))->handle(app(Chat::class));
    Notification::assertSentTo($this->bob, MentionMail::class, fn (MentionMail $m): bool => $m->from === 'Ann Agent' && $m->where === 'a direct message');
});

it('lets authors change or take back a message for 15 minutes, and the broker remove one from a shared channel', function (): void {
    $everyone = app(Chat::class)->post(Channel::query()->firstOrCreate(['kind' => 'everyone'], ['name' => 'Everyone']), 'Frist post', $this->ann);
    $this->actingAs($this->ann)->patch("/chat/messages/{$everyone->id}", ['body' => 'First post'])->assertSessionHasNoErrors();
    expect($everyone->fresh()->body)->toBe('First post')->and($everyone->fresh()->edited_at)->not->toBeNull();

    $this->travel(16)->minutes();
    $this->actingAs($this->ann)->patch("/chat/messages/{$everyone->id}", ['body' => 'Too late'])->assertSessionHasErrors('body');
    $this->actingAs($this->bob)->post("/chat/messages/{$everyone->id}/hide")->assertForbidden();
    $this->actingAs($this->owner)->post("/chat/messages/{$everyone->id}/hide")->assertRedirect();
    expect($everyone->fresh()->hidden_at)->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'team.chat.message_hidden')->exists())->toBeTrue();
    $channel = Channel::query()->where('kind', 'everyone')->sole();
    $this->actingAs($this->bob)->getJson("/chat/{$channel->ulid}/messages")->assertJsonPath('messages.0.body', null)->assertJsonPath('messages.0.hidden', true);
});

it('lets the broker make groups that only members see', function (): void {
    $this->actingAs($this->ann)->post('/chat/groups', ['name' => 'Downtown', 'members' => [$this->bob->id]])->assertForbidden();
    $this->actingAs($this->owner)->post('/chat/groups', ['name' => 'Downtown', 'members' => [$this->ann->id]]);
    $group = Channel::query()->where('kind', 'group')->sole();

    $this->actingAs($this->ann)->post("/chat/{$group->ulid}/messages", ['body' => 'Hi team'])->assertRedirect();
    $this->actingAs($this->bob)->getJson("/chat/{$group->ulid}/messages")->assertForbidden();
    $this->actingAs($this->bob)->get('/chat')->assertInertia(fn (Assert $p) => $p->where('channels', fn ($c): bool => ! collect($c)->contains('kind', 'group')));
});

it('finds help articles by plain words and shows broker-only ones to brokers', function (): void {
    $help = app(HelpCentre::class);
    expect(array_column($help->search('how do I upload documents', false), 'slug'))->toContain('deals-and-documents')
        ->and(array_column($help->search('spanish', false), 'slug'))->toBe(['explain-and-compare'])
        ->and(array_keys($help->visible(false)))->not->toContain('smart-forms')
        ->and(array_keys($help->visible(true)))->toContain('smart-forms')
        ->and(count($help->all()))->toBeGreaterThanOrEqual(12);
    expect(HelpCentre::html('Hi <script>alert(1)</script> **bold**'))->not->toContain('<script>')->toContain('<strong>bold</strong>');

    $this->actingAs($this->ann)->get('/help?q=wire+fraud')->assertInertia(fn (Assert $p) => $p->component('team/help')->where('results.0.slug', 'security'));
    $this->actingAs($this->ann)->get('/help/smart-forms')->assertNotFound();
    $this->actingAs($this->ann)->get('/help/team-chat')->assertInertia(fn (Assert $p) => $p->where('article.title', 'Team chat')->where('voted', null));
    $this->actingAs($this->ann)->post('/help/team-chat/feedback', ['helpful' => false]);
    $this->actingAs($this->ann)->get('/help/team-chat')->assertInertia(fn (Assert $p) => $p->where('voted', false));
});

it('sends support requests to HQ, and HQ answers by email and on the Help page', function (): void {
    Notification::fake();
    $this->actingAs($this->ann)->post('/help/ask', ['topic' => 'how_to', 'subject' => 'Import', 'body' => 'short'])->assertSessionHasErrors('body');
    $this->actingAs($this->ann)->post('/help/ask', ['topic' => 'how_to', 'subject' => 'Importing my list', 'body' => 'How do I bring in my past clients from Outlook?'])
        ->assertSessionHasNoErrors();

    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
    $this->actingAs($staff)->get('http://app.rightally.io/hq/support')->assertInertia(fn (Assert $p) => $p->component('hq/support')->where('tickets.0.subject', 'Importing my list')
        ->where('tickets.0.brokerage', $this->tenant->name)->where('tickets.0.body', 'How do I bring in my past clients from Outlook?'));
    $ulid = DB::connection(Connections::control())->table('support_tickets')->value('ulid');
    $this->actingAs($staff)->post("http://app.rightally.io/hq/support/{$ulid}", ['reply' => 'Contacts › Import, then upload a CSV.'])->assertRedirect();
    Notification::assertSentOnDemand(SupportReplyMail::class);

    $this->actingAs($this->ann)->get('/help')->assertInertia(fn (Assert $p) => $p->where('requests.0.status', 'answered')->where('requests.0.reply', 'Contacts › Import, then upload a CSV.'));
    $this->actingAs($this->bob)->get('/help')->assertInertia(fn (Assert $p) => $p->where('requests', []));
    $this->actingAs($this->ann)->get('/hq/support')->assertNotFound();
});
