<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Forms\Packets;
use Modules\Forms\Templates;
use Modules\Transactions\Models\Deal;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Contracts/contract-helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->other = User::factory()->create();
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->templates = app(Templates::class);
    $this->packets = app(Packets::class);
});

function blankPdfContents(): string
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Text(72, 60, 'RESIDENTIAL LISTING AGREEMENT');

    return $pdf->Output('S');
}

function blankPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('listing.pdf', blankPdfContents());
}

/** @return list<array<string, mixed>> */
function listingFields(): array
{
    return [
        ['key' => 'address', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 100, 'w' => 400, 'h' => 14, 'bind' => 'deal.address', 'required' => true, 'label' => 'Property address'],
        ['key' => 'price', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 130, 'w' => 200, 'h' => 14, 'bind' => 'deal.price', 'required' => true, 'label' => 'List price'],
        ['key' => 'seller', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 160, 'w' => 300, 'h' => 14, 'bind' => 'seller.names', 'required' => true, 'label' => 'Seller'],
        ['key' => 'term_days', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 190, 'w' => 100, 'h' => 14, 'required' => true, 'label' => 'Term (days)'],
        ['key' => 'special', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 220, 'w' => 450, 'h' => 14, 'label' => 'Special provisions'],
        ['key' => 'seller_sig', 'type' => 'signature', 'page' => 1, 'x' => 72, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'seller1', 'label' => 'Seller signature'],
        ['key' => 'agent_sig', 'type' => 'signature', 'page' => 1, 'x' => 320, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'listing_agent', 'label' => 'Agent signature'],
    ];
}

function formsDeal(): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '485000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    DB::table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => 'seller', 'name' => 'Maria Gomez', 'email' => 'maria@example.test', 'created_at' => now(), 'updated_at' => now()]);

    return $deal;
}

it('uploads a PDF form, reads its pages, and only lets the brokerage manage forms', function (): void {
    expect(fn () => $this->templates->upload('Listing', UploadedFile::fake()->createWithContent('x.pdf', 'not a pdf'), null, $this->owner))->toThrow(ValidationException::class, 'not a PDF')
        ->and(fn () => $this->templates->upload('Listing', blankPdf(), null, $this->agent))->toThrow(ValidationException::class);

    $this->actingAs($this->owner)->post('/forms/templates', ['name' => 'Listing agreement', 'checklist_label' => 'Listing agreement', 'file' => blankPdf()])->assertSessionHasNoErrors();
    $t = $this->templates->find((string) DB::table('form_templates')->value('ulid'));
    expect($t)->toMatchArray(['pages' => 1, 'version' => 1, 'checklist_label' => 'Listing agreement'])->and($t['page_sizes'][0])->toBe([612, 792]);
});

it('checks every field and saves a new version', function (): void {
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    $bad = fn (array $f) => fn () => $this->templates->saveFields($ulid, [$f + ['key' => 'k', 'type' => 'text', 'page' => 1, 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 10]], $this->owner);
    expect($bad(['key' => 'Bad Key']))->toThrow(ValidationException::class, 'lowercase')
        ->and($bad(['x' => 600]))->toThrow(ValidationException::class, 'outside the page')
        ->and($bad(['page' => 2]))->toThrow(ValidationException::class, 'page the form does not have')
        ->and($bad(['type' => 'signature']))->toThrow(ValidationException::class, 'signer');

    $this->actingAs($this->owner)->post("/forms/templates/{$ulid}/fields", ['fields' => listingFields()])->assertSessionHasNoErrors();
    expect($this->templates->find($ulid))->toMatchArray(['version' => 2])->and($this->templates->find($ulid)['fields'])->toHaveCount(7);
});

it('fills forms from the deal, suggests signers, and checks everything before sending', function (): void {
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    $this->templates->saveFields($ulid, listingFields(), $this->owner);
    $deal = formsDeal();

    $this->actingAs($this->agent)->post("/forms/deals/{$deal->ulid}/packets", ['templates' => [$ulid]])->assertRedirectContains('/forms/packets/');
    $packet = (string) DB::table('form_packets')->value('ulid');
    $form = DB::table('form_packet_forms')->first();
    expect(json_decode((string) $form->values, true))->toEqual(['address' => '12 Oak St, Austin, TX', 'price' => '$485,000.00', 'seller' => 'Maria Gomez'])
        ->and(DB::table('form_signers')->orderBy('sign_order')->pluck('role')->all())->toBe(['seller1', 'listing_agent']);

    expect($this->packets->check($packet, $this->agent))->toBe(['Listing: Term (days) is required but empty.']);
    $this->packets->setValues($packet, (int) $form->id, ['term_days' => '180', 'seller_sig' => 'forged'], $this->agent);
    $this->packets->setSigners($packet, [['role' => 'listing_agent', 'name' => 'Pat Lee', 'email' => 'pat@example.test', 'sign_order' => 1]], $this->agent);
    expect($this->packets->check($packet, $this->agent))->toBe(['Listing: Seller signature is for "seller1", but nobody with that role is signing.'])
        ->and(json_decode((string) DB::table('form_packet_forms')->value('values'), true))->not->toHaveKey('seller_sig');

    $this->packets->setSigners($packet, [['role' => 'seller1', 'name' => 'Maria Gomez', 'email' => 'maria@example.test', 'sign_order' => 1],
        ['role' => 'listing_agent', 'name' => 'Pat Lee', 'email' => 'pat@example.test', 'sign_order' => 2]], $this->agent);
    expect($this->packets->check($packet, $this->agent))->toBe([]);
});

it('never lets wiring instructions into a form or a clause', function (): void {
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    $this->templates->saveFields($ulid, listingFields(), $this->owner);
    $packet = $this->packets->create(formsDeal(), '', [$ulid], $this->agent);
    $form = (int) DB::table('form_packet_forms')->value('id');

    expect(fn () => $this->packets->setValues($packet, $form, ['special' => 'Wire to routing 021000021 account number 123456789'], $this->agent))
        ->toThrow(ValidationException::class, 'never carry wiring instructions')
        ->and(fn () => $this->templates->addClause('Wiring', 'Buyer shall wire funds: ABA 021000021, account # 55500001111', $this->owner))->toThrow(ValidationException::class);
    $this->packets->setValues($packet, $form, ['special' => 'Seller to leave the refrigerator; 30-day leaseback'], $this->agent);
    $this->templates->addClause('Leaseback', 'Seller may remain in possession for up to 30 days after closing.', $this->owner);
    expect(DB::table('form_clauses')->count())->toBe(1);
});

it('previews the filled PDF with the values on the page', function (): void {
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    $this->templates->saveFields($ulid, listingFields(), $this->owner);
    $packet = $this->packets->create(formsDeal(), '', [$ulid], $this->agent);
    $form = (int) DB::table('form_packet_forms')->value('id');

    $pdf = (string) $this->actingAs($this->agent)->get("/forms/packets/{$packet}/forms/{$form}/preview")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    $file = tempnam(sys_get_temp_dir(), 'pv');
    file_put_contents($file, $pdf);
    $process = new Process(['pdftotext', '-layout', $file, '-']);
    $process->run();
    expect($process->getOutput())->toContain('RESIDENTIAL LISTING AGREEMENT')->toContain('12 Oak St, Austin, TX')->toContain('$485,000.00')->toContain('Maria Gomez');
    $this->actingAs($this->other)->get("/forms/packets/{$packet}/forms/{$form}/preview")->assertSessionHasErrors('packet');
});

it('sends the forms pages the data they need, and keeps a deal\'s forms to its agent and the brokerage', function (): void {
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    $this->templates->saveFields($ulid, listingFields(), $this->owner);
    $deal = formsDeal();
    $packet = $this->packets->create($deal, '', [$ulid], $this->agent);

    assertPageContract($this->actingAs($this->owner)->get('/forms/templates'), 'forms/templates');
    assertPageContract($this->actingAs($this->agent)->get("/forms/deals/{$deal->ulid}"), 'forms/deal');
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$packet}"), 'forms/packet');
    $this->actingAs($this->other)->get("/forms/deals/{$deal->ulid}")->assertForbidden();
    $this->actingAs($this->agent)->get('/forms/templates')->assertForbidden();
});

it('still reads ordinary PDFs when qpdf is unavailable, and explains when a PDF truly cannot be read', function (): void {
    config(['forms.qpdf' => '/nonexistent/qpdf']);
    $ulid = $this->templates->upload('Listing', blankPdf(), null, $this->owner);
    expect($this->templates->find($ulid)['page_sizes'][0])->toBe([612, 792])
        ->and(fn () => $this->templates->upload('Broken', UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.7\nnot really a pdf"), null, $this->owner))
        ->toThrow(ValidationException::class, 'could not be read');
});

it('imports compressed PDFs (object streams, like most association forms), and qpdf repairs stay importable', function (): void {
    $plain = tempnam(sys_get_temp_dir(), 'plain');
    $packed = $plain.'.packed.pdf';
    file_put_contents($plain, blankPdfContents());
    $make = new Process(['qpdf', '--object-streams=generate', '--compress-streams=y', $plain, $packed]);
    $make->run();
    if (! is_file($packed)) {
        $this->markTestSkipped('qpdf is not installed here.');
    }
    $compressed = (string) file_get_contents($packed);
    expect($compressed)->toContain('/ObjStm');

    $ulid = $this->templates->upload('Association form', UploadedFile::fake()->createWithContent('assoc.pdf', $compressed), null, $this->owner);
    expect($this->templates->find($ulid)['page_sizes'][0])->toBe([612, 792]);

    // qpdf's repair output (used for PDFs FPDI cannot read as uploaded) must itself be importable,
    // whatever qpdf version the server has.
    $repaired = $plain.'.repaired.pdf';
    (new Process(['qpdf', '--object-streams=disable', '--decrypt', $packed, $repaired]))->run();
    expect((new Fpdi('P', 'pt'))->setSourceFile($repaired))->toBe(1);
});
