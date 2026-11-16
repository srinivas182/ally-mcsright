<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Transactions\DealIq\UploadRequestMail;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Review\FileReview;
use Modules\Transactions\Review\FileReviewMail;
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
    foreach ([DocIq::READ => 'ai.doc_read', DocIq::REFERENCED => 'ai.doc_referenced', FileReview::FEATURE => 'ai.review_brief'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
});

function reviewPdf(array $pages): string
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

/** @return list<array{code: string, message: string, fix: array<string, mixed>|null}> */
function reviewFindings(Deal $deal, string $code): array
{
    return DB::table('tx_findings')->where('deal_id', $deal->id)->where('code', $code)->where('status', 'open')->get()
        ->map(fn ($f): array => ['code' => (string) $f->code, 'message' => (string) $f->message, 'fix' => json_decode((string) $f->fix, true)])->all();
}

it('flags empty signature and initial lines page by page, skips e-signed pages, and asks the right person to sign', function (): void {
    $pdf = reviewPdf([
        ['RESIDENTIAL PURCHASE AGREEMENT', 'Sales Price: $480,000', "Buyer's Signature ______________   Date ______", "Seller's Signature ______________"],
        ['Page 2', 'DocuSign Envelope ID: 1234-ABCD', 'Buyer Initials ______  Seller Initials ______'],
    ]);
    $this->ai->respond(json_encode(['facts' => []]))->respond(json_encode(['addenda' => []]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Contract.pdf', $pdf)])->assertSessionHasNoErrors();
    $found = reviewFindings($this->deal, 'docs.signatures');
    expect(array_column($found, 'message'))->toEqualCanonicalizing(["Page 1 of Contract.pdf: the buyer's signature line looks empty.", "Page 1 of Contract.pdf: the seller's signature line looks empty."]);
    $found = array_values(array_filter($found, fn (array $f): bool => $f['fix']['role'] === 'buyer'));
    expect($found[0]['fix'])->toMatchArray(['action' => 'ask_sign', 'role' => 'buyer', 'page' => 1]);

    $doc = (string) $found[0]['fix']['document'];
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/ask-sign", ['role' => 'buyer', 'document' => $doc, 'page' => 1])->assertSessionHasErrors('role');
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'buyer', 'name' => 'Ana Garcia', 'email' => 'ana@example.test', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/ask-sign", ['role' => 'buyer', 'document' => $doc, 'page' => 1])->assertSessionHasNoErrors();
    Notification::assertSentOnDemand(UploadRequestMail::class, fn (UploadRequestMail $m): bool => $m->what === 'The signed Contract.pdf (signature or initials on page 1)');
});

it('finds addenda the contract marks as attached that are not in the file, and only checked ones', function (): void {
    $pdf = reviewPdf([['RESIDENTIAL PURCHASE AGREEMENT', 'Sales Price: $480,000', '22. ADDENDA: The following addenda are part of this contract:',
        '[X] Third Party Financing Addendum', '[ ] Seller Financing Addendum']]);
    $this->ai->respond(json_encode(['facts' => []]))->respond(json_encode(['addenda' => [
        ['name' => 'Third Party Financing Addendum', 'page' => 1, 'quote' => '[X] Third Party Financing Addendum'],
        ['name' => 'Seller Financing Addendum', 'page' => 1, 'quote' => '[ ] Seller Financing Addendum'], // not checked
        ['name' => 'Survey', 'page' => 1, 'quote' => '[X] Survey'], // not on the page
    ]]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Contract.pdf', $pdf)]);
    expect(array_column(reviewFindings($this->deal, 'docs.referenced_missing'), 'message'))->toBe(['Contract.pdf marks “Third Party Financing Addendum” as attached, but it is not in the file.']);

    $this->ai->respond(json_encode(['facts' => []]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Third Party Financing Addendum.pdf', reviewPdf([['THIRD PARTY FINANCING ADDENDUM', 'Buyer shall apply promptly']]))]);
    expect(reviewFindings($this->deal, 'docs.referenced_missing'))->toBe([]);
});

it('flags earnest money that differs from the accepted offer', function (): void {
    $this->deal->forceFill(['terms' => json_encode(['earnest_cents' => 500_000])])->save();
    $this->ai->respond(json_encode(['facts' => [['name' => 'earnest_money', 'value' => '$7,500', 'page' => 1, 'quote' => 'Earnest money: $7,500']]]))->respond(json_encode(['addenda' => []]));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('Contract.pdf',
        reviewPdf([['RESIDENTIAL PURCHASE AGREEMENT', 'Earnest money: $7,500']]))]);
    expect(array_column(reviewFindings($this->deal, 'docs.mismatch'), 'message'))->toContain('Contract.pdf gives earnest money of $7,500; the accepted offer said $5,000.');
});

it('gives the broker a review brief from the file, then approve or send back with a note', function (): void {
    DB::table('tx_deals')->where('id', $this->deal->id)->update(['file_submitted_at' => now(), 'file_submitted_by' => $this->agent->id]);
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}/file-review")->assertForbidden();

    // A brief with an amount that is not in the file is replaced by one written from the facts.
    $this->ai->respond(json_encode(['summary' => 'The buyer is paying $999,999.', 'note' => 'Fix it.']));
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/brief")->assertRedirect();
    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}/file-review")->assertInertia(fn (Assert $page) => $page->component('deals/file-review')
        ->where('brief.ai', false)->where('brief.summary', fn ($s): bool => str_contains((string) $s, '12 Oak St') && ! str_contains((string) $s, '999,999')));
    $this->ai->respond(json_encode(['summary' => 'Buyer deal at $480,000; nothing waived.', 'note' => 'Please upload the signed buyer agreement.']));
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/brief");
    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}/file-review")->assertInertia(fn (Assert $page) => $page->where('brief.ai', true)->where('brief.note', 'Please upload the signed buyer agreement.'));

    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/send-back", ['note' => 'Wire to routing 021000021 account 12345678'])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/send-back", ['note' => 'Please upload the signed buyer agreement.'])->assertRedirect();
    Notification::assertSentTo($this->agent, FileReviewMail::class, fn (FileReviewMail $m): bool => ! $m->approved && $m->note === 'Please upload the signed buyer agreement.');
    expect(DB::table('tx_deals')->where('id', $this->deal->id)->value('file_submitted_at'))->toBeNull();
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/approve")->assertSessionHasErrors('file');

    DB::table('tx_deals')->where('id', $this->deal->id)->update(['file_submitted_at' => now()]);
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/file-review/approve")->assertRedirect();
    Notification::assertSentTo($this->agent, FileReviewMail::class, fn (FileReviewMail $m): bool => $m->approved);
    expect(DB::table('tx_deals')->where('id', $this->deal->id)->value('file_approved_at'))->not->toBeNull();
});
