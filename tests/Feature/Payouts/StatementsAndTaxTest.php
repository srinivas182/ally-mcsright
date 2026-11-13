<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Payouts\AgentBilling;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Payouts\Statements;
use Modules\Payouts\Tax1099;
use Modules\Payouts\W9s;
use Modules\Transactions\Cdas;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\TitleDesk;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->w9s = app(W9s::class);
});

function w9(array $over = []): array
{
    return $over + ['legal_name' => 'Ann Agent', 'business_name' => null, 'tax_class' => 'individual', 'tin_type' => 'ssn', 'tin' => '123-45-6789',
        'address' => '1 Main St, Austin TX 78701', 'signature_name' => 'Ann Agent', 'certify' => true];
}

function closeAndReceive(User $agent): Deal
{
    test()->actingAs($agent)->post('/deals', ['type' => 'listing', 'street' => '7 Lake Dr', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    test()->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01']);

    return $deal->fresh() ?? $deal;
}

function receive(Deal $deal): void
{
    $receipts = app(Receipts::class);
    $receipts->record($deal, $receipts->expected($deal, Calculation::query()->findOrFail($deal->calculation_id)), CarbonImmutable::today(), 'wire', null, test()->owner);
}

it('collects W-9s with an encrypted TIN and a signature that matches the legal name', function (): void {
    expect(fn () => $this->w9s->submit(w9(['tin' => '1234']), $this->ann))->toThrow(ValidationException::class)
        ->and(fn () => $this->w9s->submit(w9(['signature_name' => 'Someone Else']), $this->ann))->toThrow(ValidationException::class);

    $this->w9s->submit(w9(), $this->ann);
    $this->w9s->submit(w9(['tin_type' => 'ein', 'tin' => '12-3456780', 'tax_class' => 'llc_single']), $this->ann);
    expect(DB::table('pay_w9s')->where('status', 'current')->count())->toBe(1)
        ->and(DB::table('pay_w9s')->pluck('tin_sealed')->implode(''))->not->toContain('123456789')
        ->and($this->w9s->current($this->ann->id))->toMatchArray(['tin_type' => 'ein', 'tin_last4' => '6780', 'match_status' => 'pending']);
});

it('writes the IRS bulk TIN matching file and imports the results', function (): void {
    $this->w9s->submit(w9(), $this->ann);
    expect(fn () => $this->w9s->bulkFile($this->ann))->toThrow(ValidationException::class);
    $file = $this->w9s->bulkFile($this->owner);
    $ulid = (string) DB::table('pay_w9s')->value('ulid');
    expect($file)->toBe("2;123456789;ANN AGENT;{$ulid}\r\n");

    $this->actingAs($this->owner)->post('/finance/tax/tin-results', ['file' => UploadedFile::fake()->createWithContent('results.txt', "2;123456789;ANN AGENT;{$ulid};3\r\n")])->assertSessionHasNoErrors();
    expect($this->w9s->current($this->ann->id)['match_status'])->toBe('mismatch');
    $this->w9s->importResults("2;123456789;ANN AGENT;{$ulid};0", $this->owner);
    expect($this->w9s->current($this->ann->id)['match_status'])->toBe('matched');
});

it('totals what was paid for 1099s with the right threshold for each year, and exports full TINs only behind a password', function (): void {
    expect(Tax1099::threshold(2025))->toBe(60_000)->and(Tax1099::threshold(2026))->toBe(200_000);
    app(BankAccounts::class)->set($this->ann, 'Ann Agent', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    receive(closeAndReceive($this->ann));
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $runs->approve($run, $this->second);
    $runs->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', null, null, $this->owner);
    $runs->send($run, $this->owner);
    $this->w9s->submit(w9(), $this->ann);

    $rows = app(Tax1099::class)->forYear(2026);
    expect($rows[0])->toMatchArray(['payee' => 'Ann Agent', 'paid_cents' => 1_200_000, 'reportable' => true])->and($rows[0]['w9']['tin_last4'])->toBe('6789');

    $this->actingAs($this->owner)->get('/finance/tax/export?year=2026')->assertRedirectContains('confirm-password');
    $csv = (string) $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/finance/tax/export?year=2026')->assertOk()->getContent();
    expect($csv)->toContain('"Ann Agent",ssn,123456789,')->toContain('12000.00');
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'payouts.1099.exported')->exists())->toBeTrue();
});

it('explains every cent on the monthly statement, with opening and closing balances and a PDF', function (): void {
    app(BankAccounts::class)->set($this->ann, 'Ann Agent', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    receive(closeAndReceive($this->ann));
    app(AgentBilling::class)->charge($this->ann, 'Sign rider', 5_000, $this->owner);
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $runs->approve($run, $this->second);
    $runs->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', null, null, $this->owner);
    $runs->send($run, $this->owner);

    $s = app(Statements::class)->month($this->ann, '2026-06');
    expect(array_column($s['lines'], 'text'))->toContain('Commission for 7 Lake Dr', 'Invoice INV-000001: Sign rider', 'Invoices paid from your commission', 'Paid to you by direct deposit')
        ->and(collect($s['lines'])->firstWhere('text', 'Paid to you by direct deposit')['cents'])->toBe(-1_195_000)
        ->and($s['opening'])->toBe(['owed_to_you' => 0, 'you_owe' => 0])->and($s['closing'])->toBe(['owed_to_you' => 0, 'you_owe' => 0])
        ->and($s['earned'])->toBe(1_200_000)->and($s['paid'])->toBe(1_195_000);

    $this->actingAs($this->ann)->get('/my-money/statement?month=2026-06')->assertInertia(fn (Assert $page) => $page->component('finance/statement')->where('statement.month', '2026-06'));
    $this->actingAs($this->ann)->get('/my-money/statement?month=2026-06&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('lets title pay agents directly: CDA lines, no payable booked, still counted for 1099', function (): void {
    app(TitleDesk::class)->setAgentsDirect(true, $this->owner);
    $deal = closeAndReceive($this->ann);
    $cdas = app(Cdas::class);
    $cdas->approve($cdas->generate($deal, $this->owner), $this->owner);
    $payees = $cdas->forDeal($deal)[0]['payload']['payees'];
    expect(array_column($payees, 'amount_cents'))->toBe([1_200_000, 300_000]);

    receive($deal);
    $rec = DB::table('pay_recognitions')->first();
    expect(json_decode((string) $rec->payables, true))->toBe([])->and(json_decode((string) $rec->paid_by_title, true))->toBe([(string) $this->ann->id => 1_200_000])
        ->and(app(Tax1099::class)->forYear(2026)[0])->toMatchArray(['paid_cents' => 1_200_000, 'reportable' => true])
        ->and(array_column(app(Statements::class)->month($this->ann, '2026-06')['lines'], 'text'))->toContain('Commission for 7 Lake Dr, paid to you directly by title');
});
