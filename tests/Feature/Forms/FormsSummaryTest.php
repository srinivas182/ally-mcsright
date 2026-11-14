<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Forms\ClauseCheck;
use Modules\Forms\Negotiations;
use Modules\Forms\Packets;
use Modules\Forms\SignatureRequested;
use Modules\Forms\Signing;
use Modules\Forms\Summaries;
use Modules\Forms\Templates;
use Modules\Transactions\DateReminder;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.test']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Summaries::FEATURE, 'primary_model_id' => $this->model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.forms_summary']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($this->model->provider->key, $this->ai);

    $pdf = new FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Text(72, 60, 'RESIDENTIAL LISTING AGREEMENT');
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '485000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'seller', 'name' => 'Maria Gomez', 'email' => 'maria@example.test',
        'created_at' => now(), 'updated_at' => now()]);
    $item = Item::query()->where('deal_id', $this->deal->id)->orderBy('sort')->firstOrFail();

    $templates = app(Templates::class);
    $t = $templates->upload('Listing', UploadedFile::fake()->createWithContent('l.pdf', $pdf->Output('S')), $item->label, $this->owner);
    $templates->saveFields($t, [
        ['key' => 'address', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 100, 'w' => 400, 'h' => 14, 'bind' => 'deal.address', 'label' => 'Address'],
        ['key' => 'price', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 130, 'w' => 200, 'h' => 14, 'bind' => 'deal.price', 'label' => 'List price'],
        ['key' => 'ends', 'type' => 'date', 'page' => 1, 'x' => 72, 'y' => 160, 'w' => 120, 'h' => 14, 'label' => 'Listing ends'],
        ['key' => 'special', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 190, 'w' => 400, 'h' => 60, 'label' => 'Special provisions'],
        ['key' => 'seller_sig', 'type' => 'signature', 'page' => 1, 'x' => 72, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'seller1', 'label' => 'Seller signature'],
        ['key' => 'agent_sig', 'type' => 'signature', 'page' => 1, 'x' => 320, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'listing_agent', 'label' => 'Agent signature'],
    ], $this->owner);
    $this->packet = app(Packets::class)->create($this->deal, 'Listing paperwork', [$t], $this->agent);
    $this->form = (int) DB::table('form_packet_forms')->value('id');
    app(Packets::class)->setValues($this->packet, $this->form, ['address' => '12 Oak St, Austin, TX', 'price' => '$485,000.00', 'ends' => '12/01/2026'], $this->agent);
});

function summaryJson(array $overrides = []): string
{
    return json_encode($overrides + [
        'headline' => 'Maria Gomez lists 12 Oak St with Acme for $485,000.00 until 12/01/2026.',
        'points' => [
            ['topic' => 'Price and money', 'text' => 'The list price is $485,000.'],
            ['topic' => 'Price and money', 'text' => 'You pay a 6% commission of $29,100 at closing.'], // invented: not in the documents
            ['topic' => 'Dates', 'text' => 'The listing ends on 12/01/2026.'],
            ['topic' => 'Dates', 'text' => 'Showings start 06/15/2026.'], // invented date
        ],
        'deadlines' => [
            ['label' => 'Listing ends', 'date' => '2026-12-01', 'rule' => 'End of the listing period'],
            ['label' => 'Photos taken', 'date' => '2026-06-10', 'rule' => 'Within 10 days'], // date not in the documents
        ],
        'agreeing_to' => ['Let Acme market the home for $485,000.', 'Pay $5,000 if you cancel.'],
        'unusual' => [],
        'es' => ['headline' => 'Maria Gomez pone en venta 12 Oak St por $485,000.', 'points' => [['topic' => 'Precio', 'text' => 'El precio es $485,000.']],
            'agreeing_to' => ['Permitir que Acme venda la casa.']],
    ]);
}

it('writes a summary from the filled-in terms only and drops any amount or date not in the documents', function (): void {
    $this->ai->respond(summaryJson());
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/summary")->assertSessionHasNoErrors();

    $prompt = $this->ai->requests[0]->userMessage;
    expect($prompt)->toContain('List price: $485,000.00')->toContain('Listing ends: 12/01/2026');
    $packet = DB::table('form_packets')->first();
    expect((string) $packet->summary)->not->toContain('485'); // encrypted at rest

    $s = Summaries::forAgent($packet);
    expect($s['status'])->toBe('draft')
        ->and(array_column($s['content']['points'], 'text'))->toBe(['The list price is $485,000.', 'The listing ends on 12/01/2026.'])
        ->and($s['content']['agreeing_to'])->toBe(['Let Acme market the home for $485,000.'])
        ->and($s['content']['removed'])->toBe(3)
        ->and($s['content']['deadlines'])->toBe([
            ['label' => 'Listing ends', 'date' => '2026-12-01', 'rule' => 'End of the listing period'],
            ['label' => 'Photos taken', 'date' => '', 'rule' => 'Within 10 days'],
        ])
        ->and($s['content']['es']['headline'])->toBe('Maria Gomez pone en venta 12 Oak St por $485,000.');
    expect(DB::table('form_events')->where('action', 'summary_written')->value('detail'))->toContain('3 line(s) removed');
});

it('shows signers only an approved summary of the current terms, with the disclaimer, in English and Spanish', function (): void {
    $this->ai->respond(summaryJson());
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/summary");
    expect(Summaries::forSigner(DB::table('form_packets')->first()))->toBeNull();

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/summary/approve")->assertSessionHasNoErrors();
    app(Signing::class)->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $url = null;
    Notification::assertSentOnDemand(SignatureRequested::class, function (SignatureRequested $n, array $c, object $to) use (&$url): bool {
        $url ??= $to->routes['mail'] === 'maria@example.test' ? $n->url : null;

        return true;
    });
    auth()->logout();
    $link = (string) parse_url((string) $url, PHP_URL_PATH);
    $this->get($link)->assertInertia(fn (Assert $p) => $p->component('public/sign')
        ->where('summary.en.headline', 'Maria Gomez lists 12 Oak St with Acme for $485,000.00 until 12/01/2026.')
        ->where('summary.en.disclaimer', Summaries::DISCLAIMER['en'])->where('summary.es.disclaimer', Summaries::DISCLAIMER['es'])
        ->where('summary.deadlines.0.date', '2026-12-01'));

    // Terms change after approval: the summary is stale and hidden until written and approved again.
    app(Negotiations::class)->amend($this->packet, $this->form, ['price' => '$470,000.00'], 'Price reduced', $this->agent, 'http://acme.rightally.app');
    expect(Summaries::forAgent(DB::table('form_packets')->first())['status'])->toBe('stale');
    $this->get($link)->assertInertia(fn (Assert $p) => $p->where('summary', null));
    expect(fn () => app(Summaries::class)->approve($this->packet, $this->agent))->toThrow(ValidationException::class, 'terms changed');
});

it('refuses to summarise empty forms or for someone not on the deal', function (): void {
    $other = User::factory()->create();
    expect(fn () => app(Summaries::class)->generate($this->packet, $other))->toThrow(Exception::class);
    app(Packets::class)->setValues($this->packet, $this->form, ['address' => '', 'price' => '', 'ends' => ''], $this->agent);
    expect(fn () => app(Summaries::class)->generate($this->packet, $this->agent))->toThrow(ValidationException::class, 'Fill in the forms first');
    expect($this->ai->requests)->toBe([]);
});

it('puts chosen deadlines on the deal key dates, and reminds the agent before they fall due', function (): void {
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/dates", ['dates' => [['label' => 'Listing ends', 'date' => '2026-12-01'], ['label' => 'Photos taken', 'date' => '06/03/2026']]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/dates", ['dates' => [['label' => 'Listing ends', 'date' => '2026-12-01']]]); // no duplicate
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/dates", ['dates' => [['label' => 'Open house', 'date' => '']]])->assertSessionHasErrors();
    expect(DB::table('tx_dates')->orderBy('due_on')->get(['label', 'due_on', 'source'])->map(fn ($d) => [$d->label, substr((string) $d->due_on, 0, 10), $d->source])->all())
        ->toBe([['Photos taken', '2026-06-03', 'forms'], ['Listing ends', '2026-12-01', 'forms']]);

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/dates", ['label' => 'Inspection period ends', 'due_on' => '2026-06-02'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $p) => $p->component('deals/show')->has('dates', 3)
        ->where('dates.0.label', 'Inspection period ends')->where('dates.0.soon', true)->where('dates.2.soon', false));

    $this->artisan('transactions:date-reminders')->assertSuccessful();
    Notification::assertSentTo($this->agent, DateReminder::class, fn (DateReminder $n): bool => $n->lines === ['Inspection period ends — Tue 2 Jun (tomorrow)', 'Photos taken — Wed 3 Jun (in 2 days)']);
    $this->artisan('transactions:date-reminders'); // once a day only
    Notification::assertSentToTimes($this->agent, DateReminder::class, 1);

    $id = (int) DB::table('tx_dates')->where('label', 'Photos taken')->value('id');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/dates/{$id}/toggle")->assertSessionHasNoErrors();
    $this->travel(2)->days();
    $this->artisan('transactions:date-reminders');
    Notification::assertSentTo($this->agent, DateReminder::class, fn (DateReminder $n): bool => $n->lines === ['Inspection period ends — Tue 2 Jun (missed yesterday)']);
    $this->actingAs(User::factory()->create())->post("/deals/{$this->deal->ulid}/dates", ['label' => 'X', 'due_on' => '2026-06-09'])->assertForbidden();
    $this->actingAs($this->agent)->delete("/deals/{$this->deal->ulid}/dates/{$id}")->assertSessionHasNoErrors();
    expect(DB::table('tx_dates')->count())->toBe(2);
});

it('spells out counter-offer differences exactly', function (): void {
    expect(Negotiations::line('List price', '$485,000.00', '$470,000.00'))->toBe('List price: $485,000.00 → $470,000.00 (down $15,000)')
        ->and(Negotiations::line('Earnest money', '5000', '7500.50'))->toBe('Earnest money: 5000 → 7500.50 (up $2,500.50)')
        ->and(Negotiations::line('Closing date', '07/01/2026', '07/11/2026'))->toBe('Closing date: 07/01/2026 → 07/11/2026 (10 days later)')
        ->and(Negotiations::line('Option period', '07/11/2026', '07/10/2026'))->toBe('Option period: 07/11/2026 → 07/10/2026 (1 day earlier)')
        ->and(Negotiations::line('Bedrooms', '3', '4'))->toBe('Bedrooms: 3 → 4')
        ->and(Negotiations::line('Address', '12 Oak St', '14 Oak St'))->toBe('Address: 12 Oak St → 14 Oak St');
});

it('finds special terms that are not library wording before sending, and can require broker approval', function (): void {
    $clause = 'Seller will leave the refrigerator, washer and dryer in the home at no additional cost to the buyer.';
    app(Templates::class)->addClause('Appliances', $clause, $this->owner);
    $pid = (int) DB::table('form_packets')->value('id');

    app(Packets::class)->setValues($this->packet, $this->form, ['special' => $clause], $this->agent);
    expect(ClauseCheck::review($pid))->toBe([]);

    $typed = $clause.' Seller will also pay for a one-year home warranty and any HOA transfer fees up to $1,500.';
    app(Packets::class)->setValues($this->packet, $this->form, ['special' => $typed], $this->agent);
    expect(ClauseCheck::review($pid))->toBe([['form' => 'Listing', 'label' => 'Special provisions', 'text' => $typed]]);
    expect(app(Packets::class)->check($this->packet, $this->agent))->not->toContain('Special terms that are not from your clause library need approval by the broker or office staff before sending.');

    $this->actingAs($this->agent)->post('/forms/clause-setting', ['clause_review' => 'require'])->assertSessionHasErrors('clause_review');
    $this->actingAs($this->owner)->post('/forms/clause-setting', ['clause_review' => 'require'])->assertSessionHasNoErrors();
    expect(fn () => app(Signing::class)->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app'))->toThrow(ValidationException::class, 'need approval');
    expect(fn () => app(ClauseCheck::class)->approve($this->packet, $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post("/forms/packets/{$this->packet}/clauses/approve")->assertSessionHasNoErrors();
    expect(ClauseCheck::approved(DB::table('form_packets')->first()))->toBeTrue();

    // Editing the wording after approval needs a new approval.
    app(Packets::class)->setValues($this->packet, $this->form, ['special' => $typed.' Buyer may move in early.'], $this->agent);
    expect(ClauseCheck::approved(DB::table('form_packets')->first()))->toBeFalse();
    $this->actingAs($this->owner)->post("/forms/packets/{$this->packet}/clauses/approve");
    app(Signing::class)->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    expect(DB::table('form_packets')->value('status'))->toBe('sent');
});

it('sends the packet, templates, deal and signing pages the data they need', function (): void {
    $this->ai->respond(summaryJson());
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/summary");
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/summary/approve");
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$this->packet}"), 'forms/packet');
    assertPageContract($this->actingAs($this->owner)->get('/forms/templates'), 'forms/templates');
    assertPageContract($this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}"), 'deals/show');
    app(Signing::class)->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $url = null;
    Notification::assertSentOnDemand(SignatureRequested::class, function (SignatureRequested $n) use (&$url): bool {
        $url ??= $n->url;

        return true;
    });
    auth()->logout();
    assertPageContract($this->get((string) parse_url((string) $url, PHP_URL_PATH)), 'public/sign');
});
