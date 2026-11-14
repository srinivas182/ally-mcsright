<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\DocumentReading;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Reading\DocTypes;
use Modules\Transactions\Reading\Requirements;
use Modules\Transactions\Reading\Sensitive;
use Symfony\Component\Process\Process;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

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
    foreach ([DocIq::READ => 'ai.doc_read', DocIq::ASK => 'ai.doc_ask'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
});

/** A PDF with one page per list of lines. */
function docPdf(array $pages, int $size = 11): string
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    foreach ($pages as $lines) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', $size);
        foreach ($lines as $n => $line) {
            $pdf->Text(60, 70 + $n * ($size + 9), $line);
        }
    }

    return $pdf->Output('S');
}

/** A "scanned" PDF: the page is an image only, with no text layer. */
function scannedPdf(array $lines): string
{
    $dir = sys_get_temp_dir().'/scan-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/page.pdf", docPdf([$lines], 22));
    (new Process(['pdftoppm', '-r', '150', '-png', '-singlefile', "{$dir}/page.pdf", "{$dir}/page"]))->mustRun();
    $pdf = new FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->Image("{$dir}/page.png", 0, 0, 612, 792);
    $out = $pdf->Output('S');
    array_map('unlink', glob("{$dir}/*") ?: []);
    rmdir($dir);

    return $out;
}

function pdfText(string $pdf): string
{
    $file = tempnam(sys_get_temp_dir(), 't').'.pdf';
    file_put_contents($file, $pdf);
    $p = new Process(['pdftotext', $file, '-']);
    $p->run();
    @unlink($file);

    return $p->getOutput();
}

function combinedFile(): string
{
    return docPdf([
        ['RESIDENTIAL PURCHASE AGREEMENT', 'Page 1 of 2', 'Buyer: John Smith   Seller: Maria Gomez', 'Property: 12 Oak St, Austin, TX',
            'Sales Price: $485,000.00', 'Closing Date: 07/15/2026', 'The property is subject to a homeowners association.'],
        ['Page 2 of 2', 'Option period ends 06/10/2026.', 'Earnest money: $5,000.00 held by Lone Star Title.', 'Year built: 1965'],
        ['LEAD-BASED PAINT DISCLOSURE', 'Housing built before 1978 may contain lead-based paint.', 'Seller has no reports or records.'],
        ["SELLER'S DISCLOSURE NOTICE", 'Seller: Maria Gomez   SSN 123-45-6789', 'Refund account number 12345678 routing 021000021', 'The roof was replaced in 2019.'],
    ]);
}

function factsJson(): string
{
    return json_encode(['facts' => [
        ['name' => 'price', 'value' => '$485,000.00', 'page' => 1, 'quote' => 'Sales Price: $485,000.00'],
        ['name' => 'closing_date', 'value' => '07/15/2026', 'page' => 1, 'quote' => 'Closing Date: 07/15/2026'],
        ['name' => 'option_period_end', 'value' => '06/10/2026', 'page' => 2, 'quote' => 'Option period ends 06/10/2026.'],
        ['name' => 'earnest_money', 'value' => '$9,999', 'page' => 2, 'quote' => 'Earnest money: $9,999 held in trust'], // not on the page
        ['name' => 'lender', 'value' => 'Big Bank', 'page' => 1, 'quote' => 'Sales Price: $485,000.00'], // quote does not state it
        ['name' => 'title_company', 'value' => 'Lone Star Title', 'page' => 2, 'quote' => 'held by Lone Star Title'],
        ['name' => 'favourite_colour', 'value' => 'blue', 'page' => 1, 'quote' => 'Buyer: John Smith'],
    ]]);
}

it('reads a whole-file upload, splits it into documents, files each into the checklist and adds what the file now needs', function (): void {
    $this->ai->respond(factsJson());
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Everything.pdf', combinedFile())])
        ->assertSessionHasNoErrors();

    $original = Document::query()->where('filename', 'Everything.pdf')->sole();
    expect($original->getAttribute('split_into'))->toBe(3)->and($original->item_id)->toBeNull();
    $reading = DocumentReading::query()->where('document_id', $original->id)->sole();
    expect($reading->status)->toBe('read')->and($reading->pages)->toBe(4)->and($reading->methods)->toBe(['text', 'text', 'text', 'text'])
        ->and(array_map(fn ($s) => [$s['type'], $s['from'], $s['to']], $reading->segments))->toBe([['purchase_agreement', 1, 2], ['lead_paint', 3, 3], ['seller_disclosure', 4, 4]])
        ->and((string) DB::table('tx_document_readings')->where('id', $reading->id)->value('page_text'))->not->toContain('Oak'); // encrypted at rest

    $filed = fn (string $label) => Document::query()->where('item_id', Item::query()->where('deal_id', $this->deal->id)->where('label', $label)->value('id'))->first();
    expect($filed('Purchase contract')?->getAttribute('pages_from'))->toBe(1)->and($filed('Purchase contract')?->getAttribute('pages_to'))->toBe(2)
        ->and($filed('Lead-based paint disclosure (homes built before 1978)'))->not->toBeNull()
        ->and($filed('Seller property disclosure'))->not->toBeNull()
        ->and(Item::query()->where('label', 'Purchase contract')->value('status'))->toBe('submitted');
    expect(pdfText((string) Storage::disk('local')->get($filed('Purchase contract')->path)))->toContain('RESIDENTIAL PURCHASE AGREEMENT')->not->toContain('LEAD-BASED');

    // The HOA mention adds HOA documents; the 1965 build date needs no new item because a lead-paint item exists.
    $hoa = Item::query()->where('label', 'HOA documents')->sole();
    expect($hoa->status)->toBe('missing')->and($hoa->getAttribute('added_reason'))->toBe('Added because page 1 of Everything.pdf mentions a homeowners association.')
        ->and(Item::query()->where('label', 'Lead-based paint disclosure')->exists())->toBeFalse();

    // Facts: only those whose quote is on the page and states the value.
    $facts = collect(DocIq::dealFacts($this->deal))->keyBy('name');
    expect($facts->keys()->sort()->values()->all())->toBe(['closing_date', 'option_period_end', 'price', 'title_company'])
        ->and($facts['price']['page'])->toBe(1);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/facts", ['facts' => ['price', 'closing_date', 'option_period_end', 'title_company']])->assertSessionHasNoErrors();
    $deal = $this->deal->fresh();
    expect($deal->price_cents)->toBe(48_500_000)->and($deal->closing_date?->toDateString())->toBe('2026-07-15')
        ->and(DB::table('tx_dates')->where('deal_id', $deal->id)->get(['label', 'due_on', 'source'])->map(fn ($d) => [$d->label, substr((string) $d->due_on, 0, 10), $d->source])->all())
        ->toBe([['Option period ends', '2026-06-10', 'docs']]);
});

it('warns about sensitive numbers and wiring instructions, and makes a safe copy with them removed', function (): void {
    $this->ai->respond(factsJson());
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Everything.pdf', combinedFile())]);
    $disclosure = Document::query()->where('filename', 'like', "Seller's disclosure%")->sole();
    expect(DocumentReading::query()->where('document_id', $disclosure->id)->value('sensitive'))->toBe(['ssn' => 1, 'bank' => 2, 'dob' => 0, 'wire' => true]);

    $safe = $this->actingAs($this->agent)->get("/deals/documents/{$disclosure->ulid}/safe")->assertOk();
    expect($safe->headers->get('Content-Type'))->toBe('application/pdf')->and(pdfText((string) $safe->getContent()))->not->toContain('123-45-6789')->not->toContain('021000021');
    $contract = Document::query()->where('filename', 'like', 'Purchase agreement%')->sole();
    expect(pdfText((string) $this->actingAs($this->agent)->get("/deals/documents/{$contract->ulid}/safe")->getContent()))->toContain('RESIDENTIAL PURCHASE AGREEMENT');
    $this->actingAs(User::factory()->create())->get("/deals/documents/{$disclosure->ulid}/safe")->assertForbidden();
});

it('reads scanned pages by OCR on our own servers and files them', function (): void {
    $this->ai->respond(json_encode(['facts' => []]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('scan.pdf',
        scannedPdf(['EARNEST MONEY RECEIPT', 'Received five thousand dollars', 'from John Smith for 12 Oak St']))])->assertSessionHasNoErrors();
    $doc = Document::query()->where('filename', 'scan.pdf')->sole();
    $reading = DocumentReading::query()->where('document_id', $doc->id)->sole();
    expect($reading->methods)->toBe(['ocr'])->and($reading->doc_type)->toBe('earnest_money')
        ->and(strtoupper((string) $reading->page_text[0]))->toContain('EARNEST MONEY RECEIPT')
        ->and(Item::query()->whereKey($doc->item_id)->value('label'))->toBe('Earnest money receipt');
    // The text sent to the AI was read here; no image was sent.
    expect($this->ai->requests[0]->userMessage)->toContain('[Page 1]');
});

it('answers questions only from the document, with quotes that are really there', function (): void {
    $this->ai->respond(factsJson());
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Everything.pdf', combinedFile())]);
    $contract = Document::query()->where('filename', 'like', 'Purchase agreement%')->sole();

    $this->ai->respond(json_encode(['found' => true, 'answer' => 'Closing is set for 15 July 2026.', 'citations' => [['page' => 1, 'quote' => 'Closing Date: 07/15/2026']]]));
    $this->actingAs($this->agent)->post("/deals/documents/{$contract->ulid}/ask", ['question' => 'When is closing?'])->assertSessionHas('docAnswer', [
        'document' => $contract->ulid, 'question' => 'When is closing?', 'found' => true, 'answer' => 'Closing is set for 15 July 2026.',
        'citations' => [['page' => 1, 'quote' => 'Closing Date: 07/15/2026']]]);

    $this->ai->respond(json_encode(['found' => true, 'answer' => 'The seller pays all closing costs.', 'citations' => [['page' => 1, 'quote' => 'Seller pays all closing costs']]]));
    $this->actingAs($this->agent)->post("/deals/documents/{$contract->ulid}/ask", ['question' => 'Who pays closing costs?'])
        ->assertSessionHas('docAnswer', fn (array $a): bool => $a['found'] === false && $a['answer'] === 'I could not find that in this document.' && $a['citations'] === []);
    $this->actingAs(User::factory()->create())->post("/deals/documents/{$contract->ulid}/ask", ['question' => 'When is closing?'])->assertForbidden();
});

it('recognises documents and splits pages predictably', function (): void {
    expect(DocTypes::classify("COUNTER OFFER #2\nto the purchase agreement"))->toBe('counter_offer')
        ->and(DocTypes::classify('Third Amendment to Purchase Agreement'))->toBe('amendment')
        ->and(DocTypes::classify('ALTA Settlement Statement - Buyer'))->toBe('settlement_statement')
        ->and(DocTypes::classify('Just some notes'))->toBeNull()
        ->and(DocTypes::segment(['notes', 'PURCHASE AGREEMENT', 'more', 'PURCHASE AGREEMENT Page 1 of 1']))->toBe([
            ['type' => 'purchase_agreement', 'from' => 1, 'to' => 3], ['type' => 'purchase_agreement', 'from' => 4, 'to' => 4]]);
});

it('adds required documents only when the file needs them', function (): void {
    expect(Requirements::find(['Year built: 1972'], []))->toBe([['label' => 'Lead-based paint disclosure', 'page' => 1, 'because' => 'says the home was built in 1972']])
        ->and(Requirements::find(['Year built: 1992'], []))->toBe([])
        ->and(Requirements::find(['The home was not built before 1978.'], []))->toBe([])
        ->and(Requirements::find(['', 'Served by a private well and a septic system.'], []))->toBe([['label' => 'Well and septic inspection', 'page' => 2, 'because' => 'mentions a well or septic system']])
        ->and(Requirements::find(['Homeowners association dues apply.'], ['HOA certificate']))->toBe([]);
    expect(Sensitive::wordsToHide(['SSN', '123-45-6789', 'Account', 'number', '12345678', 'Phone', '5125550123', 'DOB', '01/02/1980', 'Closing', '07/15/2026']))->toBe([1, 4, 8]);
});

it('sends the deal page what DocIQ found', function (): void {
    $this->ai->respond(factsJson());
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Everything.pdf', combinedFile())]);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('notes.pdf', docPdf([['Some notes about the deal for the file']]))]);
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $p) => $p->component('deals/show')
        ->where('unsorted', fn ($u): bool => collect($u)->pluck('filename')->sort()->values()->all() === ['Everything.pdf', 'notes.pdf'])
        ->where('items', fn ($items): bool => collect($items)->firstWhere('label', 'HOA documents')['added_reason'] !== null
            && collect($items)->firstWhere('label', 'Purchase contract')['documents'][0]['reading']['type'] === 'Purchase agreement')
        ->has('facts', 4));
    assertPageContract($this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}"), 'deals/show');
});
