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
use Modules\Core\Support\Connections;
use Modules\Transactions\DealIq\Adapt;
use Modules\Transactions\DealIq\DatesChangedMail;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\DealIq\FormDrafter;
use Modules\Transactions\DealIq\Intake;
use Modules\Transactions\DealIq\PartyMessageMail;
use Modules\Transactions\DealIq\UploadReceivedMail;
use Modules\Transactions\DealIq\UploadRequestMail;
use Modules\Transactions\DealIq\UploadRequests;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Timeline\StatePacks;
use Modules\Transactions\Timeline\Timeline;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([Intake::FEATURE => 'ai.deal_intake', DealAssistant::FEATURE => 'ai.deal_assistant'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
});

function addParty(Deal $deal, string $role, string $name, ?string $email): void
{
    DB::connection(Connections::cell())->table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => $role, 'name' => $name,
        'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, string> label => due_on */
function timelineDates(Deal $deal): array
{
    return DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $deal->id)->pluck('due_on', 'label')
        ->map(fn ($d): string => substr((string) $d, 0, 10))->all();
}

it('knows business days: weekends, federal holidays (observed) and state holidays', function (): void {
    expect(StatePacks::holidays(2026, null))->toContain('2026-01-19', '2026-07-03', '2026-11-26', '2026-12-25')
        ->and(StatePacks::holidays(2026, 'TX'))->toContain('2026-03-02', '2026-04-21')
        ->and(StatePacks::holidays(2026, 'CA'))->not->toContain('2026-03-02')
        // Saturday July 4 → the holiday is Friday July 3, so a deadline that day rolls to Monday.
        ->and(StatePacks::roll(CarbonImmutable::parse('2026-07-03'), 'CA')->toDateString())->toBe('2026-07-06')
        ->and(StatePacks::roll(CarbonImmutable::parse('2026-03-02'), 'TX')->toDateString())->toBe('2026-03-03')
        ->and(StatePacks::roll(CarbonImmutable::parse('2026-03-02'), 'CA')->toDateString())->toBe('2026-03-02')
        // NJ attorney review: 3 business days from Friday Nov 20 skips the weekend and Thanksgiving.
        ->and(StatePacks::addBusinessDays(CarbonImmutable::parse('2026-11-20'), 3, 'NJ')->toDateString())->toBe('2026-11-25')
        ->and(StatePacks::addBusinessDays(CarbonImmutable::parse('2026-11-24'), 3, 'NJ')->toDateString())->toBe('2026-11-30');
});

it('builds the timeline from the contract with the state pack, and rebuilds it when the closing date moves', function (): void {
    // Contract Thursday Jan 15 2026 in Texas: earnest money 3 days later is Sunday Jan 18 → Monday Jan 19 is MLK Day → Tuesday Jan 20.
    $this->deal->update(['contract_date' => '2026-01-15', 'closing_date' => '2026-02-20']);
    $changes = app(Timeline::class)->rebuild($this->deal->refresh());
    expect(timelineDates($this->deal))->toMatchArray(['Earnest money due' => '2026-01-20', 'Final walk-through' => '2026-02-19'])
        ->and(count($changes))->toBe(2);

    // Moving the closing to a Saturday moves the walk-through and warns about the closing day.
    $this->deal->update(['closing_date' => '2026-02-28']);
    app(Timeline::class)->refresh($this->deal->refresh());
    expect(timelineDates($this->deal))->toMatchArray(['Final walk-through' => '2026-02-27', 'Check the closing date' => '2026-02-28']);
    Notification::assertSentTo($this->agent, DatesChangedMail::class);

    // Nothing moved: no new notice, and no duplicates.
    Notification::fake();
    expect(app(Timeline::class)->refresh($this->deal->refresh()))->toBe([]);
    Notification::assertNothingSent();
    expect(DB::connection(Connections::cell())->table('tx_dates')->where('deal_id', $this->deal->id)->where('label', 'Final walk-through')->count())->toBe(1);
});

it('adapts the checklist to the home and the loan, once', function (): void {
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/property", ['property_type' => 'condo', 'financing' => 'fha'])->assertRedirect();
    $labels = Item::query()->where('deal_id', $this->deal->id)->pluck('label')->all();
    expect($labels)->toContain('Condominium association documents and resale certificate', 'FHA amendatory clause');

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/property", ['property_type' => 'condo', 'financing' => 'cash']);
    expect(Item::query()->where('deal_id', $this->deal->id)->where('label', 'FHA amendatory clause')->count())->toBe(1) // never removed
        ->and(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Condominium association documents and resale certificate')->count())->toBe(1)
        ->and(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Proof of funds')->exists())->toBeTrue();

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/property", ['property_type' => 'castle'])->assertSessionHasErrors('property_type');
    expect(array_keys(Adapt::PROPERTY_TYPES))->toContain('manufactured');
});

it('sends a single-use upload link the other side can use without an account', function (): void {
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/upload-requests", ['what' => 'Loan approval letter', 'role' => 'lender', 'name' => 'Sam Banks', 'email' => 'sam@lender.test'])
        ->assertRedirect();
    $url = null;
    Notification::assertSentOnDemand(UploadRequestMail::class, function (UploadRequestMail $mail, array $channels, AnonymousNotifiable $to) use (&$url): bool {
        $url = $mail->link;

        return $to->routes['mail'] === 'sam@lender.test';
    });
    $token = (string) substr((string) $url, strrpos((string) $url, '/') + 1);
    expect(DB::connection(Connections::cell())->table('tx_upload_requests')->value('token_hash'))->toBe(hash('sha256', $token));

    auth()->logout();
    $this->get('/deal-upload/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/deal-upload')
        ->where('valid', true)->where('what', 'Loan approval letter')->where('street', '12 Oak St')->where('agent', 'Pat Lee'));
    $this->post('/deal-upload/'.$token, ['file' => UploadedFile::fake()->create('approval.pdf', 40, 'application/pdf')])->assertRedirect('/deal-upload/'.$token.'?done=1');
    expect(Document::query()->where('deal_id', $this->deal->id)->where('via', 'link')->count())->toBe(1);
    Notification::assertSentTo($this->agent, UploadReceivedMail::class);

    // Used once: the link no longer works.
    $this->get('/deal-upload/'.$token)->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->post('/deal-upload/'.$token, ['file' => UploadedFile::fake()->create('again.pdf', 40, 'application/pdf')])->assertSessionHasErrors('file');
    $this->get('/deal-upload/'.str_repeat('a', 48))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    expect(app(UploadRequests::class)->forDeal($this->deal)[0]['status'])->toBe('received');
});

it('expires upload links after 14 days', function (): void {
    $token = app(UploadRequests::class)->send($this->deal, null, 'Survey', 'title', 'Lone Star Title', 'docs@title.test', null, $this->agent);
    $this->travel(15)->days();
    expect(app(UploadRequests::class)->find($token))->toBeNull()
        ->and(app(UploadRequests::class)->forDeal($this->deal)[0]['status'])->toBe('expired');
});

it('starts a deal from a description, then the agent confirms the setup', function (): void {
    $this->ai->respond(json_encode(['type' => 'buyer', 'street' => '412 Elm St', 'city' => 'Austin', 'state' => 'tx', 'postal_code' => '78701', 'price' => 450000,
        'contract_date' => '2026-06-01', 'closing_date' => '2026-07-03', 'financing' => 'va', 'property_type' => 'empty', 'buyers' => ['Ana Garcia', 'Luis Garcia'], 'sellers' => []]));
    $this->actingAs($this->agent)->post('/deals/intake/text', ['text' => 'Buyer deal for the Garcias, 412 Elm St Austin TX 78701, $450k VA loan, closing July 3'])
        ->assertRedirect();
    $deal = Deal::query()->latest('id')->firstOrFail();
    expect($deal->street)->toBe('412 Elm St')->and($deal->setup_status)->toBe('review')->and($deal->state_code)->toBe('TX')->and($deal->financing)->toBe('va');

    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}/setup")->assertInertia(fn (Assert $page) => $page->component('deals/setup')
        ->where('proposal.street', '412 Elm St')->where('proposal.price', '450000')->where('proposal.closing_date', '2026-07-03')->has('proposal.parties', 2));

    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/setup", ['type' => 'buyer', 'street' => '412 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'postal_code' => '78701',
        'price' => '450000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-07-03', 'property_type' => 'townhome', 'financing' => 'va',
        'parties' => [['role' => 'buyer', 'name' => 'Ana Garcia', 'email' => 'ana@example.test'], ['role' => 'buyer', 'name' => 'Luis Garcia', 'email' => ''], ['role' => 'lender', 'name' => 'Sam Banks', 'email' => 'sam@lender.test']]])
        ->assertRedirect("/deals/{$deal->ulid}");
    $deal->refresh();
    expect($deal->setup_status)->toBeNull()
        ->and(DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->count())->toBe(3)
        ->and(Item::query()->where('deal_id', $deal->id)->pluck('label')->all())->toContain('VA amendatory (escape) clause', 'HOA documents and resale certificate')
        // Earnest money: Jun 1 + 3 = Jun 4; closing Friday Jul 3 is the observed Independence Day.
        ->and(timelineDates($deal))->toMatchArray(['Earnest money due' => '2026-06-04', 'Final walk-through' => '2026-07-02', 'Check the closing date' => '2026-07-03']);

    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/setup", ['type' => 'buyer', 'street' => '', 'state_code' => 'TX'])->assertSessionHasErrors('street');
});

it('starts a deal from a contract upload and waits while it is read', function (): void {
    $this->actingAs($this->agent)->post('/deals/intake', ['file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf')])->assertRedirect();
    $deal = Deal::query()->latest('id')->firstOrFail();
    expect($deal->setup_status)->toBe('reading')->and(Document::query()->where('deal_id', $deal->id)->count())->toBe(1);
    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}/setup")->assertInertia(fn (Assert $page) => $page->component('deals/setup')->has('proposal.reading'));
});

it('proposes actions in plain English, does only what is confirmed, and rebuilds the timeline', function (): void {
    $this->deal->update(['contract_date' => '2026-06-01', 'closing_date' => '2026-07-15']);
    addParty($this->deal, 'lender', 'Sam Banks', 'sam@lender.test');
    $this->ai->respond(json_encode(['answer' => 'Done, once you confirm.', 'actions' => [
        ['type' => 'set_date', 'params' => ['label' => 'Closing date', 'date' => '2026-07-24']],
        ['type' => 'message_party', 'params' => ['role' => 'lender', 'subject' => 'Closing moved', 'body' => 'Closing moved to July 24. Please confirm.']],
        ['type' => 'add_checklist_item', 'params' => ['label' => 'Closing extension addendum']],
        ['type' => 'message_party', 'params' => ['role' => 'title', 'body' => 'Hi']],
        ['type' => 'delete_deal', 'params' => []],
    ]]));
    $turn = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant", ['text' => 'Push closing to the 24th and tell the lender'])->assertOk()->json();
    expect($turn['actions'])->toHaveCount(4) // delete_deal is never offered
        ->and($turn['actions'][0]['says'])->toBe('Move "Closing date" to Friday, Jul 24')
        ->and($turn['actions'][1]['says'])->toContain('Sam Banks (lender)')
        ->and($turn['actions'][3]['problem'])->toBe('Add the title with an email address to the deal first.');

    // The agent unticks the checklist item.
    $results = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}", ['chosen' => [0, 1, 3]])->assertOk()->json('results');
    expect(array_column($results, 'done'))->toBe([true, true, false])
        ->and($this->deal->refresh()->closing_date?->toDateString())->toBe('2026-07-24')
        ->and(timelineDates($this->deal)['Final walk-through'])->toBe('2026-07-23')
        ->and(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Closing extension addendum')->exists())->toBeFalse();
    Notification::assertSentOnDemand(PartyMessageMail::class, fn ($m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'sam@lender.test');

    // Done once only.
    $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}", ['chosen' => [0]])->assertUnprocessable();
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('dealIq.history.0.status', 'done')
        ->where('dealIq.history.0.said', 'Push closing to the 24th and tell the lender'));
});

it('never sends wiring instructions to a party', function (): void {
    addParty($this->deal, 'buyer', 'Ana Garcia', 'ana@example.test');
    $this->ai->respond(json_encode(['answer' => '', 'actions' => [
        ['type' => 'message_party', 'params' => ['role' => 'buyer', 'body' => 'Please wire the earnest money to routing number 021000021, account 12345678.']],
    ]]));
    $turn = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant", ['text' => 'send the buyer the wiring details'])->json();
    expect($turn['actions'][0]['problem'])->toBe('Messages can\'t contain bank or wiring details.');
    $results = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}", ['chosen' => [0]])->json('results');
    expect($results[0]['done'])->toBeFalse();
    Notification::assertNotSentTo(new AnonymousNotifiable, PartyMessageMail::class);
    Notification::assertCount(0);
});

it('drafts a form through the forms module and links to the draft', function (): void {
    app()->instance(FormDrafter::class, new class implements FormDrafter
    {
        public function draft(Deal $deal, string $instruction, User $by): ?array
        {
            return str_contains($instruction, 'extension') ? ['packet' => '01JTESTPACKET0000000000000', 'form' => 'Amendment to contract', 'filled' => ['closing_date' => '2026-07-24'], 'flags' => ['Check the new date']] : null;
        }
    });
    $this->ai->respond(json_encode(['answer' => '', 'actions' => [
        ['type' => 'draft_form', 'params' => ['instruction' => 'a 9-day closing extension']],
        ['type' => 'draft_form', 'params' => ['instruction' => 'a pet addendum']],
    ]]));
    $turn = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant", ['text' => 'draft an extension and a pet addendum'])->json();
    $results = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}", ['chosen' => [0, 1]])->json('results');
    expect($results[0]['done'])->toBeTrue()->and($results[0]['link'])->toBe('/forms/packets/01JTESTPACKET0000000000000')->and($results[0]['note'])->toContain('Check the new date')
        ->and($results[1]['done'])->toBeFalse();
});

it('keeps Deal-IQ to people who can see the deal, and dismisses proposals', function (): void {
    $other = User::factory()->create();
    $this->ai->respond(json_encode(['answer' => 'ok', 'actions' => [['type' => 'add_checklist_item', 'params' => ['label' => 'Survey']]]]));
    $this->actingAs($other)->postJson("/deals/{$this->deal->ulid}/assistant", ['text' => 'hi'])->assertForbidden();
    $this->actingAs($other)->post("/deals/{$this->deal->ulid}/upload-requests", ['what' => 'x', 'role' => 'lender', 'name' => 'x', 'email' => 'x@x.test'])->assertForbidden();
    $turn = $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant", ['text' => 'add a survey'])->json();
    $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}/dismiss")->assertOk();
    $this->actingAs($this->agent)->postJson("/deals/{$this->deal->ulid}/assistant/{$turn['ulid']}", ['chosen' => [0]])->assertUnprocessable();
    expect(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Survey')->exists())->toBeFalse();
});
