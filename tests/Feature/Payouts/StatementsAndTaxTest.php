<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Connect\AppCatalog;
use Modules\Payouts\AgentBilling;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\EFile1099;
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

it('e-files the year\'s 1099-NECs through the partner once everything is ready (AC2)', function (): void {
    Http::fake([
        'testoauth.expressauth.net/*' => Http::response(['AccessToken' => 'tok-1', 'TokenType' => 'Bearer']),
        'testapi.taxbandits.com/*/Form1099NEC/Create' => Http::response(['SubmissionId' => 'sub-123', 'Form1099Records' => ['SuccessRecords' => [['RecordId' => 'r1']]]]),
        'testapi.taxbandits.com/*/Form1099NEC/Transmit' => Http::response(['SubmissionId' => 'sub-123', 'StatusCode' => 200]),
        'testapi.taxbandits.com/*/Form1099NEC/Status*' => Http::response(['Form1099Records' => [['Status' => 'ACCEPTED']]]),
    ]);
    app(BankAccounts::class)->set($this->ann, 'Ann Agent', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    receive(closeAndReceive($this->ann));
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $runs->approve($run, $this->second);
    $runs->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', null, null, $this->owner);
    $runs->send($run, $this->owner);
    $this->w9s->submit(w9(), $this->ann);

    $efile = app(EFile1099::class);
    expect($efile->problems(2026))->toContain('Add the brokerage\'s payer details: legal name, EIN and address.')
        ->and(collect($efile->problems(2026))->contains(fn ($p) => str_contains($p, 'not switched on')))->toBeTrue();
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('efile_1099'), true,
        ['client_id' => 'cid', 'client_secret' => 'csecret', 'user_token' => 'ut', 'environment' => 'sandbox'], null, $hq);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/tax/payer', ['legal_name' => 'Acme Realty LLC', 'ein' => '12-3456789',
        'street' => '100 Congress Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'])->assertSessionHasNoErrors();
    expect($efile->problems(2026))->toBe([]);
    $this->actingAs($this->owner)->get('/finance/tax?year=2026')->assertInertia(fn (Assert $p) => $p->where('efile.payer.ein_last4', '6789')->where('efile.problems', []));

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/tax/efile', ['year' => 2026, 'mail_copies' => true])->assertSessionHasNoErrors();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'Form1099NEC/Create') && $r->hasHeader('Authorization', 'Bearer tok-1')
        && $r['ReturnData'][0]['Recipient']['TIN'] === '123456789' && $r['ReturnData'][0]['Recipient']['LastNm'] === 'Agent'
        && $r['ReturnData'][0]['Recipient']['USAddress']['ZipCd'] === '78701' && $r['ReturnData'][0]['NECFormData']['B1NEC'] == 12000
        && $r['ReturnHeader']['Business']['EINorSSN'] === '123456789' && $r['SubmissionManifest']['IsPostal'] === true);
    $filing = DB::table('pay_1099_filings')->sole();
    expect($filing->status)->toBe('transmitted')->and($filing->submission_id)->toBe('sub-123')->and((int) $filing->records)->toBe(1);
    expect($efile->problems(2026))->toContain('2026 has already been filed. Corrections are made with the partner.');

    $this->actingAs($this->owner)->post("/finance/tax/efile/{$filing->ulid}/refresh")->assertRedirect();
    expect(DB::table('pay_1099_filings')->value('status'))->toBe('accepted');
    $this->actingAs($this->ann)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/tax/efile', ['year' => 2026])->assertForbidden();
});
