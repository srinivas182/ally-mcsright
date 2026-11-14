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
use Modules\Compensation\Models\Calculation;
use Modules\Payouts\AutoPayouts;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\PayoutHeld;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Transactions\Cdas;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\EarnestMoney;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Reading\Sensitive;
use Modules\Transactions\TitleDesk;
use Tests\Fixtures\FakeAiProvider;

require_once __DIR__.'/../Contracts/contract-helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => DocIq::READ, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.doc_read']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '7 Birch Ln', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-04']);
    $this->deal = $this->deal->fresh();
});

function moneyPdf(array $lines): string
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 11);
    foreach ($lines as $n => $line) {
        $pdf->Text(60, 70 + $n * 20, $line);
    }

    return $pdf->Output('S');
}

function uploadDoc(string $name, array $lines, array $facts = []): void
{
    test()->ai->respond(json_encode(['facts' => $facts]));
    test()->actingAs(test()->ann)->post('/deals/'.test()->deal->ulid.'/documents', ['file' => UploadedFile::fake()->createWithContent($name, moneyPdf($lines))])->assertSessionHasNoErrors();
}

function codes(Deal $deal): array
{
    return DB::table('tx_findings')->where('deal_id', $deal->id)->where('status', 'open')->where(fn ($q) => $q->where('code', 'like', 'money.%')->orWhere('code', 'like', 'wire.%'))
        ->orderBy('id')->pluck('severity', 'code')->all();
}

function holdReason(Deal $deal): ?string
{
    return DB::table('tx_deals')->where('id', $deal->id)->value('payout_hold_reason');
}

it('holds payouts when the CDA no longer matches the deal, until a new CDA is made', function (): void {
    app(Cdas::class)->generate($this->deal, $this->owner);
    app(ComplianceScan::class)->run($this->deal);
    expect(codes($this->deal))->toBe([]);

    $this->deal->update(['price_cents' => 49_000_000]);
    app(ComplianceScan::class)->run($this->deal);
    expect(codes($this->deal))->toBe(['money.cda_stale' => 'blocker'])->and(holdReason($this->deal))->toContain('Make a new CDA');

    app(Cdas::class)->generate($this->deal->fresh(), $this->owner);
    app(ComplianceScan::class)->run($this->deal);
    expect(codes($this->deal))->toBe([])->and(holdReason($this->deal))->toBeNull();
});

it('checks the settlement statement against the CDA and the title company, holds the automatic payout, and releases it when resolved', function (): void {
    app(Cdas::class)->generate($this->deal, $this->owner);
    app(AutoPayouts::class)->configure(true, 1_500_000, false, $this->owner);
    uploadDoc('settlement.pdf', ['ALTA SETTLEMENT STATEMENT', 'Settlement agent: Fly By Night Title', 'Listing broker commission: $14,000.00'], [
        ['name' => 'commission_listing', 'value' => '$14,000.00', 'page' => 1, 'quote' => 'Listing broker commission: $14,000.00'],
        ['name' => 'title_company', 'value' => 'Fly By Night Title', 'page' => 1, 'quote' => 'Settlement agent: Fly By Night Title'],
    ]);
    expect(codes($this->deal))->toBe(['money.commission' => 'blocker', 'wire.title_unverified' => 'warning'])
        ->and(DB::table('tx_findings')->where('code', 'money.commission')->value('message'))->toBe('settlement.pdf pays $14,000 commission; the CDA says $15,000.');

    // The money arrives and matches the CDA: the automatic payout waits on the hold.
    $receipts = app(Receipts::class);
    $receipts->record($this->deal, $receipts->expected($this->deal, Calculation::query()->findOrFail($this->deal->calculation_id)), CarbonImmutable::today(), 'wire', null, $this->owner);
    expect(DB::table('pay_runs')->count())->toBe(0)->and((int) DB::table('tx_deals')->where('id', $this->deal->id)->value('payout_waiting_by'))->toBe($this->owner->id);
    Notification::assertSentTo($this->owner, PayoutHeld::class, fn (PayoutHeld $n): bool => str_contains($n->reason, '$14,000'));

    // The broker confirms the statement is a draft and dismisses the finding: the payout goes ahead by itself.
    $finding = DB::table('tx_findings')->where('code', 'money.commission')->sole();
    $this->actingAs($this->owner)->post("/deals/{$this->deal->ulid}/compliance/findings/{$finding->id}/dismiss", ['reason' => 'Draft statement; final one shows $15,000'])->assertSessionHasNoErrors();
    expect(holdReason($this->deal))->toBeNull()->and(DB::table('pay_runs')->value('status'))->toBe('approved')
        ->and(DB::table('tx_deals')->where('id', $this->deal->id)->value('payout_waiting_by'))->toBeNull();

    // A verified title company clears the warning.
    $desk = app(TitleDesk::class);
    $ulid = $desk->addCompany('Fly By Night Title, LLC', 'close@fbn.test', '5125550100');
    $desk->verifyCompany($ulid, 'Called the number on their website', $this->owner);
    app(ComplianceScan::class)->run($this->deal);
    expect(codes($this->deal))->toBe([]);
});

it('flags wiring instructions that change between documents and makes a manual payout run need a written override', function (): void {
    uploadDoc('wire-1.pdf', ['WIRE INSTRUCTIONS', 'Beneficiary: Lone Star Title', 'Routing number 021000021', 'Account number 11112222']);
    uploadDoc('wire-2.pdf', ['UPDATED WIRE INSTRUCTIONS', 'Beneficiary: Lone Star Title', 'Routing number 021000021', 'Account number 99998888']);
    expect(codes($this->deal))->toBe(['wire.changed' => 'blocker'])
        ->and(holdReason($this->deal))->toContain('account ending 2222 in wire-1.pdf; account ending 8888 in wire-2.pdf');

    $receipts = app(Receipts::class);
    $receipts->record($this->deal, $receipts->expected($this->deal, Calculation::query()->findOrFail($this->deal->calculation_id)), CarbonImmutable::today(), 'wire', null, $this->owner);
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $this->actingAs($this->owner)->get('/finance/payouts')->assertInertia(fn (Assert $p) => $p->where('runs.0.held.0.street', '7 Birch Ln'));
    expect(fn () => $runs->approve($run, $this->owner))->toThrow(ValidationException::class, 'Payouts are held on 7 Birch Ln')
        ->and(fn () => $runs->approve($run, $this->owner, 'ok'))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post("/finance/payouts/{$run}/approve", ['override' => 'Called Lone Star Title: 2222 is right, 8888 was a fake email'])->assertSessionHasNoErrors();
    expect(DB::table('pay_runs')->where('ulid', $run)->value('status'))->toBe('approved');
});

it('flags a short commission receipt, earnest money that does not match the contract, and earnest money left in trust after closing', function (): void {
    app(Cdas::class)->generate($this->deal, $this->owner);
    $receipts = app(Receipts::class);
    $receipts->record($this->deal, 1_000_000, CarbonImmutable::today(), 'wire', null, $this->owner);
    expect(codes($this->deal))->toBe(['money.receipt' => 'blocker']);

    $deposit = app(EarnestMoney::class)->expect($this->deal, 300_000, 'trust', null, null, $this->owner);
    uploadDoc('contract.pdf', ['RESIDENTIAL PURCHASE AGREEMENT', 'Earnest money: $5,000.00'], [
        ['name' => 'earnest_money', 'value' => '$5,000.00', 'page' => 1, 'quote' => 'Earnest money: $5,000.00'],
    ]);
    expect(DB::table('tx_findings')->where('code', 'money.earnest')->value('message'))->toBe('contract.pdf says earnest money is $5,000; the trust record says $3,000.');

    app(EarnestMoney::class)->receive($deposit, CarbonImmutable::today(), 'wire', 'REF1', $this->owner);
    $this->deal->update(['status' => 'closed']);
    app(ComplianceScan::class)->run($this->deal);
    expect(DB::table('tx_findings')->where('message', 'like', 'The deal has closed but earnest money%')->value('status'))->toBe('open');
});

it('reads bank accounts in wiring instructions', function (): void {
    expect(Sensitive::accounts("Routing number 021000021\nAccount number 1111-2222-3333"))->toBe([['routing' => '021000021', 'last4' => '3333']])
        ->and(Sensitive::accounts('Acct # 98765432 and account no. 12345678'))->toBe([['routing' => null, 'last4' => '5432'], ['routing' => null, 'last4' => '5678']])
        ->and(Sensitive::accounts('Account manager: Jo'))->toBe([]);
});

it('shows the payout hold on the deal page', function (): void {
    app(Cdas::class)->generate($this->deal, $this->owner);
    $this->deal->update(['price_cents' => 49_000_000]);
    app(ComplianceScan::class)->run($this->deal);
    $this->actingAs($this->ann)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $p) => $p->where('compliance.payout_hold', fn ($h): bool => str_contains((string) $h, 'Make a new CDA')));
    assertPageContract($this->actingAs($this->ann)->get("/deals/{$this->deal->ulid}"), 'deals/show');
    assertPageContract($this->actingAs($this->owner)->get('/finance/payouts'), 'finance/payouts');
});
