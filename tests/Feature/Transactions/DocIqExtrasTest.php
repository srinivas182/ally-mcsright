<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Forms\SmartForm;
use Modules\Forms\Templates;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\Explainer;
use Modules\Transactions\Reading\VersionCompare;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([Explainer::FEATURE => 'ai.doc_explain', SmartForm::FEATURE => 'ai.smart_fields'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->item = Item::query()->create(['deal_id' => $this->deal->id, 'label' => 'Purchase agreement', 'required' => true, 'stage' => 'contract', 'sort' => 99, 'status' => 'pending']);
});

/** A PDF with one page per list of lines. */
function fb1Pdf(array $pages): string
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    foreach ($pages as $lines) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 11);
        foreach ($lines as $n => $line) {
            $pdf->Text(60, 70 + $n * 24, $line);
        }
    }

    return $pdf->Output('S');
}

/** A stored, already-read version of the purchase agreement. */
function fb1Doc(object $t, int $version, array $pages, array $facts, array $methods = ['text']): Document
{
    $path = "tx/{$t->deal->id}/v{$version}.pdf";
    Storage::disk('local')->put($path, fb1Pdf($pages));
    $doc = Document::query()->create(['deal_id' => $t->deal->id, 'item_id' => $t->item->id, 'filename' => 'Contract.pdf', 'path' => $path, 'mime' => 'application/pdf',
        'size' => 1000, 'sha256' => hash('sha256', $path), 'version' => $version, 'via' => 'upload', 'uploaded_by' => $t->agent->id]);
    DocumentReading::query()->create(['document_id' => $doc->id, 'status' => 'read', 'pages' => count($pages), 'page_text' => array_map(fn ($p) => implode("\n", $p), $pages),
        'methods' => $methods, 'facts' => $facts]);

    return $doc;
}

it('shows what changed from the last version: facts and lines added or taken out', function (): void {
    $v1 = fb1Doc($this, 1, [['Sales Price: $485,000.00', 'Closing Date: 07/15/2026', 'Seller pays the survey.']],
        [['name' => 'price', 'value' => '$485,000.00', 'page' => 1, 'quote' => 'Sales Price: $485,000.00'], ['name' => 'lender', 'value' => 'Big Bank', 'page' => 1, 'quote' => 'x']]);
    $v2 = fb1Doc($this, 2, [['Sales Price: $475,000.00', 'Closing Date: 07/15/2026', 'Buyer pays the survey.', 'Seller credits $3,000 at closing.']],
        [['name' => 'price', 'value' => '$475,000.00', 'page' => 1, 'quote' => 'Sales Price: $475,000.00']], ['ocr']);

    expect(VersionCompare::previous($v2)?->id)->toBe($v1->id)
        ->and(VersionCompare::compare($v1)['ready'])->toBeFalse();

    $this->actingAs($this->agent)->post("/deals/documents/{$v2->ulid}/compare")->assertSessionHas('docCompare', function (array $c): bool {
        expect($c['facts'])->toBe([['label' => 'Price', 'before' => '$485,000.00', 'after' => '$475,000.00'], ['label' => 'Lender', 'before' => 'Big Bank', 'after' => '(no longer stated)']])
            ->and($c['added'])->toBe(['Sales Price: $475,000.00', 'Buyer pays the survey.', 'Seller credits $3,000 at closing.'])
            ->and($c['removed'])->toBe(['Sales Price: $485,000.00', 'Seller pays the survey.'])
            ->and($c['scanned'])->toBeTrue()
            ->and($c['before']['version'])->toBe(1);

        return true;
    });
    $this->actingAs(User::factory()->create())->post("/deals/documents/{$v2->ulid}/compare")->assertNotFound();
});

it('explains a document in plain English or Spanish, keeping only points that are really in it', function (): void {
    app(CreditLedger::class)->add(null, 50, 'adjust', 'Test credits', $this->owner);
    $doc = fb1Doc($this, 1, [['Sales Price: $485,000.00', 'Closing Date: 07/15/2026', 'Buyer must apply for the loan within 5 days.']], [], ['text', 'ocr']);
    $this->ai->respond(json_encode(['headline' => 'Este es el contrato de compra por $485,000.', 'points' => [
        ['text' => 'El precio es $485,000.', 'page' => 1, 'quote' => 'Sales Price: $485,000.00', 'kind' => 'money'],
        ['text' => 'El cierre es el 15 de julio.', 'page' => 1, 'quote' => 'Closing on July 15', 'kind' => 'date'], // quote not on the page
        ['text' => 'Usted paga $9,999 de impuestos.', 'page' => 1, 'quote' => 'Sales Price: $485,000.00', 'kind' => 'money'], // amount not in the document
    ]]));

    $this->actingAs($this->agent)->post("/deals/documents/{$doc->ulid}/explain", ['lang' => 'es'])->assertSessionHas('docExplain', function (array $e): bool {
        expect($e['lang'])->toBe('es')
            ->and($e['headline'])->toBe('Este es el contrato de compra por $485,000.')
            ->and(array_column($e['points'], 'text'))->toBe(['El precio es $485,000.'])
            ->and($e['removed'])->toBe(2)
            ->and($e['scanned'])->toBe(Explainer::SCANNED['es'])
            ->and($e['disclaimer'])->toBe(Explainer::DISCLAIMER['es']);

        return true;
    });
    expect($this->ai->requests[0]->userMessage)->toContain('[Page 1]');
    $this->actingAs($this->agent)->post("/deals/documents/{$doc->ulid}/explain", ['lang' => 'fr'])->assertSessionHasErrors('lang');
});

it('shares a safe copy by a short-lived link that is private and logged', function (): void {
    $doc = fb1Doc($this, 1, [['Seller: Maria Gomez', 'Refund account 12345678']], []);
    $this->actingAs($this->agent)->post("/deals/documents/{$doc->ulid}/share", ['days' => 9])->assertSessionHasErrors('days');
    $link = $this->actingAs($this->agent)->post("/deals/documents/{$doc->ulid}/share", ['days' => 3, 'to' => 'Lone Star Title'])->getSession()->get('docShare')['link'];

    expect($link)->toContain('/shared/doc/'.$doc->ulid);
    auth()->logout();
    $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get(str_replace('signature=', 'signature=x', $link))->assertForbidden();
    $this->travel(4)->days();
    $this->get($link)->assertForbidden();
    expect(DB::table('audit_events')->whereIn('action', ['transactions.document.safe_shared', 'transactions.document.safe_opened'])->count())->toBe(2);
});

it('turns a plain PDF into a smart form: blanks become fields tied to the deal and signers', function (): void {
    $pdf = fb1Pdf([['Purchase Price: $______________', 'Property Address: ____________________', '[] Seller pays the home warranty',
        'Buyer Signature ____________________']]);
    $ulid = app(Templates::class)->fromPdf('Our addendum', $pdf, [], $this->owner);

    $this->actingAs($this->owner)->post("/forms/templates/{$ulid}/find-fields", ['ai' => false])->assertSessionHasNoErrors();
    $fields = collect(app(Templates::class)->find($ulid)['fields'])->keyBy('label');

    expect($fields->keys()->all())->toBe(['Purchase price', 'Property address', 'Seller pays the home warranty', 'Buyer signature'])
        ->and($fields['Purchase price']['bind'])->toBe('deal.price')
        ->and($fields['Property address']['bind'])->toBe('deal.address')
        ->and($fields['Seller pays the home warranty']['type'])->toBe('checkbox')
        ->and($fields['Buyer signature']['type'])->toBe('signature')
        ->and($fields['Buyer signature']['role'])->toBe('buyer1')
        ->and($fields['Purchase price']['page'])->toBe(1);

    // Running it again adds nothing on top of the fields already placed.
    $this->actingAs($this->owner)->post("/forms/templates/{$ulid}/find-fields", ['ai' => false]);
    expect(app(Templates::class)->find($ulid)['fields'])->toHaveCount(4);
    $this->actingAs($this->agent)->post("/forms/templates/{$ulid}/find-fields")->assertForbidden();
});

it('lets AI tidy the names and links, but only to links and signers on our list', function (): void {
    app(CreditLedger::class)->add(null, 50, 'adjust', 'Test credits', $this->owner);
    $pdf = fb1Pdf([['Price $____________', 'Sign ____________________']]);
    $ulid = app(Templates::class)->fromPdf('Short form', $pdf, [], $this->owner);
    $this->ai->respond(json_encode(['fields' => [
        ['n' => 0, 'label' => 'Purchase price', 'type' => 'text', 'bind' => 'bank.routing', 'required' => true], // not an allowed link: keeps its own
        ['n' => 1, 'label' => 'Seller 1 signature', 'type' => 'signature', 'role' => 'seller1', 'required' => true],
    ]]));

    $this->actingAs($this->owner)->post("/forms/templates/{$ulid}/find-fields")->assertSessionHasNoErrors();
    $fields = app(Templates::class)->find($ulid)['fields'];

    expect(array_column($fields, 'label'))->toBe(['Purchase price', 'Seller 1 signature'])
        ->and($fields[0]['bind'])->toBe('deal.price')
        ->and($fields[1]['type'])->toBe('signature')
        ->and($fields[1]['role'])->toBe('seller1')
        ->and($fields[1]['required'])->toBeTrue();
});

it('says so when a form has no blanks to find', function (): void {
    $ulid = app(Templates::class)->fromPdf('Letter', fb1Pdf([['Dear client, thank you.']]), [], $this->owner);

    $this->actingAs($this->owner)->post("/forms/templates/{$ulid}/find-fields", ['ai' => false])->assertSessionHasErrors('template');
});
