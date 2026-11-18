<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Transactions\DealIq\UploadReceivedMail;
use Modules\Transactions\DealIq\UploadRequests;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Portal\Concierge;
use Modules\Transactions\Portal\Copy;
use Modules\Transactions\Portal\PortalCodeMail;
use Modules\Transactions\Portal\PortalFeedbackMail;
use Modules\Transactions\Portal\PortalMail;
use Modules\Transactions\Portal\PortalQuestionMail;
use Modules\Transactions\Portal\PortalSecurityMail;
use Modules\Transactions\Portal\PortalView;
use Modules\Transactions\Portal\SignIn;
use Modules\Transactions\Portal\Updates;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    // Monday 10:00 in Austin (America/Chicago, the brokerage's time zone).
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+15125550199']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Concierge::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.deal_concierge']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '412 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '450000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->deal->update(['status' => 'under_contract', 'contract_date' => '2026-06-01', 'closing_date' => '2026-07-10', 'financing' => 'fha']);
    foreach ([['Option period ends', '2026-06-08'], ['Financing deadline', '2026-06-25'], ['Earnest money due', '2026-06-04'], ['Check the closing date', '2026-07-10']] as [$label, $due]) {
        DB::table('tx_dates')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'label' => $label, 'due_on' => $due, 'source' => 'timeline', 'created_at' => now(), 'updated_at' => now()]);
    }
});

/** Invites someone as the agent and returns the token from their email. */
function portalInvite(object $test, string $role, string $name, string $email, string $locale = 'en'): string
{
    $test->actingAs($test->agent)->post("/deals/{$test->deal->ulid}/portal", ['role' => $role, 'name' => $name, 'email' => $email, 'locale' => $locale])->assertRedirect();
    $token = null;
    Notification::assertSentOnDemand(PortalMail::class, function (PortalMail $mail, array $channels, AnonymousNotifiable $to) use ($email, &$token): bool {
        if ($to->routes['mail'] !== $email) {
            return false;
        }
        $token = substr($mail->url, strrpos($mail->url, '/') + 1);

        return true;
    });
    auth()->logout();

    return (string) $token;
}

/** Signs in with the link, the invited address and the emailed code; returns the remembered-device cookie, if any. */
function portalEnter(object $test, string $token, bool $remember = false): ?string
{
    $access = DB::table('tx_portal_access')->where('login_hash', hash('sha256', $token))->first();
    $test->get("/my-deal/in/{$token}")->assertOk();
    $test->post("/my-deal/in/{$token}/code", ['email' => $access->email])->assertRedirect("/my-deal/in/{$token}");
    $code = null;
    Notification::assertSentOnDemand(PortalCodeMail::class, function (PortalCodeMail $mail, array $c, AnonymousNotifiable $to) use ($access, &$code): bool {
        $code = $mail->code;

        return $to->routes['mail'] === $access->email;
    });
    $response = $test->post("/my-deal/in/{$token}/verify", ['code' => $code, 'remember' => $remember])->assertRedirect("/my-deal/{$access->ulid}");

    return $remember ? $response->getCookie(SignIn::COOKIE)?->getValue() : null;
}

function portalAccessUlid(string $email): string
{
    return (string) DB::table('tx_portal_access')->where('email', $email)->value('ulid');
}

function portalSms(int $tenantId): void
{
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['sms_from' => '+15125550100', 'voice_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $tenantId, 'sms' => true, 'voice' => false, 'created_at' => now(), 'updated_at' => now()]);
}

it('has every word in English and Spanish', function (): void {
    expect(Copy::missing())->toBe([])
        ->and(Copy::date('es', '2026-07-24'))->toBe('viernes, 24 de julio')
        ->and(Copy::date('en', '2026-07-24'))->toBe('Friday, July 24')
        ->and(Copy::label('es', 'Option period ends'))->toBe('Termina el periodo de opción')
        ->and(Copy::label('es', 'Pick up the keys'))->toBe('Pick up the keys')
        ->and(Copy::t('es', 'mail.invite_subject', ['street' => '412 Elm St']))->toBe('Siga su casa en 412 Elm St');
});

it('signs a client in with a single-use link and shows their deal, never to another browser', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test', 'es');
    $ulid = portalAccessUlid('ana@example.test');

    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal'); // not signed in here
    portalEnter($this, $token);
    $this->get("/my-deal/{$ulid}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('public/my-deal-show')
            ->where('access.locale', 'es')->where('access.client', true)->where('deal.street', '412 Elm St')
            ->where('t.progress', 'En qué va todo')
            ->where('stages.0.key', 'contract')->where('stages.0.state', 'done')->where('stages.1.key', 'inspection')->where('stages.1.state', 'current')
            ->where('stages.2.label', 'Préstamo y avalúo')
            ->where('dates', fn ($dates): bool => collect($dates)->pluck('label')->all() === ['Entrega del depósito de garantía', 'Termina el periodo de opción', 'Fecha límite del financiamiento', 'Cierre'])
            ->where('next.0', 'Entrega del depósito de garantía: jueves, 4 de junio')
            ->where('agent.name', 'Pat Lee'));

    // The link worked once.
    $this->post('/my-deal/sign-out');
    $this->get("/my-deal/in/{$token}")->assertRedirect('/my-deal?expired=1');
    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');
});

it('expires invitations, and the agent can remove someone at once', function (): void {
    $token = portalInvite($this, 'seller', 'Sam Seller', 'sam@example.test');
    $this->travel(8)->days();
    $this->get("/my-deal/in/{$token}")->assertRedirect('/my-deal?expired=1');

    $this->travelBack();
    $token = portalInvite($this, 'seller', 'Sam Seller', 'sam@example.test');
    $ulid = portalAccessUlid('sam@example.test');
    portalEnter($this, $token);
    $this->get("/my-deal/{$ulid}")->assertOk();
    $this->actingAs($this->agent)->delete("/deals/{$this->deal->ulid}/portal/{$ulid}")->assertRedirect();
    auth()->logout();
    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');
    expect(DB::table('tx_portal_access')->where('ulid', $ulid)->value('revoked_at'))->not->toBeNull();
});

it('emails a new sign-in link without saying whether the address is known', function (): void {
    portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    Notification::fake();
    $this->post('/my-deal/link', ['email' => 'nobody@example.test'])->assertRedirect('/my-deal?lang=en')->assertSessionHas('portal_sent', true);
    Notification::assertNothingSent();
    $this->post('/my-deal/link', ['email' => 'ANA@example.test'])->assertRedirect('/my-deal?lang=en')->assertSessionHas('portal_sent', true);
    Notification::assertSentOnDemandTimes(PortalMail::class, 1);
});

it('shows the other side only their dates and requests: no progress, documents or concierge', function (): void {
    $token = portalInvite($this, 'lender', 'Big Bank', 'loans@bank.test');
    app(UploadRequests::class)->send($this->deal, null, 'Loan approval letter', 'lender', 'Big Bank', 'loans@bank.test', null, $this->agent);
    $doc = Document::query()->create(['deal_id' => $this->deal->id, 'filename' => 'contract.pdf', 'path' => 'x/contract.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => 'x', 'version' => 1, 'via' => 'upload', 'uploaded_by' => $this->agent->id]);
    $doc->forceFill(['shared_at' => now()])->save();
    $ulid = portalAccessUlid('loans@bank.test');
    portalEnter($this, $token);
    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('access.client', false)->where('stages', [])->where('documents', [])
        ->where('dates', fn ($d): bool => collect($d)->pluck('label')->all() === ['Financing deadline', 'Closing'])
        ->where('requests.0.what', 'Loan approval letter'));
    $this->get("/my-deal/{$ulid}/documents/{$doc->ulid}")->assertNotFound();
    $this->postJson("/my-deal/{$ulid}/ask", ['question' => 'When is closing?'])->assertUnprocessable();
    $this->post("/my-deal/{$ulid}/upload", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertForbidden();

    // They answer the request from the portal.
    $request = (string) DB::table('tx_upload_requests')->value('ulid');
    $this->post("/my-deal/{$ulid}/upload", ['request' => $request, 'file' => UploadedFile::fake()->create('approval.pdf', 10, 'application/pdf')])->assertRedirect("/my-deal/{$ulid}");
    expect(DB::table('tx_upload_requests')->value('used_at'))->not->toBeNull();
    Notification::assertSentTo($this->agent, UploadReceivedMail::class);
});

it('lets clients open only the documents the agent shared, and send the agent a document', function (): void {
    Storage::disk('local')->put('x/disclosure.pdf', '%PDF-1.4 test');
    $doc = Document::query()->create(['deal_id' => $this->deal->id, 'filename' => 'disclosure.pdf', 'path' => 'x/disclosure.pdf', 'mime' => 'application/pdf', 'size' => 13, 'sha256' => 'x', 'version' => 1, 'via' => 'upload', 'uploaded_by' => $this->agent->id]);
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $ulid = portalAccessUlid('ana@example.test');
    portalEnter($this, $token);
    $this->get("/my-deal/{$ulid}/documents/{$doc->ulid}")->assertNotFound();

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal/documents/{$doc->ulid}")->assertRedirect();
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('unsorted.0.shared', true)
        ->where('portal.people.0.email', 'ana@example.test'));
    auth()->logout();
    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('documents.0.name', 'disclosure.pdf'));
    $this->get("/my-deal/{$ulid}/documents/{$doc->ulid}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex');

    $this->post("/my-deal/{$ulid}/upload", ['file' => UploadedFile::fake()->create('pre-approval.pdf', 10, 'application/pdf')])->assertRedirect("/my-deal/{$ulid}");
    expect(Document::query()->where('deal_id', $this->deal->id)->where('via', 'portal')->count())->toBe(1);
    Notification::assertSentTo($this->agent, UploadReceivedMail::class);
});

it('sends money and wiring questions straight to the agent, never to the AI', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test', 'es');
    $ulid = portalAccessUlid('ana@example.test');
    portalEnter($this, $token);
    $r = $this->postJson("/my-deal/{$ulid}/ask", ['question' => '¿A qué cuenta del banco envío la transferencia?'])->assertOk()->json();
    expect($r['status'])->toBe('to_agent')->and($r['answer'])->toContain('Nunca envíe dinero')
        ->and($this->ai->requests)->toBe([]);
    Notification::assertSentTo($this->agent, PortalQuestionMail::class);

    // The agent replies; wiring details are refused; the client gets the reply in an email.
    $q = (string) DB::table('tx_portal_questions')->value('ulid');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal/questions/{$q}", ['reply' => 'Wire to routing number 021000021 account 12345678'])->assertSessionHasErrors('reply');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal/questions/{$q}", ['reply' => 'Call me and I will walk you through it with the title company.'])->assertRedirect();
    expect(DB::table('tx_portal_questions')->value('status'))->toBe('replied');
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'ana@example.test'
        && $m->subject === 'Su agente respondió sobre 412 Elm St' && $m->replyTo === $this->agent->email);
});

it('answers from the deal in the client\'s language and shows where the answer came from', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test', 'es');
    $ulid = portalAccessUlid('ana@example.test');
    portalEnter($this, $token);
    $this->ai->respond(json_encode(['answer' => 'Su periodo de opción termina el lunes 8 de junio.', 'to_agent' => false, 'sources' => ['dates', 'made_up']]));
    $r = $this->postJson("/my-deal/{$ulid}/ask", ['question' => '¿Cuándo termina mi periodo de opción?'])->assertOk()->json();
    expect($r['status'])->toBe('answered')->and($r['answer'])->toBe('Su periodo de opción termina el lunes 8 de junio.')->and($r['sources'])->toBe(['fechas clave']);
    Notification::assertNotSentTo($this->agent, PortalQuestionMail::class);

    // They would rather the agent answered.
    $this->postJson("/my-deal/{$ulid}/ask/{$r['ulid']}/agent")->assertOk();
    Notification::assertSentTo($this->agent, PortalQuestionMail::class);

    // An answer with an amount in it is not shown.
    $this->ai->respond(json_encode(['answer' => 'You will need $13,500 at closing.', 'to_agent' => false, 'sources' => ['deal']]));
    $r = $this->postJson("/my-deal/{$ulid}/ask", ['question' => 'How much do I bring to closing?'])->json();
    expect($r['status'])->toBe('to_agent')->and($r['answer'])->not->toContain('13,500');

    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->has('questions', 2)->where('questions.1.sources.0', 'fechas clave'));
});

it('sends each milestone once, texts only clients who opted in during the day, and a Monday summary', function (): void {
    portalSms($this->tenant->id);
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    portalInvite($this, 'lender', 'Big Bank', 'loans@bank.test');
    $ulid = portalAccessUlid('ana@example.test');
    portalEnter($this, $token);
    $this->post("/my-deal/{$ulid}/preferences", ['locale' => 'en', 'phone' => '', 'texts' => true])->assertSessionHasErrors('phone');
    $this->post("/my-deal/{$ulid}/preferences", ['locale' => 'en', 'phone' => '(512) 555-0111', 'texts' => true])->assertRedirect();
    expect(DB::table('tx_portal_access')->where('ulid', $ulid)->value('phone'))->toBe('+15125550111');
    Notification::fake();

    $counts = app(Updates::class)->run();
    expect($counts)->toMatchArray(['milestones' => 1, 'weekly' => 1, 'texts' => 1])
        ->and($this->sms->sent[0]['to'])->toBe('+15125550111')->and($this->sms->sent[0]['message'])->toContain('Inspections');
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'ana@example.test' && $m->subject === '412 Elm St: Inspections');
    Notification::assertSentOnDemand(PortalMail::class, fn (PortalMail $m, $c, AnonymousNotifiable $to): bool => $m->subject === 'This week on 412 Elm St'
        && in_array('• Earnest money due: Thursday, June 4', $m->lines, true));

    // Nothing new: nothing sent.
    expect(app(Updates::class)->run())->toMatchArray(['milestones' => 0, 'weekly' => 0, 'texts' => 0]);

    // The option period is done: the next milestone. At night, no text (the email still goes).
    DB::table('tx_dates')->where('label', 'Option period ends')->update(['done_at' => now()]);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 04:00', 'UTC')); // 11pm in Austin
    expect(app(Updates::class)->run())->toMatchArray(['milestones' => 1, 'texts' => 0]);
    expect(DB::table('tx_deals')->where('id', $this->deal->id)->value('stage_shown'))->toBe('financing');
    expect(DB::table('tx_portal_updates')->join('tx_portal_access', 'tx_portal_access.id', '=', 'tx_portal_updates.access_id')->where('tx_portal_access.role', 'lender')->count())->toBe(0);
});

it('asks clients how it went a week after closing and tells the agent', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $ulid = portalAccessUlid('ana@example.test');
    $this->deal->update(['status' => 'closed', 'closed_on' => '2026-06-01', 'stage_shown' => 'closed']);
    expect(PortalView::stage($this->deal->refresh()))->toBe('closed');
    Notification::fake();
    expect(app(Updates::class)->run()['checkins'])->toBe(0);
    $this->travel(7)->days();
    expect(app(Updates::class)->run()['checkins'])->toBe(1)->and(app(Updates::class)->run()['checkins'])->toBe(0);

    portalEnter($this, $token);
    $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('checkin', true)->where('stages.4.state', 'current'));
    $this->post("/my-deal/{$ulid}/feedback", ['rating' => 5, 'comment' => 'Pat made it easy.', 'may_quote' => true])->assertRedirect();
    $this->post("/my-deal/{$ulid}/feedback", ['rating' => 1]); // once only
    expect(DB::table('tx_portal_feedback')->count())->toBe(1)->and((int) DB::table('tx_portal_feedback')->value('rating'))->toBe(5);
    Notification::assertSentToTimes($this->agent, PortalFeedbackMail::class, 1);
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('portal.feedback.0.rating', 5)->where('portal.feedback.0.may_quote', true));
});

it('keeps the portal to the deal\'s agent and the brokerage', function (): void {
    $other = User::factory()->create();
    $this->actingAs($other)->post("/deals/{$this->deal->ulid}/portal", ['role' => 'buyer', 'name' => 'X', 'email' => 'x@example.test', 'locale' => 'en'])->assertNotFound();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal", ['role' => 'buyer', 'name' => 'X', 'email' => 'not-an-email', 'locale' => 'en'])->assertSessionHasErrors('email');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal", ['role' => 'buyer', 'name' => 'X', 'email' => 'x@example.test', 'locale' => 'fr'])->assertSessionHasErrors('locale');
    auth()->logout();
    $this->get('/my-deal')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page->component('public/my-deal')->where('t.signin_title', 'See your deal'));
    $this->get('/my-deal?lang=es')->assertInertia(fn (Assert $page) => $page->where('t.signin_title', 'Vea su transacción'));
});

it('never signs anyone in with the link alone: opening it (as an email scanner does) changes nothing', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test', 'es');
    $ulid = portalAccessUlid('ana@example.test');
    $this->get("/my-deal/in/{$token}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->component('public/my-deal-verify')->where('t.verify_title', 'Confirme que es usted')->where('sentTo', null)->where('sms', null));
    $this->get("/my-deal/in/{$token}")->assertOk(); // a scanner opening it twice does not use it up
    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');

    // Someone with a forwarded link but not the inbox: a wrong address gets no code.
    $this->post("/my-deal/in/{$token}/code", ['email' => 'forwarded@else.test'])->assertRedirect("/my-deal/in/{$token}")->assertSessionHas('portal_error', 'wrong_email');
    Notification::assertSentOnDemandTimes(PortalCodeMail::class, 0);
    expect(DB::table('tx_portal_access')->where('ulid', $ulid)->value('code_hash'))->toBeNull();
});

it('locks the link after five wrong tries, and codes expire after ten minutes', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $this->post("/my-deal/in/{$token}/code", ['email' => 'Ana@Example.test']);
    Notification::assertSentOnDemandTimes(PortalCodeMail::class, 1);
    $this->post("/my-deal/in/{$token}/verify", ['code' => '000000x'])->assertSessionHas('portal_error', 'code_wrong:4');
    $this->get("/my-deal/in/{$token}")->assertInertia(fn (Assert $page) => $page->where('sentTo', 'a•••@example.test')->where('error', 'code_wrong:4'));
    foreach ([3, 2, 1] as $left) {
        $this->post("/my-deal/in/{$token}/verify", ['code' => '999999'])->assertSessionHas('portal_error', "code_wrong:{$left}");
    }
    $this->post("/my-deal/in/{$token}/verify", ['code' => '999999'])->assertRedirect('/my-deal?expired=1');
    $this->get("/my-deal/in/{$token}")->assertRedirect('/my-deal?expired=1'); // the link is dead
    expect(DB::table('audit_events')->where('action', 'transactions.portal.locked')->exists())->toBeTrue();

    // Codes expire.
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    Notification::fake();
    $this->post("/my-deal/in/{$token}/code", ['email' => 'ana@example.test']);
    $code = null;
    Notification::assertSentOnDemand(PortalCodeMail::class, function (PortalCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });
    $this->travel(11)->minutes();
    $this->post("/my-deal/in/{$token}/verify", ['code' => $code])->assertSessionHas('portal_error', 'code_expired');
});

it('emails every new sign-in with a "this was not me" link that signs out everywhere and warns the agent', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $ulid = portalAccessUlid('ana@example.test');
    portalEnter($this, $token);
    $report = null;
    Notification::assertSentOnDemand(PortalMail::class, function (PortalMail $m) use (&$report): bool {
        if ($m->subject !== 'New sign-in to your deal') {
            return false;
        }
        $report = substr($m->url, strrpos($m->url, '/') + 1);

        return $m->actionKey === 'mail.not_me' && str_contains($m->lines[0], 'on A browser on an unknown device');
    });
    $this->get("/my-deal/{$ulid}")->assertOk();

    // Opening the link does nothing by itself; confirming signs every device out and tells the agent.
    $this->get("/my-deal/not-me/{$report}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/my-deal-not-me')->where('valid', true)->where('done', false));
    $this->get("/my-deal/{$ulid}")->assertOk();
    $this->post("/my-deal/not-me/{$report}")->assertRedirect("/my-deal/not-me/{$report}");
    Notification::assertSentTo($this->agent, PortalSecurityMail::class);
    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');
    expect(DB::table('tx_portal_devices')->whereNull('revoked_at')->count())->toBe(0);
});

it('remembers a device for 30 days until the agent signs them out everywhere', function (): void {
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $ulid = portalAccessUlid('ana@example.test');
    $device = portalEnter($this, $token, remember: true);
    expect($device)->not->toBeNull();

    // A new browser session with the remembered-device cookie goes straight in.
    $this->flushSession();
    $this->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');
    $this->withCookie(SignIn::COOKIE, (string) $device)->get("/my-deal/{$ulid}")->assertOk();

    // Session-only sign-ins end after 12 hours; the remembered device does not.
    $this->travel(13)->hours();
    $this->flushSession();
    $this->withCookie(SignIn::COOKIE, (string) $device)->get("/my-deal/{$ulid}")->assertOk();

    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('portal.people.0.devices', 1));
    $this->actingAs($this->agent)->delete("/deals/{$this->deal->ulid}/portal/{$ulid}/devices")->assertRedirect();
    auth()->logout();
    $this->withCookie(SignIn::COOKIE, (string) $device)->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');
    $this->flushSession();
    $this->withCookie(SignIn::COOKIE, (string) $device)->get("/my-deal/{$ulid}")->assertRedirect('/my-deal');

    $this->travel(31)->days();
    expect(DB::table('tx_portal_devices')->where('expires_at', '>', now())->whereNull('revoked_at')->count())->toBe(0);
});

it('texts the code to clients who opted in, during the day only', function (): void {
    portalSms($this->tenant->id);
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $ulid = portalAccessUlid('ana@example.test');
    DB::table('tx_portal_access')->where('ulid', $ulid)->update(['phone' => '+15125550111', 'sms_consent_at' => now()]);
    $this->get("/my-deal/in/{$token}")->assertInertia(fn (Assert $page) => $page->where('sms', '•••0111'));
    $this->post("/my-deal/in/{$token}/code", ['email' => 'ana@example.test', 'via' => 'sms'])->assertSessionHas('portal_code_sent', '•••0111');
    expect($this->sms->sent)->toHaveCount(1)->and($this->sms->sent[0]['message'])->toContain('is your sign-in code for 412 Elm St');
    preg_match('/^(\d{6})/', $this->sms->sent[0]['message'], $m);
    $this->post("/my-deal/in/{$token}/verify", ['code' => $m[1]])->assertRedirect("/my-deal/{$ulid}");

    // At night the code goes by email instead.
    $token = portalInvite($this, 'buyer', 'Ana Garcia', 'ana@example.test');
    $this->travelTo(CarbonImmutable::parse('2026-06-02 04:00', 'UTC'));
    $this->post("/my-deal/in/{$token}/code", ['email' => 'ana@example.test', 'via' => 'sms'])->assertSessionHas('portal_code_sent', 'a•••@example.test');
    expect($this->sms->sent)->toHaveCount(1);
});
