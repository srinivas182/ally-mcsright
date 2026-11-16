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
use Modules\Transactions\DealIq\FormDrafter;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Offers\Compare;
use Modules\Transactions\Offers\OfferNoticeMail;
use Modules\Transactions\Offers\OfferReader;
use Modules\Transactions\Offers\OfferReceivedMail;
use Modules\Transactions\Offers\OfferSummary;
use Modules\Transactions\Portal\PortalCodeMail;
use Modules\Transactions\Portal\PortalMail;
use Modules\Transactions\Repairs\RepairRequests;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([OfferReader::FEATURE => 'ai.offer_read', OfferSummary::FEATURE => 'ai.offer_summary', RepairRequests::FEATURE => 'ai.repair_read'] as $f => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '450000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->drafts = [];
    $test = $this;
    app()->instance(FormDrafter::class, new class($test) implements FormDrafter
    {
        public function __construct(private object $test) {}

        public function draft(Deal $deal, string $instruction, User $by): ?array
        {
            $this->test->drafts[] = $instruction;

            return ['packet' => '01JDRAFT00000000000000000'.count($this->test->drafts), 'form' => 'Amendment', 'filled' => [], 'flags' => []];
        }
    });
});

function offersPdf(array $pages): string
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    foreach ($pages as $lines) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 11);
        foreach ($lines as $n => $line) {
            $pdf->Text(60, 70 + $n * 20, $line);
        }
    }

    return $pdf->Output('S');
}

/** An offer sent through the listing's link by a buyer's agent; returns their revise token. */
function sendOffer(object $test, string $link, array $terms): string
{
    auth()->logout();
    $test->post($link, $terms)->assertRedirect();
    $token = null;
    Notification::assertSentOnDemand(OfferNoticeMail::class, function (OfferNoticeMail $m, $c, AnonymousNotifiable $to) use ($terms, &$token): bool {
        if ($to->routes['mail'] !== $terms['agent_email'] || $m->url === null) {
            return false;
        }
        $token = substr($m->url, strrpos($m->url, '/') + 1);

        return true;
    });

    return (string) $token;
}

function offerLink(object $test): string
{
    $test->actingAs($test->agent)->post("/deals/{$test->deal->ulid}/offers/link")->assertSessionHas('offerLink');

    return parse_url((string) session('offerLink'), PHP_URL_PATH);
}

it('works out escalation, net to seller and how likely each offer is to close', function (): void {
    $a = (object) ['id' => 1, 'price_cents' => 45_000_000, 'escalation_cap_cents' => 47_000_000, 'escalation_step_cents' => 200_000];
    $b = (object) ['id' => 2, 'price_cents' => 46_000_000, 'escalation_cap_cents' => null, 'escalation_step_cents' => null];
    expect(Compare::prices([$a, $b]))->toBe([1 => 46_200_000, 2 => 46_000_000]);
    $b->price_cents = 48_000_000;
    expect(Compare::prices([$a, $b])[1])->toBe(47_000_000); // capped

    $offer = (object) ['concessions_cents' => 500_000, 'buyer_agent_cents' => 1_200_000];
    expect(Compare::net($offer, 46_000_000, ['listing_percent' => 3.0, 'title_percent' => 1.0, 'payoff_cents' => 20_000_000, 'other_cents' => 100_000])['net_cents'])
        ->toBe(46_000_000 - 1_380_000 - 460_000 - 500_000 - 1_200_000 - 20_000_000 - 100_000);

    $cash = (object) ['financing' => 'cash', 'proof' => 'proof_of_funds', 'contingencies' => '["inspection"]', 'option_days' => 5, 'appraisal_gap_cents' => null, 'closing_date' => '2026-06-20', 'expires_at' => null];
    $fha = (object) ['financing' => 'fha', 'proof' => 'prequal', 'contingencies' => '["financing","appraisal","sale_of_home"]', 'option_days' => 10, 'appraisal_gap_cents' => null, 'closing_date' => '2026-09-01', 'expires_at' => null];
    expect(Compare::certainty($cash, 45_000_000))->toMatchArray(['score' => 95, 'grade' => 'Strong'])
        ->and(Compare::certainty($fha, 45_000_000)['grade'])->toBe('Weaker')
        ->and(Compare::certainty($fha, 45_000_000)['reasons'])->toContain('Depends on selling their own home', 'Pre-qualified only, not pre-approved', 'Closes more than 60 days out');
});

it('takes offers from buyers\' agents through the listing\'s link, never indexed, and lets them revise', function (): void {
    $link = offerLink($this);
    $this->get($link)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page->component('public/offer')->where('valid', true)->where('listing.street', '12 Oak St'));
    $revise = sendOffer($this, $link, ['agent_name' => 'Ann Agent', 'agent_email' => 'ann@buyers.test', 'brokerage' => 'Buyers Realty', 'buyer_names' => 'John and Mary Smith',
        'price' => '455000', 'financing' => 'conventional', 'proof' => 'preapproval', 'contingencies' => ['inspection', 'financing'], 'option_days' => 7, 'closing_date' => '2026-07-15',
        'file' => UploadedFile::fake()->createWithContent('offer.pdf', offersPdf([['Offer', 'Price $455,000']]))]);
    Notification::assertSentTo($this->agent, OfferReceivedMail::class);
    $offer = DB::table('tx_offers')->first();
    expect($offer->label)->toBe('A')->and((int) $offer->price_cents)->toBe(45_500_000)->and($offer->file_path)->not->toBeNull()
        ->and(Document::query()->where('deal_id', $this->deal->id)->count())->toBe(0); // an offer is not a deal document

    $this->get("/offer/revise/{$revise}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('offer.label', 'A')->where('offer.price_cents', 45_500_000));
    $this->post("/offer/revise/{$revise}", ['price' => '458000', 'financing' => 'conventional', 'proof' => 'preapproval', 'contingencies' => ['inspection']])->assertRedirect();
    $offer = DB::table('tx_offers')->first();
    expect((int) $offer->price_cents)->toBe(45_800_000)->and($offer->agent_name)->toBe('Ann Agent')->and(count(json_decode((string) $offer->history, true)))->toBe(1);

    // A new link stops the old one.
    offerLink($this);
    auth()->logout();
    $this->get($link)->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->post($link, ['agent_name' => 'X', 'agent_email' => 'x@x.test', 'price' => '1'])->assertNotFound();
});

it('sends every buyer\'s agent the same words at the same moment, and closes revisions at the deadline', function (): void {
    $link = offerLink($this);
    sendOffer($this, $link, ['agent_name' => 'Ann', 'agent_email' => 'ann@buyers.test', 'price' => '455000', 'financing' => 'cash', 'proof' => 'proof_of_funds']);
    sendOffer($this, $link, ['agent_name' => 'Bob', 'agent_email' => 'bob@buyers.test', 'price' => '460000', 'financing' => 'fha', 'proof' => 'preapproval']);
    Notification::fake();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/notify", ['kind' => 'highest_best', 'message' => 'Highest and best please.', 'deadline' => '2026-06-03 17:00'])->assertRedirect();
    $tokens = [];
    Notification::assertSentOnDemandTimes(OfferNoticeMail::class, 2);
    Notification::assertSentOnDemand(OfferNoticeMail::class, function (OfferNoticeMail $m) use (&$tokens): bool {
        $tokens[] = substr((string) $m->url, strrpos((string) $m->url, '/') + 1);

        return $m->message === 'Highest and best please.' && $m->deadline?->toDateString() === '2026-06-03';
    });
    $notice = DB::table('tx_offer_notices')->first();
    expect(json_decode((string) $notice->recipients, true))->toHaveCount(2);

    auth()->logout();
    $this->post("/offer/revise/{$tokens[0]}", ['price' => '470000', 'financing' => 'cash', 'proof' => 'proof_of_funds'])->assertRedirect();
    expect((int) DB::table('tx_offers')->where('label', 'A')->value('round'))->toBe(2);
    $this->travelTo(CarbonImmutable::parse('2026-06-04 12:00', 'UTC'));
    $this->get("/offer/revise/{$tokens[1]}")->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->post("/offer/revise/{$tokens[1]}", ['price' => '480000'])->assertNotFound();

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/notify", ['kind' => 'highest_best', 'message' => 'Again', 'deadline' => '2026-06-01 10:00'])->assertSessionHasErrors('deadline');
});

it('reads an uploaded offer, keeping only terms it can point to on the page', function (): void {
    $pdf = offersPdf([['RESIDENTIAL PURCHASE OFFER', 'Buyer\'s agent: Carla Diaz, Lone Star Homes', 'Purchase price: $452,000', 'Financing: FHA', 'Closing Date: 07/20/2026']]);
    $this->ai->respond(json_encode(['terms' => [
        ['name' => 'price', 'value' => '$452,000', 'page' => 1, 'quote' => 'Purchase price: $452,000'],
        ['name' => 'financing', 'value' => 'fha', 'page' => 1, 'quote' => 'Financing: FHA'],
        ['name' => 'closing_date', 'value' => '07/20/2026', 'page' => 1, 'quote' => 'Closing Date: 07/20/2026'],
        ['name' => 'earnest_money', 'value' => '$9,000', 'page' => 1, 'quote' => 'Earnest money $9,000'], // not on the page
        ['name' => 'agent_name', 'value' => 'Carla Diaz', 'page' => 1, 'quote' => 'Buyer\'s agent: Carla Diaz'],
    ]]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/upload", ['file' => UploadedFile::fake()->createWithContent('offer.pdf', $pdf)])->assertRedirect();
    $o = DB::table('tx_offers')->first();
    expect((int) $o->price_cents)->toBe(45_200_000)->and($o->financing)->toBe('fha')->and(substr((string) $o->closing_date, 0, 10))->toBe('2026-07-20')
        ->and($o->agent_name)->toBe('Carla Diaz')->and($o->earnest_cents)->toBeNull()->and(array_keys(json_decode((string) $o->read, true)))->not->toContain('earnest_money');
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}/offers")->assertInertia(fn (Assert $page) => $page->component('deals/offers')
        ->where('offers.0.label', 'Offer A')->where('offers.0.read.price.page', 1)->where('canSeeCommission', true));
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}/offers/{$o->ulid}/file")->assertOk();
});

it('drafts a counter-offer and accepting tells everyone else at once', function (): void {
    $link = offerLink($this);
    sendOffer($this, $link, ['agent_name' => 'Ann', 'agent_email' => 'ann@buyers.test', 'buyer_names' => 'John Smith & Mary Smith', 'price' => '455000', 'financing' => 'fha',
        'proof' => 'preapproval', 'closing_date' => '2026-07-10', 'escalation_cap' => '470000', 'escalation_step' => '1000']);
    sendOffer($this, $link, ['agent_name' => 'Bob', 'agent_email' => 'bob@buyers.test', 'price' => '460000', 'financing' => 'cash', 'proof' => 'proof_of_funds']);
    $a = DB::table('tx_offers')->where('label', 'A')->first();

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/{$a->ulid}/counter", ['closing_date' => '2026-07-17'])->assertSessionHas('packet');
    expect($this->drafts[0])->toContain('closing on 2026-07-17')->and(DB::table('tx_offers')->where('id', $a->id)->value('status'))->toBe('countered');

    Notification::fake();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/{$a->ulid}/accept")->assertRedirect("/deals/{$this->deal->ulid}");
    $deal = $this->deal->refresh();
    expect($deal->price_cents)->toBe(46_100_000) // escalated above $460,000 by $1,000
        ->and($deal->closing_date?->toDateString())->toBe('2026-07-10')->and($deal->financing)->toBe('fha')
        ->and(DB::table('tx_parties')->where('deal_id', $deal->id)->pluck('role')->all())->toBe(['co_op_agent', 'buyer', 'buyer'])
        ->and(Item::query()->where('deal_id', $deal->id)->where('label', 'FHA amendatory clause')->exists())->toBeTrue()
        ->and(DB::table('tx_offers')->where('label', 'B')->value('status'))->toBe('declined');
    Notification::assertSentOnDemandTimes(OfferNoticeMail::class, 1);
    Notification::assertSentOnDemand(OfferNoticeMail::class, fn (OfferNoticeMail $m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'bob@buyers.test' && $m->url === null);
});

it('writes the seller summary only from the figures, and shows it to sellers without buyers\' names', function (): void {
    $link = offerLink($this);
    sendOffer($this, $link, ['agent_name' => 'Ann', 'agent_email' => 'ann@buyers.test', 'buyer_names' => 'John Smith', 'price' => '455000', 'financing' => 'cash', 'proof' => 'proof_of_funds']);
    sendOffer($this, $link, ['agent_name' => 'Bob', 'agent_email' => 'bob@buyers.test', 'price' => '460000', 'financing' => 'fha', 'proof' => 'prequal', 'contingencies' => ['sale_of_home']]);

    // An amount that is not in the comparison: the words are written from the figures instead.
    $this->ai->respond(json_encode(['en' => 'Offer B is worth $999,999 to you.', 'es' => 'La Oferta B vale $999,999.']));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/summary")->assertRedirect();
    $s = OfferSummary::latest($this->deal);
    expect($s['ai'])->toBeFalse()->and($s['en'])->toContain('nets the most')->and($s['en'])->not->toContain('999,999');

    $this->ai->respond(json_encode(['en' => 'Offer B is higher, but Offer A is cash and nets $436,800.', 'es' => 'La Oferta A es de contado y le deja $436,800.']));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/summary");
    $s = OfferSummary::latest($this->deal);
    expect($s['ai'])->toBeTrue();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/summary/save", ['en' => $s['en'].' I recommend A.', 'es' => $s['es'], 'share' => true])->assertRedirect();

    // The seller sees it in the portal, in Spanish; no buyer names or agents anywhere.
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal", ['role' => 'seller', 'name' => 'Sam Seller', 'email' => 'sam@example.test', 'locale' => 'es']);
    $token = null;
    Notification::assertSentOnDemand(PortalMail::class, function (PortalMail $m, $c, AnonymousNotifiable $to) use (&$token): bool {
        $token = substr($m->url, strrpos($m->url, '/') + 1);

        return $to->routes['mail'] === 'sam@example.test';
    });
    auth()->logout();
    $this->post("/my-deal/in/{$token}/code", ['email' => 'sam@example.test']);
    $code = null;
    Notification::assertSentOnDemand(PortalCodeMail::class, function (PortalCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });
    $ulid = (string) DB::table('tx_portal_access')->value('ulid');
    $this->post("/my-deal/in/{$token}/verify", ['code' => $code])->assertRedirect("/my-deal/{$ulid}");
    $page = $this->get("/my-deal/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('offers.text', 'La Oferta A es de contado y le deja $436,800.')
        ->where('offers.offers.0.label', 'Oferta B')->where('offers.offers.1.label', 'Oferta A')->where('offers.offers.1.grade', 'Alta')->where('offers.offers.1.net_cents', 43_680_000));
    expect($page->getContent())->not->toContain('John Smith')->not->toContain('bob@buyers.test');
});

it('turns an inspection report into a repair request the agent edits, drafts and shares with the buyers', function (): void {
    $this->deal->update(['type' => 'buyer']);
    $pdf = offersPdf([['HOME INSPECTION REPORT', 'Electrical: double-tapped breaker in main panel (safety hazard).', 'Roof: two missing shingles on the north slope.'],
        ['Plumbing: slow drain in the hall bathroom sink.', 'HVAC: replace the air filter.']]);
    $this->ai->respond(json_encode(['items' => [
        ['title' => 'Missing shingles', 'location' => 'North slope', 'category' => 'major', 'page' => 1, 'quote' => 'two missing shingles on the north slope', 'suggest' => 'repair'],
        ['title' => 'Double-tapped breaker', 'location' => 'Main panel', 'category' => 'safety', 'page' => 1, 'quote' => 'double-tapped breaker in main panel', 'suggest' => 'repair'],
        ['title' => 'Foundation crack', 'location' => 'Garage', 'category' => 'major', 'page' => 2, 'quote' => 'large foundation crack', 'suggest' => 'repair'], // not in the report
        ['title' => 'Air filter', 'location' => 'HVAC', 'category' => 'maintenance', 'page' => 2, 'quote' => 'replace the air filter', 'suggest' => 'repair'],
    ]]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/repairs", ['file' => UploadedFile::fake()->createWithContent('inspection.pdf', $pdf)])->assertRedirect();
    $r = RepairRequests::forDeal($this->deal)[0];
    expect(array_column($r['items'], 'title'))->toBe(['Double-tapped breaker', 'Missing shingles', 'Air filter'])
        ->and($r['items'][2]['ask'])->toBe('none'); // routine maintenance is not asked for

    $items = $r['items'];
    $items[1]['ask'] = 'credit';
    $items[1]['credit'] = '850';
    $items[0]['note'] = 'By a licensed electrician';
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/repairs/{$r['ulid']}", ['items' => $items])->assertRedirect();
    $items[0]['note'] = 'Wire to routing 021000021 account 12345678';
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/repairs/{$r['ulid']}", ['items' => $items])->assertSessionHasErrors('note');

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/repairs/{$r['ulid']}/draft")->assertSessionHas('packet');
    expect($this->drafts[0])->toContain('Double-tapped breaker (Main panel): By a licensed electrician')->toContain('credit of $850')->not->toContain('Air filter');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/repairs/{$r['ulid']}/share", ['share' => true]);
    expect(RepairRequests::forDeal($this->deal, true))->toHaveCount(1)->and(RepairRequests::forDeal($this->deal)[0]['credit_cents'])->toBe(85_000);
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}/repairs")->assertInertia(fn (Assert $page) => $page->component('deals/repairs')->where('requests.0.status', 'drafted'));
});

it('keeps offers and repairs to people who can see the deal', function (): void {
    $other = User::factory()->create();
    $this->actingAs($other)->get("/deals/{$this->deal->ulid}/offers")->assertForbidden();
    $this->actingAs($other)->post("/deals/{$this->deal->ulid}/offers/link")->assertForbidden();
    $this->actingAs($other)->get("/deals/{$this->deal->ulid}/repairs")->assertForbidden();
    $this->deal->update(['type' => 'buyer']);
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}/offers")->assertNotFound();
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('dealIq.offers', null));
});
