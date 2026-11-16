<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\Replies\ReplyWaiting;
use Modules\Ads\Studio\Replies\StudioReplies;
use Modules\Ads\Studio\Replies\StudioReply;
use Modules\Ads\Studio\StudioPlans;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Connect\FacebookLeads;
use Modules\Crm\Models\Lead;
use Tests\Fixtures\FakeAiProvider;

/* Sprint SA5c: replies to comments and messages on the agent's Facebook Page and Instagram — drafted by AI, Fair
   Housing checked, approved first by default, sensitive ones left to the agent, leads from messages. */

const REPLY_HOOK = 'https://auth.rightally.app/connect/apps/facebook_leads/webhook';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Agent']);
    $hq = User::factory()->make(['id' => 999999]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->jane->id, 10_000, 'adjust', 'Test credits', $this->owner);
    $app = app(AppCatalog::class)->find('studio_essentials');
    app(AppRegistry::class)->save($app, true, [], null, $hq);
    app(AppRegistry::class)->savePrice($app, 3900, [], $hq);
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30, 'features' => ['replies']], $hq);
    $this->actingAs($this->jane)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_essentials/addon')->assertSessionHasNoErrors();
    $this->flushSession();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true, ['client_id' => '123', 'client_secret' => 'app-secret', 'verify_token' => 'v'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_pages'), true, [], null, $hq);
    $this->conn = Connection::query()->create(['app_key' => 'facebook_pages', 'owner_user_id' => $this->jane->id, 'label' => 'Facebook Pages', 'status' => 'connected',
        'credentials' => ['access_token' => 'u', 'long_lived' => '1', 'expires_at' => (string) (time() + 86400 * 50), 'pages' => (string) json_encode(['111' => 'page-111'])],
        'settings' => ['pages' => [['id' => '111', 'name' => 'Jane Sells Austin']], 'instagram' => [['id' => '17841', 'name' => '@janesells', 'page' => '111']]],
        'connected_by' => $this->jane->id]);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => StudioReplies::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.studio_caption']);
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai = new FakeAiProvider);
    $this->post = SocialPost::query()->create(['user_id' => $this->jane->id, 'listing_ulid' => null, 'text' => "Just listed: 201 Pine Rd\nThree bedrooms, big yard.", 'status' => 'posted', 'post_at' => now()]);
    SocialTarget::query()->create(['post_id' => $this->post->id, 'connection_id' => $this->conn->id, 'channel' => 'facebook', 'target_id' => '111', 'name' => 'Jane Sells Austin', 'status' => 'posted'])
        ->forceFill(['external_id' => '111_900'])->save();
});

/** @param  array<string, mixed>  $body */
function metaHook(array $body): TestResponse
{
    $raw = (string) json_encode($body);

    return test()->call('POST', REPLY_HOOK, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret')], $raw);
}

function fbComment(string $id, string $text, string $from = '555'): TestResponse
{
    return metaHook(['object' => 'page', 'entry' => [['id' => '111', 'time' => 1, 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add',
        'comment_id' => $id, 'post_id' => '111_900', 'from' => ['id' => $from, 'name' => 'Rosa Diaz'], 'message' => $text]]]]]]);
}

function igMessage(string $mid, string $text): TestResponse
{
    return metaHook(['object' => 'instagram', 'entry' => [['id' => '17841', 'time' => 1, 'messaging' => [['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => '17841'],
        'message' => ['mid' => $mid, 'text' => $text]]]]]]);
}

function draftReply(object $t, string $intent, string $reply, bool $sensitive = false): void
{
    $t->ai->respond((string) json_encode(['intent' => $intent, 'sensitive' => $sensitive, 'reply' => $reply]));
}

function repliesOn(object $t, string $mode = 'approve'): void
{
    Http::fake(['graph.facebook.com/v21.0/111/subscribed_apps*' => fn (HttpRequest $r) => $r->method() === 'GET'
        ? Http::response(['data' => [['id' => '123', 'subscribed_fields' => ['leadgen']]]]) : Http::response(['success' => true]),
        'graph.facebook.com/v21.0/igsid-9?*' => Http::response(['name' => 'Rosa Diaz', 'username' => 'rosa.d']),
        'graph.facebook.com/v21.0/*' => Http::response(['id' => 'reply-1'])]);
    $t->actingAs($t->jane)->post('/studio/replies', ['on' => true, 'mode' => $mode])->assertSessionHasNoErrors();
}

it('turns replies on without dropping the Page\'s lead forms, and only with a package that includes them', function (): void {
    $this->actingAs($this->owner)->post('/studio/replies', ['on' => true])->assertSessionHasErrors('replies');
    repliesOn($this);
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '111/subscribed_apps') && $r['subscribed_fields'] === 'feed,leadgen,messages');
    expect(DB::connection(Connections::control())->table('connect_routes')->where('app_key', 'facebook_pages')->orderBy('external_key')->pluck('external_key')->all())->toBe(['111', '17841'])
        ->and(app(StudioReplies::class)->settings($this->jane))->toBe(['on' => true, 'mode' => 'approve']);
    $this->actingAs($this->jane)->get('/studio/replies')->assertInertia(fn (Assert $p) => $p->component('studio/replies')->where('ready', true)->where('settings.on', true));
});

it('drafts a reply to a Facebook comment and waits for the agent, once, then sends it as a reply to that comment', function (): void {
    repliesOn($this);
    draftReply($this, 'question', 'Thanks Rosa! It has three bedrooms. Send me a message for a private tour.');
    fbComment('900_1', 'How many bedrooms?')->assertOk();
    fbComment('900_1', 'How many bedrooms?')->assertOk(); // Meta retries
    fbComment('900_2', 'Thanks everyone!', '111')->assertOk(); // the Page itself

    $r = StudioReply::query()->sole();
    expect($r->only(['channel', 'kind', 'status', 'intent', 'from_name', 'social_post_id']))->toBe(['channel' => 'facebook', 'kind' => 'comment', 'status' => 'awaiting',
        'intent' => 'question', 'from_name' => 'Rosa Diaz', 'social_post_id' => $this->post->id])
        ->and($r->draft)->toStartWith('Thanks Rosa!');
    Notification::assertSentTo($this->jane, ReplyWaiting::class);
    Http::assertNotSent(fn (HttpRequest $req): bool => str_ends_with($req->url(), '900_1/comments')); // nothing goes out without her

    $this->actingAs($this->jane)->post('/studio/replies/'.$r->ulid.'/send', ['text' => 'Perfect for young couples with no kids.'])->assertSessionHasErrors('reply');
    $this->actingAs($this->jane)->post('/studio/replies/'.$r->ulid.'/send', ['text' => 'Three bedrooms! Message me for a tour.'])->assertSessionHasNoErrors();
    expect($r->refresh()->only(['status', 'draft']))->toBe(['status' => 'sent', 'draft' => 'Three bedrooms! Message me for a tour.']);
    Http::assertSent(fn (HttpRequest $req): bool => str_ends_with($req->url(), '900_1/comments') && $req['message'] === 'Three bedrooms! Message me for a tour.'
        && $req['access_token'] === 'page-111');
    $this->actingAs($this->owner)->post('/studio/replies/'.$r->ulid.'/send', ['text' => 'Hi'])->assertNotFound(); // only hers
});

it('on Auto, answers thanks and simple questions itself, and leaves interest, sensitive ones and spam alone', function (): void {
    repliesOn($this, 'auto');
    draftReply($this, 'praise', 'Thank you so much!');
    fbComment('c1', 'Congrats!! 🎉')->assertOk();
    draftReply($this, 'interest', 'Thanks! Send me a message and I\'ll set up a showing.');
    fbComment('c2', 'I want to see it this weekend')->assertOk();
    draftReply($this, 'question', 'Happy to talk numbers, message me!');
    fbComment('c3', 'Would they take an offer of 450k?')->assertOk(); // money: needs her, whatever the AI says
    draftReply($this, 'question', 'It is perfect for families with kids.');
    fbComment('c4', 'Is it a good area?')->assertOk(); // a draft that fails Fair Housing is never offered
    draftReply($this, 'spam', '');
    fbComment('c5', 'Earn $5000 a week from home, click here')->assertOk();

    $by = StudioReply::query()->get()->keyBy('external_id');
    expect($by['c1']->only(['status', 'reason']))->toBe(['status' => 'sent', 'reason' => 'Sent automatically'])
        ->and($by['c2']->status)->toBe('awaiting')
        ->and($by['c3']->status)->toBe('sensitive')->and($by['c3']->reason)->toContain('needs you')
        ->and($by['c4']->only(['status', 'draft']))->toBe(['status' => 'sensitive', 'draft' => null])
        ->and($by['c5']->status)->toBe('ignored');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'c1/comments'));
    Http::assertNotSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'c3/comments') || str_ends_with($r->url(), 'c2/comments'));
    Notification::assertSentToTimes($this->jane, ReplyWaiting::class, 1); // one email for the burst
    $this->actingAs($this->jane)->get('/studio/replies')->assertInertia(fn (Assert $p) => $p->has('items', 4)->where('items.0.status', 'sensitive'));
});

it('turns an Instagram message with contact details into a lead, replies in the thread, and only within 24 hours', function (): void {
    repliesOn($this);
    draftReply($this, 'interest', 'Thanks! I\'ll email you the details today.');
    igMessage('mid.1', 'Hi! I love 201 Pine Rd, can you send details? rosa@example.test')->assertOk();
    $r = StudioReply::query()->sole();
    $lead = Lead::query()->sole();
    expect($r->only(['channel', 'kind', 'status', 'lead_id']))->toBe(['channel' => 'instagram', 'kind' => 'message', 'status' => 'awaiting', 'lead_id' => $lead->id])
        ->and($lead->assigned_user_id)->toBe($this->jane->id)->and($lead->utm['utm_medium'] ?? null)->toBe('social_message')
        ->and($lead->message)->toStartWith('Messaged on Instagram:')->and($r->from_name)->toBe('Rosa Diaz');

    $this->actingAs($this->jane)->post('/studio/replies/'.$r->ulid.'/send')->assertSessionHasNoErrors();
    Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '111/messages') && $req['recipient'] === ['id' => 'igsid-9'] && $req['messaging_type'] === 'RESPONSE'
        && $req['message'] === ['text' => 'Thanks! I\'ll email you the details today.']);

    draftReply($this, 'question', 'Yes, it is!');
    igMessage('mid.2', 'Is it still available?')->assertOk();
    $late = StudioReply::query()->where('external_id', 'mid.2')->sole();
    $this->travel(25)->hours();
    $this->actingAs($this->jane)->post('/studio/replies/'.$late->ulid.'/send')->assertSessionHasErrors('reply');
    expect($late->refresh()->status)->toBe('expired')->and(Lead::query()->count())->toBe(1); // no contact details: no lead
});

it('keeps lead forms working on the shared address, and ignores comments when replies are off', function (): void {
    fbComment('c9', 'Nice!')->assertOk();
    expect(StudioReply::query()->count())->toBe(0); // no route, replies off
    $this->ai->respond('{}');
    expect(app(FacebookLeads::class)->route(['object' => 'page', 'entry' => [['id' => '111', 'changes' => [
        ['field' => 'leadgen', 'value' => ['page_id' => '111', 'leadgen_id' => '77', 'form_id' => '9']],
        ['field' => 'feed', 'value' => ['item' => 'like', 'verb' => 'add']]]]]]))
        ->toBe([['key' => '111', 'payload' => ['event' => 'leadgen', 'page_id' => '111', 'leadgen_id' => '77', 'form_id' => '9']]]);
});
