<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Compliance\Evidence;
use Modules\Transactions\Compliance\Findings;
use Modules\Transactions\Compliance\Rules;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Reading\DocIq;
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
    foreach ([DocIq::READ => 'ai.doc_read', DocIq::ASK => 'ai.doc_ask', Rules::FEATURE => 'ai.compliance_rule'] as $feature => $credit) {
        AiFeatureRoute::query()->create(['feature_key' => $feature, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $credit]);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
});

function credential(User $user, string $kind, string $expires, ?string $state = 'TX'): void
{
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => test()->tenant->id, 'user_id' => $user->id, 'kind' => $kind,
        'state_code' => $kind === 'licence' ? $state : null, 'expires_on' => $expires, 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
}

function scanPdf(array $pages): string
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

function open(Deal $deal): array
{
    return DB::table('tx_findings')->where('deal_id', $deal->id)->where('status', 'open')->orderBy('id')->pluck('severity', 'message')->all();
}

it('checks the checklist for the deal stage and the agent\'s licence and E&O, and scores the file', function (): void {
    $r = app(ComplianceScan::class)->run($this->deal);
    expect(open($this->deal))->toBe([
        'Missing: Listing agreement.' => 'blocker',
        'Missing: Seller property disclosure.' => 'blocker',
        'Pat Lee has no TX licence on the roster.' => 'blocker',
        'Pat Lee has no E&O cover on the roster.' => 'warning',
    ])->and($r)->toBe(['score' => 35, 'blockers' => 3, 'warnings' => 1]);

    credential($this->agent, 'licence', '2027-03-31');
    credential($this->agent, 'eo', '2027-01-01');
    expect(app(ComplianceScan::class)->run($this->deal)['score'])->toBe(60);

    // The contract date falls after the licence expiry: blocker.
    DB::table('roster_credentials')->where('kind', 'licence')->update(['expires_on' => '2026-05-15']);
    $this->deal->update(['contract_date' => '2026-05-20']);
    app(ComplianceScan::class)->run($this->deal);
    expect(array_keys(open($this->deal)))->toContain("Pat Lee's TX licence expired on 05/15/2026, before 05/20/2026.");

    // Fixed problems resolve themselves on the next scan.
    DB::table('roster_credentials')->where('kind', 'licence')->update(['expires_on' => '2027-03-31']);
    $item = Item::query()->where('deal_id', $this->deal->id)->where('label', 'Listing agreement')->sole();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['item' => $item->ulid, 'file' => UploadedFile::fake()->createWithContent('la.docx', 'x', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]);
    expect(open($this->deal))->toBe(['Missing: Seller property disclosure.' => 'blocker'])
        ->and(DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('status', 'resolved')->count())->toBe(3);
});

it('finds mismatches, blanks, wrong years, missing e-signature records and bank details, each with evidence', function (): void {
    credential($this->agent, 'licence', '2027-03-31');
    credential($this->agent, 'eo', '2027-01-01');
    $this->ai->respond(json_encode(['facts' => [
        ['name' => 'price', 'value' => '$485,000.00', 'page' => 1, 'quote' => 'Sales Price: $485,000.00'],
        ['name' => 'contract_date', 'value' => '05/20/2016', 'page' => 1, 'quote' => 'Effective Date: 05/20/2016'],
        ['name' => 'seller_names', 'value' => 'Robert King', 'page' => 1, 'quote' => 'Seller: Robert King'],
    ]]));
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'seller', 'name' => 'Maria Gomez', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/documents", ['file' => UploadedFile::fake()->createWithContent('contract.pdf', scanPdf([
        ['RESIDENTIAL PURCHASE AGREEMENT', 'Seller: Robert King', 'Sales Price: $485,000.00', 'Effective Date: 05/20/2016', 'Closing Date: __________',
            'Refund account number 12345678 routing 021000021'],
    ]))]);
    $doc = Document::query()->where('filename', 'contract.pdf')->sole();
    $findings = DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('status', 'open')->get()->keyBy('code');

    $price = DB::table('tx_findings')->where('message', 'like', 'contract.pdf says the price is%')->sole();
    expect($price->message)->toBe('contract.pdf says the price is $485,000; the deal says $480,000.')
        ->and(json_decode((string) $price->evidence, true))->toBe(['document' => $doc->ulid, 'filename' => 'contract.pdf', 'page' => 1, 'quote' => 'Sales Price: $485,000.00'])
        ->and(json_decode((string) $price->fix, true)['action'])->toBe('apply_fact');
    expect(DB::table('tx_findings')->where('message', 'like', '%Robert King%does not include Maria Gomez%')->exists())->toBeTrue()
        ->and($findings['docs.blank']->message)->toBe('Looks blank in contract.pdf: “Closing Date: __________”')
        ->and($findings['docs.wrong_year']->message)->toContain('05/20/2016')
        ->and($findings['docs.no_signature_record']->severity)->toBe('info')
        ->and($findings['docs.wire']->severity)->toBe('warning')
        ->and($findings['docs.sensitive']->severity)->toBe('info');

    // Evidence: the cited page with the quote boxed.
    $this->actingAs($this->agent)->get("/deals/documents/{$doc->ulid}/evidence?page=1&quote=".urlencode('Sales Price: $485,000.00'))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(Evidence::find([['Sales', [10, 10, 20, 8]], ['Price:', [32, 10, 25, 8]], ['$485,000.00', [60, 10, 50, 8]]], 'Sales Price: $485,000.00'))->toBe([10, 10, 100, 8])
        ->and(Evidence::find([['Other', [0, 0, 1, 1]]], 'Sales Price'))->toBeNull();

    // One-click fix: use the contract's price.
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/facts", ['facts' => ['price']]);
    expect(DB::table('tx_findings')->where('id', $price->id)->value('status'))->toBe('resolved');
});

it('needs a buyer agreement on buyer deals under contract, and flags old form editions and ownership overrides', function (): void {
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '9 Elm St', 'state_code' => 'TX', 'price' => '300000']);
    $buyer = Deal::query()->latest('id')->firstOrFail();
    $buyer->update(['status' => 'under_contract']);
    app(ComplianceScan::class)->run($buyer);
    expect(DB::table('tx_findings')->where('deal_id', $buyer->id)->where('code', 'docs.buyer_agreement')->value('severity'))->toBe('blocker');

    $db = DB::table('form_templates');
    $new = $db->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'name' => 'Listing Agreement', 'path' => 'x', 'sha256' => str_repeat('a', 64), 'pages' => 1,
        'page_sizes' => '[]', 'fields' => '[]', 'edition' => '02-2026', 'created_by' => $this->owner->id, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => now()]);
    $old = DB::table('form_templates')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'name' => 'Listing Agreement', 'path' => 'x', 'sha256' => str_repeat('b', 64),
        'pages' => 1, 'page_sizes' => '[]', 'fields' => '[]', 'edition' => '11-2025', 'superseded_by' => $new, 'active' => false, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $packet = DB::table('form_packets')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'deal_id' => $this->deal->id, 'title' => 'Listing paperwork',
        'status' => 'completed', 'created_by' => $this->agent->id, 'sent_at' => '2026-05-10 00:00:00', 'owner_override_reason' => 'Trustee of the Gomez trust', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('form_packet_forms')->insert(['tenant_id' => $this->tenant->id, 'packet_id' => $packet, 'template_id' => $old, 'template_version' => 1, 'values' => '{}', 'sort' => 0]);
    app(ComplianceScan::class)->run($this->deal);
    expect(DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('code', 'forms.old_edition')->value('message'))
        ->toBe('“Listing paperwork” was sent on an older edition of Listing Agreement (edition 02-2026 was already out).')
        ->and(DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('code', 'forms.owner_override')->value('message'))->toContain('Trustee of the Gomez trust');
});

it('lets reviewers dismiss with a reason, keeps dismissals, tracks accuracy and submits a clean file', function (): void {
    app(ComplianceScan::class)->run($this->deal);
    $licence = DB::table('tx_findings')->where('code', 'agent.licence')->sole();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/findings/{$licence->id}/dismiss", ['reason' => 'I am licensed'])->assertSessionHasErrors('finding');
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/compliance/findings/{$licence->id}/dismiss", ['reason' => 'no'])->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/compliance/findings/{$licence->id}/dismiss", ['reason' => 'Licence verified on TREC today'])->assertSessionHasNoErrors();
    app(ComplianceScan::class)->run($this->deal);
    expect(DB::table('tx_findings')->where('id', $licence->id)->value('status'))->toBe('dismissed');

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/submit")->assertSessionHasErrors('file');
    credential($this->agent, 'eo', '2027-01-01');
    foreach (Item::query()->where('deal_id', $this->deal->id)->where('status', 'missing')->get() as $item) {
        $this->actingAs($this->owner)->post("/deals/items/{$item->ulid}/review", ['action' => 'waive', 'reason' => 'Not needed for this test']);
    }
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/submit")->assertSessionHasNoErrors();
    expect(DB::table('tx_deals')->where('id', $this->deal->id)->value('file_submitted_at'))->not->toBeNull()
        ->and(DB::table('tx_deals')->where('id', $this->deal->id)->value('compliance_score'))->toBe(100);

    // Accuracy: a check dismissed often and rarely right is flagged for review.
    foreach (range(1, 5) as $n) {
        DB::table('tx_findings')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $this->deal->id, 'code' => 'docs.blank', 'severity' => 'warning', 'fingerprint' => sha1("x{$n}"),
            'message' => 'x', 'status' => 'dismissed', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }
    expect(Findings::accuracy()['docs.blank'])->toBe(['fixed' => 0, 'dismissed' => 5, 'open' => 0, 'accuracy' => 0, 'review' => true])
        ->and(Findings::accuracy()['agent.eo']['accuracy'])->toBe(100);
});

it('turns a plain-English rule into a check only after the broker confirms how it was read', function (): void {
    credential($this->agent, 'licence', '2027-03-31');
    credential($this->agent, 'eo', '2027-01-01');
    $this->ai->respond(json_encode(['applies_to' => ['deal_types' => ['listing'], 'min_price' => 400000, 'max_price' => null, 'states' => [], 'text_contains' => []],
        'requires' => ['kind' => 'broker_approval', 'document' => null], 'severity' => 'blocker', 'summary' => 'Big listings need approval']));
    $this->actingAs($this->agent)->post('/deals/compliance/rules', ['text' => 'Listings over $400,000 need broker approval'])->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/compliance/rules', ['text' => 'Listings over $400,000 need broker approval'])->assertSessionHasNoErrors();
    $rule = DB::table('tx_compliance_rules')->sole();
    expect($rule->summary)->toBe('Listing deals over $400,000 need broker approval (blocks the file).')->and((bool) $rule->active)->toBeFalse();
    app(ComplianceScan::class)->run($this->deal);
    expect(DB::table('tx_findings')->where('code', 'rule')->exists())->toBeFalse();

    $this->actingAs($this->owner)->post("/deals/compliance/rules/{$rule->ulid}/confirm")->assertSessionHasNoErrors();
    $finding = DB::table('tx_findings')->where('code', 'rule')->sole();
    expect($finding->message)->toBe('Brokerage rule: Listing deals over $400,000 need broker approval (blocks the file).')->and($finding->severity)->toBe('blocker');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/compliance/rules/{$rule->ulid}/approve")->assertForbidden();
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/compliance/rules/{$rule->ulid}/approve")->assertSessionHasNoErrors();
    expect(DB::table('tx_findings')->where('id', $finding->id)->value('status'))->toBe('resolved');

    // A document rule that applies only when the documents mention a pool.
    $this->ai->respond(json_encode(['applies_to' => ['deal_types' => [], 'min_price' => null, 'max_price' => null, 'states' => [], 'text_contains' => ['pool']],
        'requires' => ['kind' => 'document', 'document' => 'Pool safety addendum'], 'severity' => 'warning', 'summary' => 'x']));
    app(Rules::class)->draft('Any home with a pool needs our pool safety addendum.', $this->owner);
    $pool = DB::table('tx_compliance_rules')->where('text', 'like', '%pool%')->sole();
    expect($pool->summary)->toBe('Every deal whose documents mention “pool” need Pool safety addendum (warning).');
    app(Rules::class)->confirm($pool->ulid, $this->owner);
    app(ComplianceScan::class)->run($this->deal);
    expect(DB::table('tx_findings')->where('message', 'like', '%Pool safety%')->exists())->toBeFalse();

    $this->ai->respond(json_encode(['applies_to' => [], 'requires' => ['kind' => 'unsupported', 'document' => null], 'severity' => 'warning', 'summary' => 'Needs a judgement call']));
    expect(fn () => app(Rules::class)->draft('Agents must be nice to clients at all times.', $this->owner))->toThrow(ValidationException::class, 'cannot check that rule');
});

it('lets the broker turn checks off or change their severity', function (): void {
    $this->actingAs($this->agent)->post('/deals/compliance/checks/agent.eo', ['enabled' => false])->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/compliance/checks/agent.eo', ['enabled' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/deals/compliance/checks/agent.licence', ['enabled' => true, 'severity' => 'info'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/deals/compliance/checks/agent.licence', ['enabled' => true, 'severity' => 'fatal'])->assertSessionHasErrors('check');
    app(ComplianceScan::class)->run($this->deal);
    expect(open($this->deal))->not->toHaveKey('Pat Lee has no E&O cover on the roster.')->and(open($this->deal)['Pat Lee has no TX licence on the roster.'])->toBe('info');
});

it('sends the deal and compliance pages what they need', function (): void {
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $p) => $p->where('compliance.score', 35)->has('compliance.open', 4));
    assertPageContract($this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}"), 'deals/show');
    assertPageContract($this->actingAs($this->owner)->get('/deals/compliance'), 'deals/compliance');
    $this->actingAs($this->agent)->get('/deals/compliance')->assertForbidden();
});
